<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matcher – Phase 2 of the pipeline.
 *
 * For every indexed source post compares its content-chunk vectors
 * against the title vectors of all other indexed posts.  When a pair
 * exceeds the configured similarity threshold, the best anchor text
 * is extracted from the source chunk and a row is written to
 * wp_semantic_links.
 *
 * Anchor-selection algorithm
 * ──────────────────────────
 *   1. Extract overlapping n-grams (2–6 words) from the matched chunk.
 *   2. Score each n-gram with an F1 metric against the meaningful
 *      words of the target title (stop-words removed, ≥ 3 chars).
 *   3. Return the highest-scoring n-gram that is an exact substring
 *      of the original chunk, preserving its original casing.
 *
 * This guarantees the anchor is real text from the article (exact
 * match span) while being semantically guided by the title words
 * of the target post.
 */
class SL_Matcher {

	/** Number of source posts to process per batch for matching. */
	private const BATCH_SIZE = 10;

	/** Number of links to filter with Gemini per batch. */
	private const GEMINI_BATCH_SIZE = 20;

	/** Transient key for storing matching progress. */
	private const PROGRESS_KEY = 'sl_matching_progress';

	/** Maximum anchor clusters to store in transient (memory protection). */
	private const MAX_CLUSTERS = 3000;

	/** Maximum candidates to store in transient before processing. */
	private const MAX_CANDIDATES = 5000;

	/**
	 * Similarity threshold for anchor clustering.
	 * Anchors with cosine similarity above this threshold are considered
	 * to be in the same semantic cluster (e.g., "kredyt hipoteczny" and
	 * "kredytu hipotecznego" should belong to the same cluster).
	 * Lower threshold (0.75) better handles Polish morphology variations.
	 */
	private const CLUSTER_THRESHOLD = 0.75;

	/**
	 * Default minimum similarity threshold for custom URLs.
	 * Custom URLs use a lower threshold because the user explicitly
	 * added them, so they should be matched more aggressively.
	 * This default can be overridden via the 'custom_url_threshold' setting.
	 */
	private const CUSTOM_URL_DEFAULT_THRESHOLD = 0.65;

	/**
	 * Common Polish word endings to strip for basic stemming/normalization.
	 * Used as fallback when embedding-based clustering is not available.
	 */
	private const POLISH_SUFFIXES = [
		// Noun cases
		'ów', 'ach', 'ami', 'om', 'owi', 'em', 'ie', 'ę',
		'ego', 'emu', 'ą', 'iej', 'ych', 'ymi', 'im',
		// Adjective endings
		'nego', 'nemu', 'nym', 'nej', 'nych', 'nymi',
		'owego', 'owemu', 'owym', 'owej', 'owych', 'owymi',
		// Common diminutives and other
		'ka', 'ki', 'ek', 'ko', 'ce', 'cie',
	];

	/**
	 * Polish + English stop-words (lowercased).
	 * Stripped from title word sets before anchor scoring to avoid
	 * false matches on function words.
	 */
	private const STOP_WORDS = [
		// Polish
		'i', 'w', 'z', 'na', 'do', 'nie', 'się', 'jest', 'to', 'o',
		'że', 'jak', 'ale', 'ze', 'te', 'co', 'ta', 'ten',
		'czy', 'za', 'od', 'po', 'przed', 'dla', 'bez', 'pod', 'nad',
		'już', 'tak', 'gdy', 'przy', 'an', 'by', 'ni', 'mnie',
		'jego', 'jej', 'ich', 'tego', 'tej', 'tych',
		'który', 'która', 'które', 'jaki', 'jaka', 'jakie',
		// English (titles may mix languages)
		'the', 'and', 'for', 'with', 'that', 'this', 'from',
		'are', 'was', 'has', 'have', 'will', 'your', 'can', 'about',
	];

	/**
	 * Get the custom URL similarity threshold from settings.
	 *
	 * @return float  Threshold value between 0.20 and 0.90.
	 */
	private static function get_custom_url_threshold(): float {
		return (float) SL_Settings::get( 'custom_url_threshold', self::CUSTOM_URL_DEFAULT_THRESHOLD );
	}

	/**
	 * Polish conjunctions, prepositions, and short verbs that should not appear
	 * at the end of an anchor (makes the anchor feel incomplete/cut off).
	 */
	private const TRAILING_FORBIDDEN = [
		// Conjunctions
		'oraz', 'i', 'lub', 'albo', 'czy', 'ani', 'bądź', 'a',
		'ale', 'lecz', 'jednak', 'zaś', 'natomiast', 'więc', 'zatem',
		'że', 'żeby', 'aby', 'bo', 'ponieważ', 'gdyż', 'jeśli', 'jeżeli',
		'kiedy', 'gdy', 'chociaż', 'choć', 'mimo',
		// Prepositions
		'w', 'z', 'na', 'do', 'od', 'po', 'za', 'o', 'przez',
		'dla', 'bez', 'pod', 'nad', 'przed', 'między', 'przy', 'u',
		// Pronouns often indicating incomplete phrase
		'który', 'która', 'które', 'którzy', 'których',
		'jaki', 'jaka', 'jakie', 'jacyś', 'jakich',
		'ten', 'ta', 'to', 'te', 'ci', 'ich', 'go', 'je', 'ją',
		// Short verbs (make anchor feel incomplete)
		'ma', 'mają', 'mogą', 'może', 'mogli', 'mogły',
		'jest', 'są', 'był', 'była', 'było', 'byli', 'były', 'będzie', 'będą',
		'się', 'nie', 'już', 'tak', 'jak', 'co', 'czy',
		// Other
		'też', 'także', 'również', 'nawet', 'tylko', 'właśnie',
	];

	/* ── Entry point ──────────────────────────────────────────── */

