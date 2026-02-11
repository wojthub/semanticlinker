<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexer – Phase 1 of the pipeline.
 *
 * Responsibilities:
 *   1. Detect which posts are stale (content changed since last embed).
 *   2. Chunk their content into paragraph / sentence fragments.
 *   3. Send all fragments to the embedding API in a single batch call.
 *   4. Persist the returned vectors in wp_semantic_embeddings.
 *   5. Kick off Phase 2 (SL_Matcher::match_all).
 *
 * Triggered by:
 *   – WP-Cron hook `sl_run_indexing` (hourly by default).
 *   – Manual "Reindeksuj teraz" button via SL_Ajax.
 */
class SL_Indexer {

	/** Minimum UTF-8 character count for a chunk to be worth embedding. */
	private const MIN_CHARS = 25;

	/** Maximum UTF-8 character count; longer chunks are split further. */
	private const MAX_CHARS = 600;

	/** Posts processed per single cron tick (API cost guard). */
	private const BATCH_POSTS = 150;

	/** Posts processed per AJAX batch (for progress reporting). */
	private const AJAX_BATCH_SIZE = 20;

	/** Transient key for storing indexing progress. */
	private const PROGRESS_KEY = 'sl_indexing_progress';

	public function __construct() {
		add_action( 'sl_run_indexing', [ $this, 'run' ] );
	}

	/* ── Batch processing for AJAX with progress ─────────────────── */

	/**
	 * Initialize batch indexing - returns total posts to process.
	 *
	 * @return array  Progress info with total_posts, processed, phase.
	 */
	public static function init_batch(): array {
		SL_Debug::clear();
		SL_Debug::log( 'indexer', '=== BATCH INDEXING INITIALIZED ===' );

		// Check API key first (using decrypted key)
		$api_key = SL_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			SL_Debug::log( 'indexer', 'ERROR: API key is not configured' );
			return [ 'error' => 'Klucz API Gemini nie jest skonfigurowany. Wpisz klucz w ustawieniach.' ];
		}

		// Clear any stale matching progress from previous runs
		SL_Matcher::cancel();

		$post_types = SL_Settings::get( 'post_types', [ 'post' ] );

		if ( empty( $post_types ) ) {
			return [ 'error' => 'Brak skonfigurowanych typów postów.' ];
		}