	public function match_all(): void {
		$max_links = SL_Settings::get( 'max_links_per_post', 3 );
		$threshold = (float) SL_Settings::get( 'similarity_threshold', 0.85 );

		SL_Debug::log( 'matcher', 'Matcher started', [
			'max_links' => $max_links,
			'threshold' => $threshold,
		] );

		/* Preload URL links cache (one query instead of thousands) */
		SL_DB::preload_url_links_cache();

		/* Target set = title embeddings of every indexed post */
		$title_rows = SL_DB::get_title_embeddings();

		SL_Debug::log( 'matcher', 'Title embeddings loaded', [ 'count' => count( $title_rows ) ] );

		if ( count( $title_rows ) < 2 ) {
			SL_Debug::log( 'matcher', 'ERROR: Less than 2 title embeddings - cannot cross-reference' );
			return;   // nothing to cross-reference
		}

		/* Index by post_id for O(1) lookup */
		$target_map = [];     // post_id => [ 'vec' => float[], 'title' => string, 'target_type' => 'post'|'custom' ]
		foreach ( $title_rows as $row ) {
			$target_map[$row->post_id] = [
				'vec'         => $row->embedding,
				'title'       => $row->chunk_text,
				'target_type' => 'post',
			];
		}

		/* Add custom URLs to target map */
		$custom_urls = SL_DB::get_custom_url_embeddings();
		foreach ( $custom_urls as $custom ) {
			$custom_key = 'custom_' . $custom->ID;
			$target_map[ $custom_key ] = [
				'vec'         => $custom->embedding,
				'title'       => $custom->title,
				'target_type' => 'custom',
				'url'         => $custom->url,
			];
		}

		SL_Debug::log( 'matcher', 'Custom URLs loaded into target map', [
			'count'  => count( $custom_urls ),
			'titles' => array_map( fn( $u ) => $u->title, $custom_urls ),
		] );

		$total_candidates_found = 0;
		$total_links_created = 0;
		$total_links_filtered = 0;
		$posts_processed = 0;
		$posts_skipped_max_links = 0;
		$posts_skipped_no_chunks = 0;

		/* Get excluded post IDs */
		$excluded_ids = SL_Settings::get( 'excluded_post_ids', [] );

		/* Build anchor clusters for embedding-based deduplication */
		$anchor_clusters = [];  // [ ['anchor' => '...', 'embedding' => [...], 'target_url' => '...'], ... ]
		$db_anchors = SL_DB::get_all_active_anchors();

		if ( ! empty( $db_anchors ) ) {
			$anchor_texts = [];
			$anchor_url_map = [];
			foreach ( $db_anchors as $link ) {
				if ( ! isset( $anchor_url_map[ $link->anchor_text ] ) ) {
					$anchor_texts[] = $link->anchor_text;
					$anchor_url_map[ $link->anchor_text ] = $link->target_url;
				}
			}

			$api = new SL_Embedding_API();
			$embeddings = $api->embed( $anchor_texts );

			if ( $embeddings && count( $embeddings ) === count( $anchor_texts ) ) {
				// Build anchor → embedding map
				$anchor_embedding_map = [];
				foreach ( $anchor_texts as $idx => $anchor ) {
					$anchor_embedding_map[ $anchor ] = $embeddings[ $idx ];
				}

				// Build clusters with semantic deduplication (first anchor wins)
				foreach ( $anchor_texts as $anchor ) {
					$embedding  = $anchor_embedding_map[ $anchor ];
					$target_url = $anchor_url_map[ $anchor ];

					// Check if anchor belongs to an existing cluster
					$existing_cluster = self::find_anchor_cluster( $embedding, $anchor_clusters );

					if ( $existing_cluster ) {
						// Semantic duplicate - skip (first one wins)
						continue;
					}

					$anchor_clusters[] = [
						'anchor'     => $anchor,
						'embedding'  => $embedding,
						'target_url' => $target_url,
					];
				}
			}
		}

		/* Iterate every source post */
		foreach ( $title_rows as $source_row ) {
			$src_id = (int) $source_row->post_id;
			$posts_processed++;

			/* Skip excluded source posts */
			if ( in_array( $src_id, $excluded_ids, true ) ) {
				continue;
			}

			$current_links = SL_DB::get_active_link_count( $src_id );
			if ( $current_links >= $max_links ) {
				$posts_skipped_max_links++;
				continue;
			}

			/* Content chunks (skip index 0 = title) */
			$all_chunks      = SL_DB::get_embeddings( $src_id );
			$content_chunks  = [];
			foreach ( $all_chunks as $c ) {
				if ( (int) $c->chunk_index > 0 ) {
					$content_chunks[] = $c;
				}
			}

			if ( empty( $content_chunks ) ) {
				$posts_skipped_no_chunks++;
				SL_Debug::log( 'matcher', 'Post has no content chunks', [
					'post_id' => $src_id,
					'title'   => $source_row->chunk_text,
				] );
				continue;
			}

			/* ── Score every (chunk × target) pair ──────────────── */
			$candidates = [];
			$best_score_for_post = 0;
			$scores_above_half = 0;

			foreach ( $content_chunks as $chunk ) {
				foreach ( $target_map as $tid => $target ) {
					if ( $tid === $src_id ) {
						continue;    // never link a post to itself
					}
					/* Skip excluded target posts */
					if ( in_array( $tid, $excluded_ids, true ) ) {
						continue;
					}
					$score = self::cosine( $chunk->embedding, $target['vec'] );

					if ( $score > $best_score_for_post ) {
						$best_score_for_post = $score;
					}
					if ( $score >= 0.5 ) {
						$scores_above_half++;
					}

					// Custom URLs use a much lower threshold (user explicitly added them)
					$is_custom = ( $target['target_type'] ?? 'post' ) === 'custom';
					$effective_threshold = $is_custom ? self::get_custom_url_threshold() : $threshold;

					if ( $score >= $effective_threshold ) {
						$candidates[] = [
							'chunk'        => $chunk->chunk_text,
							'target_id'    => $tid,
							'target_title' => $target['title'],
							'target_type'  => $target['target_type'] ?? 'post',
							'target_url'   => $target['url'] ?? null,  // Pre-filled for custom URLs
							'score'        => $score,
						];
						$total_candidates_found++;
					}
				}
			}

			// Log best scores for debugging
			if ( $posts_processed <= 5 || $best_score_for_post >= $threshold ) {
				SL_Debug::log( 'matcher', 'Post similarity scores', [
					'post_id'           => $src_id,
					'title'             => $source_row->chunk_text,
					'chunks_count'      => count( $content_chunks ),
					'best_score'        => round( $best_score_for_post, 4 ),
					'scores_above_0.5'  => $scores_above_half,
					'candidates_found'  => count( $candidates ),
				] );
			}

			if ( empty( $candidates ) ) {
				continue;
			}

			/* Sort: custom URLs first (priority), then by score descending */
			usort( $candidates, function ( $a, $b ) {
				$a_custom = ( $a['target_type'] ?? 'post' ) === 'custom' ? 1 : 0;
				$b_custom = ( $b['target_type'] ?? 'post' ) === 'custom' ? 1 : 0;
				if ( $a_custom !== $b_custom ) {
					return $b_custom <=> $a_custom;  // Custom first
				}
				return $b['score'] <=> $a['score'];
			} );

			/* ── Write links (respecting limits & dedup) ──────────── */
			$remaining    = $max_links - SL_DB::get_active_link_count( $src_id );
			$used_targets = [];
			$used_anchors = [];  // Track anchors used in this source post

			foreach ( $candidates as $c ) {
				if ( $remaining <= 0 ) {
					break;
				}

				/* Per-post dedup: one link per unique target */
				if ( in_array( $c['target_id'], $used_targets, true ) ) {
					continue;
				}

				$is_custom = ( $c['target_type'] ?? 'post' ) === 'custom';

				// Get permalink: use pre-filled URL for custom, get_permalink for posts
				if ( $is_custom ) {
					$permalink = $c['target_url'];
				} else {
					$permalink = get_permalink( $c['target_id'] );
				}
				if ( ! $permalink ) {
					continue;
				}

				/* Skip if an active link to this URL already exists */
				if ( SL_DB::link_exists_for_post( $src_id, $permalink ) ) {
					continue;
				}

				/* Skip blacklisted combinations */
				if ( SL_DB::is_blacklisted( $src_id, $permalink ) ) {
					continue;
				}

				/* Skip if this target URL already has max links (cluster limit)
				 * Cache is preloaded at start and updated after each insert */
				$max_links_per_url = (int) SL_Settings::get( 'max_links_per_url', 10 );
				if ( SL_DB::get_active_links_to_url( $permalink ) >= $max_links_per_url ) {
					continue;
				}

				/* Skip if same-category-only is enabled and posts don't share a category
				 * Custom URLs are exempt (they don't have categories) */
				if ( ! $is_custom && SL_Settings::get( 'same_category_only', true ) ) {
					if ( ! self::posts_share_category( $src_id, $c['target_id'] ) ) {
						continue;
					}
				}

				/* ── Anchor extraction ───────────────────────────── */
				$anchor = self::find_anchor( $c['chunk'], $c['target_title'] );
				if ( ! $anchor || mb_strlen( $anchor, 'UTF-8' ) < 3 ) {
					SL_Debug::log( 'matcher', 'Anchor extraction failed', [
						'source_id'    => $src_id,
						'target_id'    => $c['target_id'],
						'target_title' => $c['target_title'],
						'chunk_preview'=> mb_substr( $c['chunk'], 0, 100 ),
						'score'        => round( $c['score'], 4 ),
					] );
					continue;   // can't find a meaningful anchor → skip
				}

				/* Skip if this anchor is already used for a different target in this source post */
				$anchor_lower = mb_strtolower( $anchor, 'UTF-8' );
				if ( isset( $used_anchors[ $anchor_lower ] ) && $used_anchors[ $anchor_lower ] !== $permalink ) {
					SL_Debug::log( 'matcher', 'Anchor already used for different URL in this source - skipping', [
						'source_id'    => $src_id,
						'anchor'       => $anchor,
						'existing_url' => $used_anchors[ $anchor_lower ],
						'new_url'      => $permalink,
					] );
					continue;
				}

				/* Skip if anchor only appears inside excluded tags (h1, h2, code, etc.)
				 * - these links would never be injected anyway */
				if ( ! self::anchor_can_be_injected( $src_id, $anchor ) ) {
					SL_Debug::log( 'matcher', 'Anchor only in excluded tags - skipping', [
						'source_id' => $src_id,
						'anchor'    => $anchor,
					] );
					continue;
				}

				/* Embedding-based cluster deduplication */
				$api = new SL_Embedding_API();
				$anchor_embedding = $api->embed_single( $anchor );

				if ( ! $anchor_embedding ) {
					/* Fallback to text-based dedup if embedding fails */
					SL_Debug::log( 'matcher', 'WARNING: Failed to embed anchor, using text fallback', [
						'anchor' => $anchor,
					] );
					if ( SL_DB::anchor_used_globally_for_different_url( $anchor, $permalink ) ) {
						continue;
					}
				} else {
					/* Check if anchor belongs to any existing cluster */
					$existing_cluster = self::find_anchor_cluster( $anchor_embedding, $anchor_clusters );

					if ( $existing_cluster ) {
						if ( $existing_cluster['target_url'] !== $permalink ) {
							/* Conflict: same semantic cluster but different URL - skip this link */
							SL_Debug::log( 'matcher', 'Cluster dedup: anchor belongs to cluster for different URL', [
								'source_id'          => $src_id,
								'new_anchor'         => $anchor,
								'cluster_anchor'     => $existing_cluster['anchor'],
								'cluster_url'        => $existing_cluster['target_url'],
								'new_url'            => $permalink,
								'similarity'         => round( $existing_cluster['similarity'], 4 ),
							] );
							continue;
						}
						/* Same URL - anchor is already represented by this cluster, don't add duplicate */
						SL_Debug::log( 'matcher', 'Cluster dedup: anchor already in cluster for same URL (no duplicate)', [
							'source_id'      => $src_id,
							'new_anchor'     => $anchor,
							'cluster_anchor' => $existing_cluster['anchor'],
							'target_url'     => $permalink,
							'similarity'     => round( $existing_cluster['similarity'], 4 ),
						] );
					} else {
						/* No existing cluster - register as new cluster entry */
						$anchor_clusters[] = [
							'anchor'     => $anchor,
							'embedding'  => $anchor_embedding,
							'target_url' => $permalink,
						];
					}
				}

				/* Determine link status (active or filtered by Gemini) */
				$link_status = 'active';

				/* Optional Gemini AI filter: verify anchor-title contextual match
				 * Note: Custom URLs bypass Gemini filter (they're manually curated) */
				$is_custom_target = ( $c['target_type'] ?? 'post' ) === 'custom';
				if ( ! $is_custom_target && SL_Settings::get( 'gemini_anchor_filter', false ) ) {
					$api = new SL_Embedding_API();
					if ( ! $api->evaluate_anchor_match( $anchor, $c['target_title'] ) ) {
						SL_Debug::log( 'matcher', 'Gemini filter rejected anchor-title pair', [
							'source_id'    => $src_id,
							'anchor'       => $anchor,
							'target_title' => $c['target_title'],
						] );
						$link_status = 'filtered';
					}
				}

				$inserted = SL_DB::insert_link( [
					'post_id'          => $src_id,
					'anchor_text'      => $anchor,
					'target_url'       => $permalink,
					'target_post_id'   => $is_custom_target ? 0 : $c['target_id'],  // 0 for custom URLs
					'similarity_score' => round( $c['score'], 4 ),
					'status'           => $link_status,
				] );

				if ( $inserted ) {
					$used_targets[] = $c['target_id'];
					$used_anchors[ $anchor_lower ] = $permalink;  // Track anchor → URL mapping
					$remaining--;

					if ( $link_status === 'active' ) {
						$total_links_created++;
						// Update URL links cache (for cluster limit enforcement)
						SL_DB::increment_url_links_cache( $permalink );
						// Flush the frontend injection cache for this source post
						delete_transient( 'sl_inj_' . $src_id );
					} else {
						$total_links_filtered++;
					}

					SL_Debug::log( 'matcher', 'Link created', [
						'source_id'    => $src_id,
						'target_id'    => $c['target_id'],
						'anchor'       => $anchor,
						'score'        => round( $c['score'], 4 ),
						'status'       => $link_status,
					] );
				}
			}
		}

		SL_Debug::log( 'matcher', '=== MATCHER COMPLETED ===', [
			'posts_processed'         => $posts_processed,
			'posts_skipped_max_links' => $posts_skipped_max_links,
			'posts_skipped_no_chunks' => $posts_skipped_no_chunks,
			'total_candidates'        => $total_candidates_found,
			'total_links_created'     => $total_links_created,
			'total_links_filtered'    => $total_links_filtered,
		] );
	}

	/* ── Batch processing for AJAX ─────────────────────────────── */

	/**
	 * Initialize batch matching - prepares data structures.
	 *
	 * @return array  Progress info.
	 */
	public static function init_matching(): array {
		SL_Debug::log( 'matcher', '=== BATCH MATCHING INITIALIZED ===' );

		// Use lightweight query - only post IDs, no embedding vectors
		// Full embeddings (800 posts × 3072 dims) would use ~490MB PHP memory
		$source_ids = SL_DB::get_indexed_post_ids();

		if ( count( $source_ids ) < 2 ) {
			return [ 'error' => 'Za mało postów do porównania (min. 2).' ];
		}

		// Load existing anchors from DB - store only anchor + URL (no embeddings to save transient space)
		// Embeddings will be computed on-the-fly during batch processing
		$db_anchors = SL_DB::get_all_active_anchors();
		$anchor_clusters = [];  // [ ['anchor' => '...', 'target_url' => '...'], ... ] - NO embeddings!

		if ( ! empty( $db_anchors ) ) {
			// Collect unique anchor texts (first occurrence wins, limit to prevent memory issues)
			$seen_anchors = [];
			foreach ( $db_anchors as $link ) {
				if ( count( $anchor_clusters ) >= self::MAX_CLUSTERS ) {
					break;  // Limit reached
				}
				if ( ! isset( $seen_anchors[ $link->anchor_text ] ) ) {
					$seen_anchors[ $link->anchor_text ] = true;
					$anchor_clusters[] = [
						'anchor'     => $link->anchor_text,
						'target_url' => $link->target_url,
						// NO 'embedding' key - will be computed on-the-fly
					];
				}
			}

			SL_Debug::log( 'matcher', 'Loaded existing anchors for clustering (no embeddings in transient)', [
				'total_anchors'    => count( $db_anchors ),
				'unique_clusters'  => count( $anchor_clusters ),
				'max_clusters'     => self::MAX_CLUSTERS,
				'limited'          => count( $anchor_clusters ) >= self::MAX_CLUSTERS ? 'yes' : 'no',
			] );
		}

		$progress = [
			'phase'              => 'matching',
			'source_ids'         => $source_ids,
			'total_sources'      => count( $source_ids ),
			'offset'             => 0,
			'candidates'         => [],  // Links pending Gemini filter
			'anchor_clusters'    => $anchor_clusters,  // Embedding-based anchor clusters
			'links_created'      => 0,
			'links_filtered'     => 0,
			'gemini_offset'      => 0,
			'gemini_enabled'     => (bool) SL_Settings::get( 'gemini_anchor_filter', false ),
		];

		// Log transient data size for debugging (no embeddings stored now)
		$transient_size = strlen( maybe_serialize( $progress ) );
		SL_Debug::log( 'matcher', '=== INIT COMPLETE - Saving transient (no embeddings) ===', [
			'total_clusters'    => count( $anchor_clusters ),
			'transient_size_kb' => round( $transient_size / 1024, 2 ),
			'note'              => 'Embeddings computed on-the-fly per batch',
		] );

		set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

		return [
			'total_sources' => count( $source_ids ),
			'processed'     => 0,
			'phase'         => 'matching',
			'message'       => sprintf( 'Znaleziono %d postów do analizy podobieństwa. Załadowano %d klastrów anchorów.', count( $source_ids ), count( $anchor_clusters ) ),
		];
	}