		// Get all posts to process
		$posts = get_posts( [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'numberposts'            => -1,  // All posts
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		$total = count( $posts );

		// Store progress in transient
		$progress = [
			'total_posts'   => $total,
			'processed'     => 0,
			'phase'         => 'indexing',  // indexing or matching
			'offset'        => 0,
			'stale_ids'     => [],  // Will be populated as we discover stale posts
			'started_at'    => current_time( 'mysql' ),
		];

		set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

		return [
			'total_posts' => $total,
			'processed'   => 0,
			'phase'       => 'indexing',
			'message'     => sprintf( 'Znaleziono %d postów do przetworzenia.', $total ),
		];
	}

	/**
	 * Process one batch of posts for AJAX.
	 *
	 * @return array  Progress info.
	 */
	public static function process_batch(): array {
		$progress = get_transient( self::PROGRESS_KEY );

		if ( ! $progress ) {
			return [ 'error' => 'Nie znaleziono sesji indeksowania. Uruchom ponownie.' ];
		}

		$post_types = SL_Settings::get( 'post_types', [ 'post' ] );

		// If in matching or filtering phase - delegate to matcher
		if ( in_array( $progress['phase'], [ 'matching', 'filtering' ], true ) ) {
			// Initialize matching if not already initialized
			$match_progress = SL_Matcher::get_progress();
			if ( ! $match_progress ) {
				SL_Debug::log( 'indexer', '=== INITIALIZING MATCHER ===' );
				$init_result = SL_Matcher::init_matching();
				if ( isset( $init_result['error'] ) ) {
					delete_transient( self::PROGRESS_KEY );
					return [ 'error' => $init_result['error'] ];
				}
				// Return after init WITHOUT processing first batch in the same request.
				// init_matching() already uses memory for anchor loading.
				// process_matching_batch() will load all title embeddings (~490MB peak for 800 posts).
				// Splitting into separate requests prevents double memory usage in one request.
				return [
					'total_posts' => $progress['total_posts'],
					'processed'   => $progress['total_posts'],
					'phase'       => 'matching',
					'message'     => sprintf( 'Matcher zainicjalizowany (%d postów). Rozpoczynam dopasowanie...', $init_result['total_sources'] ?? 0 ),
				];
			}

			// Process matching batch
			$result = SL_Matcher::process_matching_batch();

			if ( isset( $result['complete'] ) && $result['complete'] ) {
				delete_transient( self::PROGRESS_KEY );
				update_option( 'sl_last_indexing_run', current_time( 'mysql' ) );

				return [
					'total_posts' => $progress['total_posts'],
					'processed'   => $progress['total_posts'],
					'phase'       => 'complete',
					'complete'    => true,
					'message'     => $result['message'] ?? 'Indeksacja i matching zakończone!',
				];
			}

			// Update phase from matcher result
			if ( isset( $result['phase'] ) ) {
				$progress['phase'] = $result['phase'];
				set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );
			}

			// Return matcher progress with adjusted message
			return [
				'total_posts' => $progress['total_posts'],
				'processed'   => $progress['total_posts'],  // Indexing done, show as complete
				'phase'       => $result['phase'] ?? 'matching',
				'percent'     => $result['percent'] ?? 0,
				'message'     => $result['message'] ?? 'Przetwarzanie...',
			];
		}

		// Get batch of posts
		$posts = get_posts( [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'numberposts'            => self::AJAX_BATCH_SIZE,
			'offset'                 => $progress['offset'],
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		if ( empty( $posts ) ) {
			// Before moving to matching phase, generate embeddings for any custom URLs that need them
			self::process_custom_urls_needing_embedding();

			// No more posts - move to matching phase
			$progress['phase'] = 'matching';
			set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );

			return [
				'total_posts' => $progress['total_posts'],
				'processed'   => $progress['processed'],
				'phase'       => 'matching',
				'message'     => 'Indeksowanie zakończone. Uruchamianie matchera...',
			];
		}

		// Process this batch
		$to_embed = [];
		$skipped = 0;

		foreach ( $posts as $post ) {
			$hash = md5( $post->post_title . $post->post_content );
			if ( SL_DB::embeddings_are_current( $post->ID, $hash ) ) {
				$skipped++;
				continue;
			}

			$chunks = self::extract_chunks( $post->post_content );
			$to_embed[ $post->ID ] = [
				'title'  => strip_tags( $post->post_title ),
				'chunks' => $chunks,
				'hash'   => $hash,
			];
		}

		// If there are posts to embed in this batch
		$embedding_failed = false;

		if ( ! empty( $to_embed ) ) {
			$texts = [];
			$map   = [];

			foreach ( $to_embed as $pid => $data ) {
				$map[]   = [ $pid, 0 ];
				$texts[] = $data['title'];

				foreach ( $data['chunks'] as $i => $chunk ) {
					$map[]   = [ $pid, $i + 1 ];
					$texts[] = $chunk;
				}
			}

			$api     = new SL_Embedding_API();
			$vectors = $api->embed( $texts );

			if ( $vectors ) {
				$flushed = [];
				foreach ( $vectors as $idx => $vector ) {
					/* Safety: validate map index exists */
					if ( ! isset( $map[$idx] ) || ! isset( $texts[$idx] ) ) {
						SL_Debug::log( 'indexer', 'WARNING: Map/text index mismatch', [
							'idx'         => $idx,
							'map_count'   => count( $map ),
							'texts_count' => count( $texts ),
						] );
						continue;
					}

					$pid       = $map[$idx][0];
					$chunk_idx = $map[$idx][1];

					if ( ! isset( $flushed[$pid] ) ) {
						SL_DB::delete_embeddings( $pid );
						$flushed[$pid] = true;
					}

					SL_DB::upsert_embedding(
						$pid,
						$chunk_idx,
						$texts[$idx],
						$vector,
						$to_embed[$pid]['hash']
					);
				}

				SL_Debug::log( 'indexer', 'Batch processed', [
					'posts_embedded' => count( $flushed ),
					'posts_skipped'  => $skipped,
				] );
			} else {
				// API call failed - mark for retry, don't advance progress
				$embedding_failed = true;
			}
		}

		// Only update progress if embedding succeeded (or no embedding was needed)
		if ( ! $embedding_failed ) {
			$progress['processed'] += count( $posts );
			$progress['offset']    += self::AJAX_BATCH_SIZE;
			set_transient( self::PROGRESS_KEY, $progress, HOUR_IN_SECONDS );
		} else {
			$pct = $progress['total_posts'] > 0
				? round( ( $progress['processed'] / $progress['total_posts'] ) * 100 )
				: 0;

			// 429 rate-limit: signal JS to wait and retry same batch.
			// DO NOT sleep() here - that holds the HTTP connection and causes 504 Gateway Timeout.
			if ( SL_Embedding_API::was_rate_limited() ) {
				SL_Debug::log( 'indexer', 'Rate limited (429) - client will retry in 2s', [
					'offset' => $progress['offset'],
				] );
				return [
					'total_posts'  => $progress['total_posts'],
					'processed'    => $progress['processed'],
					'phase'        => 'indexing',
					'percent'      => $pct,
					'rate_limited' => true,
					'retry_after'  => 2,
					'message'      => 'Limit API Gemini – ponawianie za 2s...',
				];
			}

			// Other API failure
			SL_Debug::log( 'indexer', 'ERROR: Embedding API failed for batch', [
				'offset'      => $progress['offset'],
				'posts_count' => count( $to_embed ),
			] );
			return [
				'total_posts' => $progress['total_posts'],
				'processed'   => $progress['processed'],
				'phase'       => 'indexing',
				'percent'     => $pct,
				'error'       => 'Błąd API podczas generowania embeddingow. Spróbuj ponownie.',
				'message'     => 'Błąd API - kliknij ponownie aby wznowić.',
			];
		}
		$percent = $progress['total_posts'] > 0
			? round( ( $progress['processed'] / $progress['total_posts'] ) * 100 )
			: 0;

		return [
			'total_posts' => $progress['total_posts'],
			'processed'   => $progress['processed'],
			'phase'       => 'indexing',
			'percent'     => $percent,
			'message'     => sprintf(
				'Przetworzono %d z %d postów (%d%%)...',
				$progress['processed'],
				$progress['total_posts'],
				$percent
			),
		];
	}

	/**
	 * Get current indexing progress.
	 *
	 * @return array|null  Progress info or null if not running.
	 */
	public static function get_progress(): ?array {
		$progress = get_transient( self::PROGRESS_KEY );
		if ( ! $progress ) {
			return null;
		}

		$percent = $progress['total_posts'] > 0
			? round( ( $progress['processed'] / $progress['total_posts'] ) * 100 )
			: 0;

		return [
			'total_posts' => $progress['total_posts'],
			'processed'   => $progress['processed'],
			'phase'       => $progress['phase'],
			'percent'     => $percent,
		];
	}

	/**
	 * Cancel ongoing indexing and clean up all related state.
	 *
	 * @param bool $cancel_matcher  Also cancel any matching in progress (default true).
	 */
	public static function cancel( bool $cancel_matcher = true ): void {
		$had_progress = get_transient( self::PROGRESS_KEY ) !== false;

		delete_transient( self::PROGRESS_KEY );

		// Also cancel matching if it was started by this indexing session
		if ( $cancel_matcher ) {
			SL_Matcher::cancel( false );  // false = don't recurse back
		}

		if ( $had_progress ) {
			SL_Debug::log( 'indexer', 'Indexing cancelled and state cleared' );
		}
	}

	/* ── Entry point ──────────────────────────────────────────── */

	public function run(): void {
		SL_Debug::clear();
		SL_Debug::log( 'indexer', '=== INDEXING STARTED ===' );

		$post_types = SL_Settings::get( 'post_types', [ 'post' ] );
		SL_Debug::log( 'indexer', 'Configured post types', [ 'post_types' => $post_types ] );

		if ( empty( $post_types ) ) {
			SL_Debug::log( 'indexer', 'ERROR: No post types configured - aborting' );
			return;
		}

		$posts = get_posts( [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'numberposts'            => self::BATCH_POSTS,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		SL_Debug::log( 'indexer', 'Posts fetched from database', [ 'count' => count( $posts ) ] );

		if ( empty( $posts ) ) {
			SL_Debug::log( 'indexer', 'WARNING: No published posts found' );
			update_option( 'sl_last_indexing_run', current_time( 'mysql' ) );
			return;
		}

		/* ── Detect stale posts ──────────────────────────────────── */
		$to_embed = [];  // post_id => [ 'title', 'chunks', 'hash' ]
		$skipped_current = 0;

		foreach ( $posts as $post ) {
			$hash = md5( $post->post_title . $post->post_content );
			if ( SL_DB::embeddings_are_current( $post->ID, $hash ) ) {
				$skipped_current++;
				continue;
			}
			$chunks = self::extract_chunks( $post->post_content );
			$to_embed[ $post->ID ] = [
				'title'  => strip_tags( $post->post_title ),
				'chunks' => $chunks,
				'hash'   => $hash,
			];
			SL_Debug::log( 'indexer', 'Post queued for embedding', [
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'chunks_count' => count( $chunks ),
				'content_len'  => mb_strlen( $post->post_content ),
			] );
		}

		SL_Debug::log( 'indexer', 'Stale detection complete', [
			'to_embed'        => count( $to_embed ),
			'skipped_current' => $skipped_current,
		] );

		if ( empty( $to_embed ) ) {
			SL_Debug::log( 'indexer', 'All posts already indexed - running matcher only' );
			update_option( 'sl_last_indexing_run', current_time( 'mysql' ) );
			// Process any custom URLs needing embeddings
			self::process_custom_urls_needing_embedding();
			// Even if nothing changed, run matching for new posts
			( new SL_Matcher() )->match_all();
			return;
		}

		/* ── Build flat text batch ───────────────────────────────── */
		$texts = [];       // sequential array of strings for the API
		$map   = [];       // same-index array: [ post_id, chunk_index ]

		foreach ( $to_embed as $pid => $data ) {
			// chunk_index 0 = title embedding (used as the "target" in matching)
			$map[]  = [ $pid, 0 ];
			$texts[] = $data['title'];

			foreach ( $data['chunks'] as $i => $chunk ) {
				$map[]  = [ $pid, $i + 1 ];   // content chunks start at 1
				$texts[] = $chunk;
			}
		}

		/* ── API call ────────────────────────────────────────────── */
		SL_Debug::log( 'indexer', 'Calling Gemini API', [ 'texts_count' => count( $texts ) ] );

		$api     = new SL_Embedding_API();
		$vectors = $api->embed( $texts );

		if ( ! $vectors ) {
			SL_Debug::log( 'indexer', 'ERROR: API call failed - no vectors returned' );
			return;
		}

		SL_Debug::log( 'indexer', 'API call successful', [
			'vectors_count' => count( $vectors ),
			'vector_dim'    => ! empty( $vectors[0] ) ? count( $vectors[0] ) : 0,
		] );

		/* ── Persist ─────────────────────────────────────────────── */
		$flushed = [];   // track which posts had old rows removed

		foreach ( $vectors as $idx => $vector ) {
			$pid        = $map[$idx][0];
			$chunk_idx  = $map[$idx][1];

			// Delete stale rows once per post (before first insert)
			if ( ! isset( $flushed[$pid] ) ) {
				SL_DB::delete_embeddings( $pid );
				$flushed[$pid] = true;
			}

			SL_DB::upsert_embedding(
				$pid,
				$chunk_idx,
				$texts[$idx],
				$vector,
				$to_embed[$pid]['hash']
			);
		}

		SL_Debug::log( 'indexer', 'Embeddings persisted', [ 'posts_updated' => count( $flushed ) ] );

		update_option( 'sl_last_indexing_run', current_time( 'mysql' ) );

		// Process any custom URLs needing embeddings before matching
		self::process_custom_urls_needing_embedding();

		/* ── Phase 2: matching ───────────────────────────────────── */
		SL_Debug::log( 'indexer', '=== STARTING MATCHER ===' );
		( new SL_Matcher() )->match_all();
	}

	/* ── Chunking ─────────────────────────────────────────────── */

	/**
	 * Split raw post HTML into clean-text chunks suitable for
	 * embedding.
	 *
	 * Strategy:
	 *   a) Split on <p> tag boundaries (WordPress default structure).
	 *   b) Strip tags, collapse whitespace.
	 *   c) Merge very short fragments; split fragments that exceed
	 *      MAX_CHARS into sentences.
	 *
	 * @param string $html  Raw post_content (may contain block-editor comments).
	 * @return string[]
	 */
	public static function extract_chunks( string $html ): array {
		/* 1. Split on <p> boundaries */
		$parts = preg_split( '/<\/?p[^>]*>/i', $html, -1, PREG_SPLIT_NO_EMPTY );

		$paragraphs = [];
		foreach ( $parts as $part ) {
			$clean = trim( strip_tags( $part ) );
			$clean = preg_replace( '/\s+/u', ' ', $clean );
			if ( mb_strlen( $clean, 'UTF-8' ) >= self::MIN_CHARS ) {
				$paragraphs[] = $clean;
			}
		}

		/* 2. Fallback: no paragraphs survived → treat whole content as one block */
		if ( empty( $paragraphs ) ) {
			$text = preg_replace( '/\s+/u', ' ', trim( strip_tags( $html ) ) );
			if ( mb_strlen( $text, 'UTF-8' ) < self::MIN_CHARS ) {
				return [];
			}
			$paragraphs = self::split_sentences( $text );
		}

		/* 3. Enforce MAX_CHARS: split long paragraphs into sentences */
		$chunks = [];
		foreach ( $paragraphs as $para ) {
			if ( mb_strlen( $para, 'UTF-8' ) <= self::MAX_CHARS ) {
				$chunks[] = $para;
			} else {
				$chunks = array_merge( $chunks, self::split_sentences( $para ) );
			}
		}

		return $chunks;
	}

	/**
	 * Split a plain-text block into sentence-sized chunks that each
	 * fit within MAX_CHARS.  Consecutive short sentences are merged
	 * into one chunk.
	 *
	 * @param string $text
	 * @return string[]
	 */
	private static function split_sentences( string $text ): array {
		// Polish-aware sentence split: break after . ! ? followed by space
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		$chunks = [];
		$buffer = '';

		foreach ( $sentences as $s ) {
			$s = trim( $s );
			if ( $s === '' ) {
				continue;
			}
			$candidate = $buffer === '' ? $s : "{$buffer} {$s}";

			if ( mb_strlen( $candidate, 'UTF-8' ) <= self::MAX_CHARS ) {
				$buffer = $candidate;
			} else {
				if ( mb_strlen( $buffer, 'UTF-8' ) >= self::MIN_CHARS ) {
					$chunks[] = $buffer;
				}
				$buffer = $s;
			}
		}

		if ( mb_strlen( $buffer, 'UTF-8' ) >= self::MIN_CHARS ) {
			$chunks[] = $buffer;
		}

		return $chunks;
	}

	/* ── Custom URLs embedding ────────────────────────────────────────── */

	/**
	 * Generate embeddings for any custom URLs that are missing them.
	 * Called during indexing transition to matching phase.
	 */
	private static function process_custom_urls_needing_embedding(): void {
		$custom_urls = SL_DB::get_custom_urls_needing_embedding();

		if ( empty( $custom_urls ) ) {
			return;
		}

		SL_Debug::log( 'indexer', 'Generating embeddings for custom URLs', [
			'count' => count( $custom_urls ),
		] );

		$api = new SL_Embedding_API();

		// Batch embed all custom URLs
		$texts = [];
		foreach ( $custom_urls as $custom ) {
			$text = $custom->title;
			if ( ! empty( $custom->keywords ) ) {
				$text .= ' ' . $custom->keywords;
			}
			$texts[] = $text;
		}

		$embeddings = $api->embed( $texts );

		if ( ! $embeddings || count( $embeddings ) !== count( $custom_urls ) ) {
			SL_Debug::log( 'indexer', 'ERROR: Failed to generate custom URL embeddings', [
				'expected' => count( $custom_urls ),
				'got'      => $embeddings ? count( $embeddings ) : 0,
			] );
			return;
		}

		// Save embeddings
		foreach ( $custom_urls as $idx => $custom ) {
			SL_DB::update_custom_url_embedding( $custom->ID, $embeddings[ $idx ] );
		}

		SL_Debug::log( 'indexer', 'Custom URL embeddings generated', [
			'count' => count( $embeddings ),
		] );
	}
}