	/**
	 * Process one batch of source posts for matching.
	 *
	 * @return array  Progress info.
	 */
	public static function process_matching_batch(): array {
		// Loading 800 title embeddings (3072 dims each) requires ~200MB+ PHP memory.
		// Increase limit for this request if the current limit is too low.
		$current = ini_get( 'memory_limit' );
		$current_bytes = wp_convert_hr_to_bytes( $current );
		if ( $current_bytes < 512 * 1024 * 1024 ) {
			ini_set( 'memory_limit', '512M' );
		}

		// Matching computation is CPU-intensive. Extend execution time to avoid
		// PHP fatal "Maximum execution time exceeded" on servers with 30s default.
		@ini_set( 'max_execution_time', '120' );

		$progress = get_transient( self::PROGRESS_KEY );

		if ( ! $progress ) {
			return [ 'error' => 'Nie znaleziono sesji matchingu.' ];
		}

		// Verify transient was loaded correctly
		$loaded_clusters = count( $progress['anchor_clusters'] ?? [] );
		SL_Debug::log( 'matcher', 'Transient loaded', [
			'phase'            => $progress['phase'] ?? 'unknown',
			'offset'           => $progress['offset'] ?? 0,
			'clusters_loaded'  => $loaded_clusters,
			'note'             => 'Embeddings will be computed on-the-fly',
		] );

		// Preload URL links cache (one query instead of thousands per batch)
		SL_DB::preload_url_links_cache();

		// Phase: Gemini filtering
		if ( $progress['phase'] === 'filtering' ) {
			return self::process_gemini_batch( $progress );
		}

		// Phase: Complete
		if ( $progress['phase'] === 'complete' ) {
			delete_transient( self::PROGRESS_KEY );
			return [
				'complete' => true,
				'message'  => sprintf(
					'Matching zakończony! Utworzono %d linków, wyfiltrowano %d.',
					$progress['links_created'],
					$progress['links_filtered']
				),
			];
		}

		$max_links  = SL_Settings::get( 'max_links_per_post', 3 );
		$threshold  = (float) SL_Settings::get( 'similarity_threshold', 0.85 );
		$excluded_ids = SL_Settings::get( 'excluded_post_ids', [] );

		// Load target map (posts)
		$title_rows = SL_DB::get_title_embeddings();
		$target_map = [];
		foreach ( $title_rows as $row ) {
			$target_map[ $row->post_id ] = [
				'vec'         => $row->embedding,
				'title'       => $row->chunk_text,
				'target_type' => 'post',
			];
		}

		// Load custom URLs into target map
		$custom_urls = SL_DB::get_custom_url_embeddings();
		foreach ( $custom_urls as $custom ) {
			$custom_key = 'custom_' . $custom->ID;
			$has_valid_embedding = is_array( $custom->embedding ) && count( $custom->embedding ) > 0;
			$target_map[ $custom_key ] = [
				'vec'         => $custom->embedding,
				'title'       => $custom->title,
				'target_type' => 'custom',
				'url'         => $custom->url,
			];
			// Debug each custom URL
			SL_Debug::log( 'matcher', 'Custom URL added to target map', [
				'id'              => $custom->ID,
				'title'           => $custom->title,
				'url'             => $custom->url,
				'has_embedding'   => $has_valid_embedding ? 'yes' : 'NO!',
				'embedding_dim'   => $has_valid_embedding ? count( $custom->embedding ) : 0,
			] );
		}

		// Debug: Log custom URLs summary
		SL_Debug::log( 'matcher', 'Batch: Custom URLs loaded summary', [
			'count'  => count( $custom_urls ),
			'titles' => array_map( fn( $u ) => $u->title, $custom_urls ),
		] );

		// Get batch of source IDs
		$batch_ids = array_slice(
			$progress['source_ids'],
			$progress['offset'],
			self::BATCH_SIZE
		);

		if ( empty( $batch_ids ) ) {
			// No more sources - move to Gemini filtering or complete
			SL_Debug::log( 'matcher', '=== MATCHING PHASE COMPLETE ===', [
				'total_candidates'  => count( $progress['candidates'] ),
				'gemini_enabled'    => $progress['gemini_enabled'] ? 'yes' : 'no',
				'clusters_count'    => count( $progress['anchor_clusters'] ?? [] ),
			] );

			if ( $progress['gemini_enabled'] && ! empty( $progress['candidates'] ) ) {
				$progress['phase'] = 'filtering';
				set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

				return [
					'total_sources'   => $progress['total_sources'],
					'processed'       => $progress['total_sources'],
					'phase'           => 'filtering',
					'candidates_count'=> count( $progress['candidates'] ),
					'message'         => sprintf(
						'Matching zakończony. Filtrowanie %d kandydatów przez Gemini AI...',
						count( $progress['candidates'] )
					),
				];
			} else {
				// No Gemini filter - save all candidates directly (with deduplication)
				SL_Debug::log( 'matcher', '=== SAVING LINKS DIRECTLY (no Gemini) ===', [
					'candidates_to_save' => count( $progress['candidates'] ),
				] );
				$existing_anchors = SL_DB::get_all_active_anchors();
				$anchor_url_map = [];
				foreach ( $existing_anchors as $link ) {
					$normalized = mb_strtolower( trim( $link->anchor_text ), 'UTF-8' );
					if ( ! isset( $anchor_url_map[ $normalized ] ) ) {
						$anchor_url_map[ $normalized ] = $link->target_url;
					}
				}

				$max_links_per_url = (int) SL_Settings::get( 'max_links_per_url', 10 );

				foreach ( $progress['candidates'] as $candidate ) {
					$anchor_normalized = mb_strtolower( trim( $candidate['anchor'] ), 'UTF-8' );
					$target_url = $candidate['target_url'];

					// Skip if this URL already reached max links limit (cache handles tracking)
					if ( SL_DB::get_active_links_to_url( $target_url ) >= $max_links_per_url ) {
						SL_Debug::log( 'matcher', 'Direct save: URL reached max links limit - skipping', [
							'source_id'  => $candidate['source_id'],
							'target_url' => $target_url,
							'limit'      => $max_links_per_url,
						] );
						continue;
					}

					// Skip if anchor already used for different URL
					if ( isset( $anchor_url_map[ $anchor_normalized ] ) &&
					     $anchor_url_map[ $anchor_normalized ] !== $target_url ) {
						SL_Debug::log( 'matcher', 'Direct save: anchor conflict - skipping', [
							'source_id'    => $candidate['source_id'],
							'anchor'       => $candidate['anchor'],
							'existing_url' => $anchor_url_map[ $anchor_normalized ],
							'new_url'      => $target_url,
						] );
						continue;
					}

					$inserted = SL_DB::insert_link( [
						'post_id'          => $candidate['source_id'],
						'anchor_text'      => $candidate['anchor'],
						'target_url'       => $target_url,
						'target_post_id'   => $candidate['target_id'],
						'similarity_score' => round( $candidate['score'], 4 ),
						'status'           => 'active',
					] );
					if ( $inserted ) {
						$anchor_url_map[ $anchor_normalized ] = $target_url;
						$progress['links_created']++;
						// Update URL links cache
						SL_DB::increment_url_links_cache( $target_url );
						delete_transient( 'sl_inj_' . $candidate['source_id'] );
						SL_Debug::log( 'matcher', 'Link saved (direct)', [
							'source_id'  => $candidate['source_id'],
							'anchor'     => $candidate['anchor'],
							'target_url' => $target_url,
						] );
					} else {
						SL_Debug::log( 'matcher', 'Failed to save link (direct)', [
							'source_id'  => $candidate['source_id'],
							'anchor'     => $candidate['anchor'],
							'target_url' => $target_url,
						] );
					}
				}

				// Diagnostic: count links in DB
				global $wpdb;
				$db_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links" );
				SL_Debug::log( 'matcher', '=== DIRECT SAVE COMPLETE ===', [
					'links_created'  => $progress['links_created'],
					'db_total_links' => $db_count,
				] );

				$progress['phase'] = 'complete';
				set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

				return [
					'complete' => true,
					'message'  => sprintf( 'Matching zakończony! Utworzono %d linków.', $progress['links_created'] ),
				];
			}
		}

		// Embed cluster anchors on-the-fly for this batch (not stored in transient)
		$cluster_count = count( $progress['anchor_clusters'] ?? [] );
		$clusters_with_embeddings = [];  // Local copy with embeddings for this batch

		if ( $cluster_count > 0 ) {
			// Extract anchor texts for batch embedding
			$cluster_anchors = [];
			foreach ( $progress['anchor_clusters'] as $c ) {
				$cluster_anchors[] = $c['anchor'];
			}

			// Batch embed all cluster anchors
			$api = new SL_Embedding_API();
			$cluster_embeddings = $api->embed( $cluster_anchors );

			if ( $cluster_embeddings && count( $cluster_embeddings ) === count( $cluster_anchors ) ) {
				// Build clusters with embeddings (in memory only, not saved to transient)
				foreach ( $progress['anchor_clusters'] as $idx => $c ) {
					$clusters_with_embeddings[] = [
						'anchor'     => $c['anchor'],
						'target_url' => $c['target_url'],
						'embedding'  => $cluster_embeddings[ $idx ],
					];
				}
				SL_Debug::log( 'matcher', '=== BATCH START - Clusters embedded ===', [
					'batch_offset'        => $progress['offset'],
					'batch_size'          => count( $batch_ids ),
					'cluster_count'       => $cluster_count,
					'embeddings_ok'       => count( $clusters_with_embeddings ),
				] );
			} else {
				SL_Debug::log( 'matcher', '=== BATCH START - Failed to embed clusters ===', [
					'batch_offset'  => $progress['offset'],
					'cluster_count' => $cluster_count,
					'error'         => 'Embedding failed - clustering disabled for this batch',
				] );
			}
		} else {
			SL_Debug::log( 'matcher', '=== BATCH START - No clusters ===', [
				'batch_offset' => $progress['offset'],
				'batch_size'   => count( $batch_ids ),
			] );
		}

		// Process this batch
		$new_candidates = [];

		foreach ( $batch_ids as $src_id ) {
			// Skip excluded source posts
			if ( in_array( $src_id, $excluded_ids, true ) ) {
				continue;
			}

			$current_links = SL_DB::get_active_link_count( $src_id );
			if ( $current_links >= $max_links ) {
				continue;
			}

			// Content chunks (skip index 0 = title)
			$all_chunks     = SL_DB::get_embeddings( $src_id );
			$content_chunks = [];
			foreach ( $all_chunks as $c ) {
				if ( (int) $c->chunk_index > 0 ) {
					$content_chunks[] = $c;
				}
			}

			if ( empty( $content_chunks ) ) {
				continue;
			}

			// Score every (chunk × target) pair
			$candidates = [];
			$custom_max_scores = [];  // Track max score for each custom URL

			foreach ( $content_chunks as $chunk ) {
				foreach ( $target_map as $tid => $target ) {
					if ( $tid === $src_id ) {
						continue;
					}
					if ( in_array( $tid, $excluded_ids, true ) ) {
						continue;
					}

					$score = self::cosine( $chunk->embedding, $target['vec'] );

					// Track max score for custom URLs
					$is_custom = ( $target['target_type'] ?? 'post' ) === 'custom';
					if ( $is_custom ) {
						$title = $target['title'];
						if ( ! isset( $custom_max_scores[ $title ] ) || $score > $custom_max_scores[ $title ] ) {
							$custom_max_scores[ $title ] = $score;
						}
					}

					// Custom URLs use a much lower threshold (user explicitly added them)
					$effective_threshold = $is_custom ? self::get_custom_url_threshold() : $threshold;

					if ( $score >= $effective_threshold ) {
						$candidates[] = [
							'chunk'        => $chunk->chunk_text,
							'target_id'    => $tid,
							'target_title' => $target['title'],
							'target_type'  => $target['target_type'] ?? 'post',
							'target_url'   => $target['url'] ?? null,  // Pre-filled for custom URLs
							'score'        => $score,
						];
					}
				}
			}

			// Log max scores for all custom URLs (even those below threshold)
			if ( ! empty( $custom_max_scores ) ) {
				SL_Debug::log( 'matcher', 'Custom URLs max scores for source', [
					'source_id'         => $src_id,
					'post_threshold'    => $threshold,
					'custom_threshold'  => self::get_custom_url_threshold(),
					'max_scores'        => array_map( fn( $s ) => round( $s, 4 ), $custom_max_scores ),
				] );
			}

			if ( empty( $candidates ) ) {
				continue;
			}

			// Sort: custom URLs first (priority), then by score descending
			usort( $candidates, function ( $a, $b ) {
				$a_custom = ( $a['target_type'] ?? 'post' ) === 'custom' ? 1 : 0;
				$b_custom = ( $b['target_type'] ?? 'post' ) === 'custom' ? 1 : 0;
				if ( $a_custom !== $b_custom ) {
					return $b_custom <=> $a_custom;  // Custom first
				}
				return $b['score'] <=> $a['score'];
			} );

			// Log custom URL candidates for debugging
			$custom_candidates = array_filter( $candidates, fn( $c ) => ( $c['target_type'] ?? 'post' ) === 'custom' );
			if ( ! empty( $custom_candidates ) ) {
				SL_Debug::log( 'matcher', 'Custom URL candidates found', [
					'source_id'        => $src_id,
					'custom_count'     => count( $custom_candidates ),
					'total_candidates' => count( $candidates ),
				] );
			}

			// ═══════════════════════════════════════════════════════════════════
			// PHASE 1: Collect pre-candidates (before embedding)
			// ═══════════════════════════════════════════════════════════════════
			$remaining    = $max_links - SL_DB::get_active_link_count( $src_id );
			$used_targets = [];
			$used_anchors = [];
			$pre_candidates = [];  // Candidates that pass basic checks, pending embedding

			foreach ( $candidates as $c ) {
				$is_custom = ( $c['target_type'] ?? 'post' ) === 'custom';

				if ( $remaining <= 0 ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: no remaining slots', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'remaining'    => $remaining,
						] );
					}
					break;
				}

				if ( in_array( $c['target_id'], $used_targets, true ) ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: target already used', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
						] );
					}
					continue;
				}

				// Get permalink: use pre-filled URL for custom, get_permalink for posts
				if ( $is_custom ) {
					$permalink = $c['target_url'];
				} else {
					$permalink = get_permalink( $c['target_id'] );
				}
				if ( ! $permalink ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: no permalink', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
						] );
					}
					continue;
				}

				if ( SL_DB::link_exists_for_post( $src_id, $permalink ) ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: link already exists', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'target_url'   => $permalink,
						] );
					}
					continue;
				}

				if ( SL_DB::is_blacklisted( $src_id, $permalink ) ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: blacklisted', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
						] );
					}
					continue;
				}

				// Skip if this target URL already has max links (cache handles tracking)
				$max_links_per_url = (int) SL_Settings::get( 'max_links_per_url', 10 );
				if ( SL_DB::get_active_links_to_url( $permalink ) >= $max_links_per_url ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: URL reached max links limit', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'target_url'   => $permalink,
							'limit'        => $max_links_per_url,
						] );
					}
					continue;
				}

				// Skip same_category_only check for custom URLs (they don't have categories)
				if ( ! $is_custom && SL_Settings::get( 'same_category_only', true ) ) {
					if ( ! self::posts_share_category( $src_id, $c['target_id'] ) ) {
						continue;
					}
				}

				// Anchor extraction
				$anchor = self::find_anchor( $c['chunk'], $c['target_title'] );
				if ( ! $anchor || mb_strlen( $anchor, 'UTF-8' ) < 3 ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: no anchor found', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'chunk'        => mb_substr( $c['chunk'], 0, 100, 'UTF-8' ) . '...',
						] );
					}
					continue;
				}

				// Skip if this anchor is already used for a different target in this source post
				$anchor_lower = mb_strtolower( $anchor, 'UTF-8' );
				if ( isset( $used_anchors[ $anchor_lower ] ) && $used_anchors[ $anchor_lower ] !== $permalink ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: anchor already used for different URL', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'anchor'       => $anchor,
							'existing_url' => $used_anchors[ $anchor_lower ],
						] );
					}
					continue;
				}

				// Check if anchor can be injected (not only in excluded tags)
				if ( ! self::anchor_can_be_injected( $src_id, $anchor ) ) {
					if ( $is_custom ) {
						SL_Debug::log( 'matcher', 'CUSTOM SKIP: anchor cannot be injected', [
							'source_id'    => $src_id,
							'target_title' => $c['target_title'],
							'anchor'       => $anchor,
						] );
					}
					continue;
				}

				// Debug: custom URL passed all filters
				if ( $is_custom ) {
					SL_Debug::log( 'matcher', 'CUSTOM PASSED: adding to pre-candidates', [
						'source_id'    => $src_id,
						'target_title' => $c['target_title'],
						'anchor'       => $anchor,
						'score'        => round( $c['score'], 4 ),
					] );
				}

				// Add to pre-candidates for batch embedding
				$pre_candidates[] = [
					'source_id'    => $src_id,
					'target_id'    => $is_custom ? 0 : $c['target_id'],  // 0 for custom URLs
					'target_type'  => $c['target_type'] ?? 'post',
					'target_title' => $c['target_title'],
					'anchor'       => $anchor,
					'anchor_lower' => $anchor_lower,
					'target_url'   => $permalink,
					'score'        => $c['score'],
				];

				$used_targets[] = $c['target_id'];
				$used_anchors[ $anchor_lower ] = $permalink;
				$remaining--;
			}

			// ═══════════════════════════════════════════════════════════════════
			// PHASE 2: Batch embed all anchors at once (1 API call instead of N)
			// ═══════════════════════════════════════════════════════════════════
			if ( empty( $pre_candidates ) ) {
				continue;
			}

			// Collect unique anchors for batch embedding
			$unique_anchors = [];
			$anchor_to_idx = [];
			foreach ( $pre_candidates as $pc ) {
				if ( ! isset( $anchor_to_idx[ $pc['anchor_lower'] ] ) ) {
					$anchor_to_idx[ $pc['anchor_lower'] ] = count( $unique_anchors );
					$unique_anchors[] = $pc['anchor'];
				}
			}

			// Batch embed all unique anchors
			$api = new SL_Embedding_API();
			$anchor_embeddings = $api->embed( $unique_anchors );

			$embeddings_ok = $anchor_embeddings && count( $anchor_embeddings ) === count( $unique_anchors );
			SL_Debug::log( 'matcher', '=== BATCH ANCHOR EMBEDDING ===', [
				'source_id'       => $src_id,
				'unique_anchors'  => count( $unique_anchors ),
				'embeddings_ok'   => $embeddings_ok ? 'yes' : 'no',
			] );

			// Build anchor → embedding map
			$anchor_embedding_map = [];
			if ( $embeddings_ok ) {
				foreach ( $unique_anchors as $idx => $anc ) {
					$anc_lower = mb_strtolower( $anc, 'UTF-8' );
					$anchor_embedding_map[ $anc_lower ] = $anchor_embeddings[ $idx ];
				}
			}

			// ═══════════════════════════════════════════════════════════════════
			// PHASE 3: Process pre-candidates with cluster deduplication
			// ═══════════════════════════════════════════════════════════════════
			foreach ( $pre_candidates as $pc ) {
				$anchor = $pc['anchor'];
				$anchor_lower = $pc['anchor_lower'];
				$permalink = $pc['target_url'];
				$anchor_embedding = $anchor_embedding_map[ $anchor_lower ] ?? null;

				$cluster_count = count( $progress['anchor_clusters'] ?? [] );

				if ( ! $anchor_embedding ) {
					// Fallback to text-based dedup if embedding fails
					SL_Debug::log( 'matcher', 'No embedding for anchor, using text fallback', [
						'anchor' => $anchor,
					] );

					// Fallback: check exact match in DB
					if ( SL_DB::anchor_used_globally_for_different_url( $anchor, $permalink ) ) {
						continue;
					}

					// Still add to clusters for text-based tracking (without embedding)
					$cluster_exists = false;
					foreach ( $progress['anchor_clusters'] as $cl ) {
						if ( mb_strtolower( $cl['anchor'], 'UTF-8' ) === $anchor_lower ) {
							$cluster_exists = true;
							break;
						}
					}
					if ( ! $cluster_exists ) {
						$progress['anchor_clusters'][] = [
							'anchor'     => $anchor,
							'target_url' => $permalink,
						];
					}
				} else {
					// Check if anchor belongs to any existing cluster (using in-memory embeddings)
					$existing_cluster = self::find_anchor_cluster(
						$anchor_embedding,
						$clusters_with_embeddings
					);

					if ( $existing_cluster ) {
						if ( $existing_cluster['target_url'] !== $permalink ) {
							// Conflict: same semantic cluster but different URL - skip this link
							SL_Debug::log( 'matcher', '*** CONFLICT DETECTED - SKIPPING LINK ***', [
								'new_anchor'     => $anchor,
								'cluster_anchor' => $existing_cluster['anchor'],
								'cluster_url'    => $existing_cluster['target_url'],
								'new_url'        => $permalink,
								'similarity'     => round( $existing_cluster['similarity'], 4 ),
							] );
							continue;
						}
						// Same URL - allow link
					} else {
						// No existing cluster - register as new cluster entry
						$progress['anchor_clusters'][] = [
							'anchor'     => $anchor,
							'target_url' => $permalink,
						];
						$clusters_with_embeddings[] = [
							'anchor'     => $anchor,
							'embedding'  => $anchor_embedding,
							'target_url' => $permalink,
						];
					}
				}

				SL_Debug::log( 'matcher', '>>> CREATING LINK <<<', [
					'source_id'  => $pc['source_id'],
					'anchor'     => $anchor,
					'target_url' => $permalink,
				] );

				// Add to candidates for Gemini filtering (or direct save)
				// Note: score is NOT rounded here - rounding happens once at DB insert time
				$new_candidates[] = [
					'source_id'    => $pc['source_id'],
					'target_id'    => $pc['target_id'],
					'target_type'  => $pc['target_type'] ?? 'post',
					'target_title' => $pc['target_title'],
					'anchor'       => $anchor,
					'target_url'   => $permalink,
					'score'        => $pc['score'],
				];
			}
		}

		// Merge new candidates (with limit to prevent memory issues)
		$progress['candidates'] = array_merge( $progress['candidates'], $new_candidates );
		if ( count( $progress['candidates'] ) > self::MAX_CANDIDATES ) {
			$progress['candidates'] = array_slice( $progress['candidates'], 0, self::MAX_CANDIDATES );
			SL_Debug::log( 'matcher', 'WARNING: Candidates array truncated to max limit', [
				'max_candidates' => self::MAX_CANDIDATES,
			] );
		}
		$progress['offset'] += self::BATCH_SIZE;

		// Log batch summary with cluster status (helps diagnose cluster persistence)
		SL_Debug::log( 'matcher', '=== BATCH END SUMMARY ===', [
			'batch_offset'       => $progress['offset'],
			'candidates_found'   => count( $new_candidates ),
			'total_candidates'   => count( $progress['candidates'] ),
			'clusters_count'     => count( $progress['anchor_clusters'] ?? [] ),
			'clusters_sample'    => array_slice( array_map( function( $c ) {
				return mb_substr( $c['anchor'], 0, 30, 'UTF-8' );
			}, $progress['anchor_clusters'] ?? [] ), 0, 5 ),
		] );

		set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

		$percent = $progress['total_sources'] > 0
			? round( ( $progress['offset'] / $progress['total_sources'] ) * 100 )
			: 0;

		return [
			'total_sources'    => $progress['total_sources'],
			'processed'        => min( $progress['offset'], $progress['total_sources'] ),
			'phase'            => 'matching',
			'percent'          => min( $percent, 100 ),
			'candidates_count' => count( $progress['candidates'] ),
			'message'          => sprintf(
				'Analizowanie podobieństwa: %d/%d postów (%d%%), %d kandydatów...',
				min( $progress['offset'], $progress['total_sources'] ),
				$progress['total_sources'],
				min( $percent, 100 ),
				count( $progress['candidates'] )
			),
		];
	}

	/**
	 * Process one batch of Gemini filtering.
	 *
	 * @param array $progress  Current progress data.
	 * @return array  Progress info.
	 */
	private static function process_gemini_batch( array $progress ): array {
		$candidates = $progress['candidates'];
		$offset     = $progress['gemini_offset'];
		$batch      = array_slice( $candidates, $offset, self::GEMINI_BATCH_SIZE );

		if ( empty( $batch ) ) {
			// Gemini filtering complete
			$progress['phase'] = 'complete';
			set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

			// Diagnostic: count links in DB
			global $wpdb;
			$db_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links" );
			SL_Debug::log( 'matcher', '=== GEMINI FILTERING COMPLETE ===', [
				'links_created'   => $progress['links_created'],
				'links_filtered'  => $progress['links_filtered'],
				'db_total_links'  => $db_count,
			] );

			// Check for API errors to report to user
			$api_warning = SL_Embedding_API::get_error_summary();
			SL_Embedding_API::clear_errors();

			$result = [
				'complete' => true,
				'message'  => sprintf(
					'Filtrowanie zakończone! Utworzono %d linków, wyfiltrowano %d.',
					$progress['links_created'],
					$progress['links_filtered']
				),
			];

			if ( $api_warning ) {
				$result['warning'] = $api_warning;
			}

			return $result;
		}

		$api = new SL_Embedding_API();

		// Build anchor → URL map from existing active links in DB for deduplication
		$existing_anchors = SL_DB::get_all_active_anchors();
		$anchor_url_map = [];  // normalized_anchor => target_url
		foreach ( $existing_anchors as $link ) {
			$normalized = mb_strtolower( trim( $link->anchor_text ), 'UTF-8' );
			if ( ! isset( $anchor_url_map[ $normalized ] ) ) {
				$anchor_url_map[ $normalized ] = $link->target_url;
			}
		}

		// Track anchors saved in this batch (to handle duplicates within same batch)
		$batch_saved_anchors = [];

		foreach ( $batch as $candidate ) {
			$anchor_normalized = mb_strtolower( trim( $candidate['anchor'] ), 'UTF-8' );

			// Check 1: Is this anchor already saved in DB for a DIFFERENT URL?
			if ( isset( $anchor_url_map[ $anchor_normalized ] ) &&
			     $anchor_url_map[ $anchor_normalized ] !== $candidate['target_url'] ) {
				SL_Debug::log( 'matcher', 'Gemini phase: anchor conflict with DB - skipping', [
					'source_id'    => $candidate['source_id'],
					'anchor'       => $candidate['anchor'],
					'existing_url' => $anchor_url_map[ $anchor_normalized ],
					'new_url'      => $candidate['target_url'],
				] );
				continue;
			}

			// Check 2: Is this anchor already saved in THIS BATCH for a DIFFERENT URL?
			if ( isset( $batch_saved_anchors[ $anchor_normalized ] ) &&
			     $batch_saved_anchors[ $anchor_normalized ] !== $candidate['target_url'] ) {
				SL_Debug::log( 'matcher', 'Gemini phase: anchor conflict within batch - skipping', [
					'source_id'    => $candidate['source_id'],
					'anchor'       => $candidate['anchor'],
					'existing_url' => $batch_saved_anchors[ $anchor_normalized ],
					'new_url'      => $candidate['target_url'],
				] );
				continue;
			}

			/* Custom URLs bypass Gemini filter (they're manually curated) */
			$is_custom_target = ( $candidate['target_type'] ?? 'post' ) === 'custom';
			if ( $is_custom_target ) {
				$is_match = true;
				SL_Debug::log( 'matcher', 'Custom URL: bypassing Gemini filter', [
					'source_id'    => $candidate['source_id'],
					'anchor'       => $candidate['anchor'],
					'target_url'   => $candidate['target_url'],
				] );
			} else {
				$is_match = $api->evaluate_anchor_match(
					$candidate['anchor'],
					$candidate['target_title']
				);
			}

			$status = $is_match ? 'active' : 'filtered';

			// Skip if this URL already reached max links limit (only for active links)
			$target_url = $candidate['target_url'];
			if ( $is_match ) {
				$max_links_per_url = (int) SL_Settings::get( 'max_links_per_url', 10 );
				if ( SL_DB::get_active_links_to_url( $target_url ) >= $max_links_per_url ) {
					SL_Debug::log( 'matcher', 'Gemini phase: URL reached max links limit - skipping', [
						'source_id'  => $candidate['source_id'],
						'target_url' => $target_url,
						'limit'      => $max_links_per_url,
					] );
					continue;
				}
			}

			$inserted = SL_DB::insert_link( [
				'post_id'          => $candidate['source_id'],
				'anchor_text'      => $candidate['anchor'],
				'target_url'       => $target_url,
				'target_post_id'   => $candidate['target_id'],
				'similarity_score' => round( $candidate['score'], 4 ),
				'status'           => $status,
			] );

			if ( $inserted ) {
				// Track this anchor for deduplication within batch
				$batch_saved_anchors[ $anchor_normalized ] = $target_url;
				// Also update in-memory map for subsequent iterations
				$anchor_url_map[ $anchor_normalized ] = $target_url;

				if ( $is_match ) {
					$progress['links_created']++;
					// Update URL links cache
					SL_DB::increment_url_links_cache( $target_url );
					delete_transient( 'sl_inj_' . $candidate['source_id'] );
				} else {
					$progress['links_filtered']++;
				}

				SL_Debug::log( 'matcher', 'Link processed by Gemini', [
					'source_id'    => $candidate['source_id'],
					'anchor'       => $candidate['anchor'],
					'target_title' => $candidate['target_title'],
					'status'       => $status,
				] );
			}
		}

		$progress['gemini_offset'] += self::GEMINI_BATCH_SIZE;
		set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

		$total_candidates = count( $candidates );
		$processed        = min( $progress['gemini_offset'], $total_candidates );
		$percent          = $total_candidates > 0
			? round( ( $processed / $total_candidates ) * 100 )
			: 100;

		// Check for API errors to report to user
		$api_warning = SL_Embedding_API::get_error_summary();
		if ( $api_warning ) {
			SL_Embedding_API::clear_errors();  // Clear after reporting
		}

		$result = [
			'total_candidates' => $total_candidates,
			'processed'        => $processed,
			'phase'            => 'filtering',
			'percent'          => $percent,
			'links_created'    => $progress['links_created'],
			'links_filtered'   => $progress['links_filtered'],
			'message'          => sprintf(
				'Filtrowanie Gemini AI: %d/%d (%d%%) - %d ok, %d wyfiltrowanych...',
				$processed,
				$total_candidates,
				$percent,
				$progress['links_created'],
				$progress['links_filtered']
			),
		];

		if ( $api_warning ) {
			$result['warning'] = $api_warning;
		}

		return $result;
	}

	/**
	 * Get current matching progress.
	 *
	 * @return array|null  Progress info or null if not running.
	 */
	public static function get_progress(): ?array {
		return get_transient( self::PROGRESS_KEY ) ?: null;
	}

	/**
	 * Cancel ongoing matching and clean up all related state.
	 *
	 * @param bool $cancel_indexer  Also cancel any indexing in progress (default true).
	 */
	public static function cancel( bool $cancel_indexer = true ): void {
		$had_progress = get_transient( self::PROGRESS_KEY ) !== false;

		delete_transient( self::PROGRESS_KEY );

		// Also cancel indexer if needed (prevent recursion with false flag)
		if ( $cancel_indexer ) {
			SL_Indexer::cancel( false );  // false = don't recurse back
		}

		if ( $had_progress ) {
			SL_Debug::log( 'matcher', 'Matching cancelled and state cleared' );
		}
	}

	/* ── Anchor selection ─────────────────────────────────────── */

	/**
	 * Find the best anchor-text phrase inside $chunk that relates to
	 * $target_title.
	 *
	 * The returned string is an **exact substring** of $chunk
	 * (original casing preserved).
	 *
	 * @param string $chunk         Plain-text source chunk (no HTML).
	 * @param string $target_title  Title of the target post.
	 * @return string|null          Best anchor, or null if nothing qualifies.
	 */
	public static function find_anchor( string $chunk, string $target_title ) {
		/* Early exit for empty inputs */
		if ( empty( $chunk ) || empty( $target_title ) ) {
			return null;
		}

		/* Get anchor word count limits from settings */
		$min_words = (int) SL_Settings::get( 'min_anchor_words', 2 );
		$max_words = (int) SL_Settings::get( 'max_anchor_words', 6 );

		/* Title words: lowercased, stop-words removed, min 3 chars */
		$title_words = preg_split( '/[\s\p{P}]+/u', mb_strtolower( strip_tags( $target_title ), 'UTF-8' ) );
		$title_words = array_values( array_filter( $title_words, function ( $w ) {
			return mb_strlen( $w, 'UTF-8' ) >= 3
				&& ! in_array( $w, self::STOP_WORDS, true );
		} ) );

		if ( empty( $title_words ) ) {
			return null;
		}

		/* Extract candidate n-grams (min–max words) from the chunk */
		$words = preg_split( '/\s+/u', trim( $chunk ), -1, PREG_SPLIT_NO_EMPTY );
		$count = count( $words );

		$scored = [];

		for ( $n = $min_words; $n <= min( $max_words, $count ); $n++ ) {
			for ( $i = 0; $i <= $count - $n; $i++ ) {
				$ngram = implode( ' ', array_slice( $words, $i, $n ) );

				/* ── Filter 1: Skip n-grams containing sentence boundaries ────
				 * Periods inside anchor indicate bad phrase boundaries.
				 * Also skip anchors ending with any punctuation.
				 */
				if ( mb_strpos( $ngram, '.', 0, 'UTF-8' ) !== false ) {
					continue;
				}

				// Skip anchors ending with any punctuation mark
				$last_char = mb_substr( $ngram, -1, 1, 'UTF-8' );
				$forbidden_endings = [ ',', '.', ';', ':', '!', '?', '-', '–', '—', '„', '"', '"', '\'', '"', '(', ')', '[', ']', '{', '}', '/', '\\', '|', '+', '=', '*', '&', '%', '$', '#', '@', '^', '~', '`', '<', '>' ];
				if ( in_array( $last_char, $forbidden_endings, true ) ) {
					continue;
				}

				// Also skip anchors starting with punctuation
				$first_char = mb_substr( $ngram, 0, 1, 'UTF-8' );
				if ( in_array( $first_char, $forbidden_endings, true ) ) {
					continue;
				}

				/* Lowercase + strip punctuation for comparison only */
				$ngram_words = preg_split(
					'/[\s\p{P}]+/u',
					mb_strtolower( $ngram, 'UTF-8' ),
					-1,
					PREG_SPLIT_NO_EMPTY
				);
				$ngram_words = array_values( array_filter(
					$ngram_words,
					function ( $w ) { return $w !== ''; }
				) );

				if ( empty( $ngram_words ) ) {
					continue;
				}

				/* ── Filter: Check real word count (punctuation not counted) ────
				 * The ngram_words array has punctuation stripped, so this is
				 * the true word count for min/max validation.
				 */
				$real_word_count = count( $ngram_words );
				if ( $real_word_count < $min_words || $real_word_count > $max_words ) {
					continue;
				}

				/* ── Filter 2: Skip n-grams ending with conjunctions ───
				 * Anchors like "zasady oraz" or "kredytu w" feel incomplete.
				 */
				$last_ngram_word = $ngram_words[ count( $ngram_words ) - 1 ] ?? '';
				if ( in_array( $last_ngram_word, self::TRAILING_FORBIDDEN, true ) ) {
					continue;
				}

				/* Word-overlap scoring */
				$overlap = count( array_intersect( $ngram_words, $title_words ) );
				if ( $overlap === 0 ) {
					continue;
				}

				$precision = $overlap / count( $ngram_words );
				$recall    = $overlap / count( $title_words );

				/* Require strong connection to target title:
				 * - At least 2 words overlap, OR
				 * - At least 50% of anchor words are from the title */
				if ( $overlap < 2 && $precision < 0.5 ) {
					continue;
				}

				$f1 = ( 2 * $precision * $recall ) / ( $precision + $recall );

				/* Completeness bonus: penalize anchors ending with stop-words
				 * (incomplete phrases like "kredyt hipoteczny jest") */
				$first_word = $ngram_words[0] ?? '';
				$last_word  = $ngram_words[ count( $ngram_words ) - 1 ] ?? '';

				$completeness = 1.0;
				if ( in_array( $last_word, self::STOP_WORDS, true ) ) {
					$completeness -= 0.15;  // Penalty for ending with stop-word
				}
				if ( in_array( $first_word, self::STOP_WORDS, true ) ) {
					$completeness -= 0.10;  // Smaller penalty for starting with stop-word
				}

				/* Final score combines F1 with completeness */
				$final_score = $f1 * $completeness;

				$scored[] = [ 'text' => $ngram, 'f1' => $final_score ];
			}
		}

		if ( empty( $scored ) ) {
			return null;
		}

		/* Sort: highest F1 first; ties broken by shorter length (tighter anchor) */
		usort( $scored, function ( $a, $b ) {
			if ( $a['f1'] != $b['f1'] ) {
				return $b['f1'] <=> $a['f1'];
			}
			return mb_strlen( $a['text'], 'UTF-8' ) <=> mb_strlen( $b['text'], 'UTF-8' );
		} );

		/* Return first candidate found as an exact (case-insensitive)
		 * substring – extract with original casing from $chunk */
		foreach ( $scored as $candidate ) {
			$pos = mb_stripos( $chunk, $candidate['text'], 0, 'UTF-8' );
			if ( $pos !== false ) {
				return mb_substr(
					$chunk,
					$pos,
					mb_strlen( $candidate['text'], 'UTF-8' ),
					'UTF-8'
				);
			}
		}

		return null;
	}

	/* ── Anchor normalization (for deduplication) ────────────────── */

	/**
	 * Normalize anchor text for comparison (Polish stemming-like).
	 * Used to detect semantically similar anchors like "kredyt hipoteczny"
	 * and "kredytu hipotecznego".
	 *
	 * @param string $anchor
	 * @return string  Normalized lowercase string with simplified word forms.
	 */
	public static function normalize_anchor( string $anchor ): string {
		$words = preg_split( '/\s+/u', mb_strtolower( trim( $anchor ), 'UTF-8' ) );
		$normalized = [];

		foreach ( $words as $word ) {
			$word = preg_replace( '/[^\p{L}]/u', '', $word );  // Keep only letters
			if ( mb_strlen( $word, 'UTF-8' ) < 3 ) {
				continue;
			}

			// Try to strip common Polish suffixes
			foreach ( self::POLISH_SUFFIXES as $suffix ) {
				$len = mb_strlen( $suffix, 'UTF-8' );
				if ( mb_strlen( $word, 'UTF-8' ) > $len + 2 &&
				     mb_substr( $word, -$len, null, 'UTF-8' ) === $suffix ) {
					$word = mb_substr( $word, 0, -$len, 'UTF-8' );
					break;
				}
			}

			$normalized[] = $word;
		}

		sort( $normalized );  // Sort for consistent comparison
		return implode( ' ', $normalized );
	}

	/**
	 * Find which cluster a new anchor belongs to based on embedding similarity.
	 * Returns the cluster info if found (similarity > cluster_threshold), or null.
	 *
	 * @param array  $anchor_embedding  The embedding vector of the new anchor.
	 * @param array  $anchor_clusters   Array of existing clusters with embeddings.
	 * @return array|null  Cluster info ['anchor' => '...', 'target_url' => '...', 'similarity' => 0.xx] or null.
	 */
	public static function find_anchor_cluster( array $anchor_embedding, array $anchor_clusters ): ?array {
		$cluster_threshold = (float) SL_Settings::get( 'cluster_threshold', self::CLUSTER_THRESHOLD );

		$best_match = null;
		$best_similarity = 0.0;
		$all_similarities = [];  // For debug logging
		$clusters_checked = 0;

		foreach ( $anchor_clusters as $idx => $cluster ) {
			if ( empty( $cluster['embedding'] ) ) {
				continue;
			}
			$clusters_checked++;

			$similarity = self::cosine( $anchor_embedding, $cluster['embedding'] );

			// Store all similarities for debugging (keep top 5 closest)
			$all_similarities[] = [
				'idx'        => $idx,
				'anchor'     => mb_substr( $cluster['anchor'], 0, 40, 'UTF-8' ),
				'target_url' => $cluster['target_url'],
				'similarity' => round( $similarity, 4 ),
			];

			if ( $similarity > $cluster_threshold && $similarity > $best_similarity ) {
				$best_similarity = $similarity;
				$best_match = [
					'anchor'     => $cluster['anchor'],
					'target_url' => $cluster['target_url'],
					'similarity' => $similarity,
				];
			}
		}

		// Sort by similarity descending and keep top 5 for logging
		usort( $all_similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );
		$top_similarities = array_slice( $all_similarities, 0, 5 );

		SL_Debug::log( 'matcher', 'find_anchor_cluster() check', [
			'threshold'         => $cluster_threshold,
			'clusters_checked'  => $clusters_checked,
			'best_similarity'   => round( $best_similarity, 4 ),
			'found_match'       => $best_match ? 'YES' : 'NO',
			'match_anchor'      => $best_match ? mb_substr( $best_match['anchor'], 0, 40, 'UTF-8' ) : null,
			'match_url'         => $best_match ? $best_match['target_url'] : null,
			'top_5_similarities'=> $top_similarities,
		] );

		return $best_match;
	}

	/**
	 * Check if an anchor belongs to an existing cluster for a different URL.
	 * Uses embedding-based clustering for accurate semantic matching.
	 *
	 * @param array  $anchor_embedding  The embedding vector of the new anchor.
	 * @param string $target_url        The target URL for this anchor.
	 * @param array  $anchor_clusters   Array of existing clusters with embeddings.
	 * @return array|null  Conflicting cluster info if found, or null if OK.
	 */
	public static function anchor_conflicts_with_cluster( array $anchor_embedding, string $target_url, array $anchor_clusters ): ?array {
		$cluster = self::find_anchor_cluster( $anchor_embedding, $anchor_clusters );

		if ( $cluster && $cluster['target_url'] !== $target_url ) {
			return $cluster;  // Conflict: same cluster but different URL
		}

		return null;  // No conflict
	}

	/* ── Anchor injection validation ───────────────────────────── */

	/**
	 * Check if an anchor text can actually be injected into a post's content.
	 * Returns false if the anchor only appears inside excluded tags (h1, h2, code, etc.)
	 * or inside existing links.
	 *
	 * @param int    $post_id
	 * @param string $anchor
	 * @return bool  True if anchor can be injected, false if only in excluded tags.
	 */
	public static function anchor_can_be_injected( int $post_id, string $anchor ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content = $post->post_content;
		$excluded_tags = SL_Settings::get(
			'excluded_tags',
			[ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'script', 'style' ]
		);

		// Also always exclude links
		$excluded_tags[] = 'a';

		/* Remove WordPress block editor comments */
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		/* Remove content inside excluded tags - use recursive pattern for nested tags */
		foreach ( $excluded_tags as $tag ) {
			$tag_esc = preg_quote( $tag, '/' );
			// Handle both self-closing and regular tags
			// Use DOTALL and non-greedy matching, process multiple times for nested
			$max_iterations = 5;
			for ( $i = 0; $i < $max_iterations; $i++ ) {
				$before = $content;
				$content = preg_replace(
					'/<' . $tag_esc . '(?:\s[^>]*)?>.*?<\/' . $tag_esc . '>/is',
					' ',  // Replace with space to preserve word boundaries
					$content
				);
				if ( $content === $before ) {
					break;  // No more matches
				}
			}
		}

		/* Strip remaining HTML tags */
		$plain_text = strip_tags( $content );

		/* Normalize whitespace */
		$plain_text = preg_replace( '/\s+/u', ' ', $plain_text );

		/* Check if anchor exists as a standalone phrase (not just substring) */
		$anchor_pattern = '/(?<![a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ])' .
			preg_quote( $anchor, '/' ) .
			'(?![a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ])/iu';

		return (bool) preg_match( $anchor_pattern, $plain_text );
	}

	/* ── Category check ────────────────────────────────────────── */

	/**
	 * Check if two posts share at least one category.
	 *
	 * @param int $post_id_a
	 * @param int $post_id_b
	 * @return bool  True if they share at least one category.
	 */
	public static function posts_share_category( int $post_id_a, int $post_id_b ): bool {
		$cats_a = wp_get_post_categories( $post_id_a, [ 'fields' => 'ids' ] );
		$cats_b = wp_get_post_categories( $post_id_b, [ 'fields' => 'ids' ] );

		if ( empty( $cats_a ) || empty( $cats_b ) ) {
			// If either post has no categories, don't link (fail-close)
			// This respects the user's intent when enabling same_category_only
			return false;
		}

		return ! empty( array_intersect( $cats_a, $cats_b ) );
	}

	/* ── Cosine similarity ─────────────────────────────────────── */

	/**
	 * Compute cosine similarity between two float vectors.
	 *
	 * @param float[] $a
	 * @param float[] $b
	 * @return float  Value in [0, 1] for normalised embedding vectors.
	 */
	public static function cosine( array $a, array $b ): float {
		/* Early exit for empty or mismatched vectors */
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}

		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;
		$len   = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot   += $a[$i] * $b[$i];
			$mag_a += $a[$i] * $a[$i];
			$mag_b += $b[$i] * $b[$i];
		}

		/* Guard against zero vectors (division by zero) */
		if ( $mag_a <= 0.0 || $mag_b <= 0.0 ) {
			return 0.0;
		}

		$denom = sqrt( $mag_a ) * sqrt( $mag_b );
		return $dot / $denom;
	}
}
