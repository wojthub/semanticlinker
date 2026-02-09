<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin data-access layer.  All SQL lives here; the rest of the plugin
 * talks to the DB exclusively through these static helpers.
 */
class SL_DB {

	/* ═══════════════════════════════════════════════════════════════
	 * LINKS (wp_semantic_links)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Insert a new link proposal.
	 *
	 * @param array $data  Keys: post_id, anchor_text, target_url,
	 *                            target_post_id, similarity_score
	 * @return int|false   Inserted ID, or false on failure.
	 */
	public static function insert_link( array $data ) {
		global $wpdb;

		// Validate required fields
		if ( empty( $data['post_id'] ) || empty( $data['anchor_text'] ) || empty( $data['target_url'] ) ) {
			return false;
		}

		// Sanitize inputs
		$post_id          = absint( $data['post_id'] );
		$anchor_text      = sanitize_text_field( $data['anchor_text'] );
		$target_url       = esc_url_raw( $data['target_url'] );
		$target_post_id   = absint( $data['target_post_id'] ?? 0 );
		$similarity_score = floatval( $data['similarity_score'] ?? 0 );

		// Ensure URL is valid
		if ( empty( $target_url ) ) {
			return false;
		}

		// Allow custom status (default: active)
		$status = sanitize_text_field( $data['status'] ?? 'active' );
		$allowed_statuses = [ 'active', 'rejected', 'filtered' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'active';
		}

		$ok = $wpdb->insert(
			$wpdb->prefix . 'semantic_links',
			[
				'post_id'          => $post_id,
				'anchor_text'      => $anchor_text,
				'target_url'       => $target_url,
				'target_post_id'   => $target_post_id,
				'similarity_score' => $similarity_score,
				'status'           => $status,
			],
			[ '%d', '%s', '%s', '%d', '%f', '%s' ]
		);

		if ( $ok ) {
			// Trigger cache invalidation for this post's injected content
			do_action( 'sl_link_changed', $post_id );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * All active links for a single post (used by the injector).
	 *
	 * @return object[]
	 */
	public static function get_links_for_post( int $post_id, string $status = 'active' ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND status = %s
				 ORDER BY similarity_score DESC",
				$post_id,
				$status
			)
		);
	}

	/**
	 * All links (optionally filtered by status), with source title
	 * joined from wp_posts.  Used by the admin dashboard.
	 * Excludes links where source or target post is trashed/deleted.
	 *
	 * @return object[]
	 */
	public static function get_all_links( string $status = '' ): array {
		global $wpdb;
		// Join source post (required), target post is optional (may be external URL)
		// Show links where source is published AND (target is published OR target_post_id is 0)
		$q = "SELECT sl.*, p.post_title AS source_title
		      FROM {$wpdb->prefix}semantic_links sl
		      LEFT JOIN {$wpdb->prefix}posts p ON sl.post_id = p.ID
		      LEFT JOIN {$wpdb->prefix}posts p2 ON sl.target_post_id = p2.ID
		      WHERE p.post_status = 'publish'
		        AND (sl.target_post_id = 0 OR p2.post_status = 'publish')";
		if ( $status !== '' ) {
			$q .= $wpdb->prepare( " AND sl.status = %s", $status );
		}
		$q .= " ORDER BY sl.created_at DESC";
		return $wpdb->get_results( $q );
	}

	/**
	 * Single link row by ID.
	 *
	 * @return object|null
	 */
	public static function get_link( int $link_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_links WHERE ID = %d",
				$link_id
			)
		);
	}

	/**
	 * Soft status change (active → rejected/filtered or vice versa).
	 * Only allows 'active', 'rejected', and 'filtered' statuses for security.
	 */
	public static function update_link_status( int $link_id, string $status ): bool {
		// Whitelist allowed statuses
		$allowed = [ 'active', 'rejected', 'filtered' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'semantic_links',
			[ 'status' => $status ],
			[ 'ID'     => $link_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Per-post deduplication check: does an *active* link to this URL
	 * already exist in this post?
	 */
	public static function link_exists_for_post( int $post_id, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND target_url = %s AND status = 'active'
				 LIMIT 1",
				$post_id,
				$target_url
			)
		);
	}

	/**
	 * Check if this anchor text is already used for a DIFFERENT URL in this post.
	 * Ensures one anchor context maps to exactly one URL.
	 */
	public static function anchor_used_for_different_url( int $post_id, string $anchor_text, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND anchor_text = %s AND target_url != %s AND status = 'active'
				 LIMIT 1",
				$post_id,
				$anchor_text,
				$target_url
			)
		);
	}

	/**
	 * Check if this anchor text is already used GLOBALLY (across all posts) for a DIFFERENT URL.
	 * Ensures one anchor = one URL across the entire site.
	 */
	public static function anchor_used_globally_for_different_url( string $anchor_text, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links
				 WHERE anchor_text = %s AND target_url != %s AND status = 'active'
				 LIMIT 1",
				$anchor_text,
				$target_url
			)
		);
	}

	/**
	 * Get all unique active anchors with their target URLs (for global deduplication).
	 * Returns array of objects with anchor_text and target_url.
	 *
	 * @return object[]
	 */
	public static function get_all_active_anchors(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT DISTINCT anchor_text, target_url FROM {$wpdb->prefix}semantic_links
			 WHERE status = 'active'"
		);
	}

	/** How many active links does this post currently have? */
	public static function get_active_link_count( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links
				 WHERE post_id = %d AND status = 'active'",
				$post_id
			)
		);
	}

	/**
	 * Delete all links where this post is the SOURCE.
	 */
	public static function delete_links_by_source( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete all links where this post is the TARGET.
	 */
	public static function delete_links_by_target( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links WHERE target_post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete ALL links (used by "Delete all" admin action).
	 * Returns number of deleted rows.
	 */
	public static function delete_all_links(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_links" );
	}

	/**
	 * Delete blacklist entries for a post (source).
	 */
	public static function delete_blacklist_by_post( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links_blacklist WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Delete ALL blacklist entries.
	 */
	public static function delete_all_blacklist(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_links_blacklist" );
	}

	/* ═══════════════════════════════════════════════════════════════
	 * BLACKLIST (wp_semantic_links_blacklist)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Add a (post, target URL) pair to the permanent blacklist.
	 * Silently ignores duplicates.
	 *
	 * Note: The blacklist works at URL level, not anchor level.
	 * This means if you reject "kredyt hipoteczny" → URL A,
	 * then "kredyty hipoteczne" → URL A is also blocked.
	 * The anchor_text is stored for debugging/reference only.
	 *
	 * @param int    $post_id     Source post ID
	 * @param string $anchor_text Anchor text (stored as metadata, not used in check)
	 * @param string $target_url  Target URL to blacklist
	 */
	public static function add_to_blacklist( int $post_id, string $anchor_text, string $target_url ): void {
		global $wpdb;

		// Sanitize inputs
		$post_id     = absint( $post_id );
		$anchor_text = sanitize_text_field( $anchor_text );
		$target_url  = esc_url_raw( $target_url );

		if ( $post_id < 1 || empty( $target_url ) ) {
			return;
		}

		// Check at URL level - anchor is ignored for deduplication
		$already = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s LIMIT 1",
				$post_id,
				$target_url
			)
		);
		if ( $already ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'semantic_links_blacklist',
			[
				'post_id'     => $post_id,
				'anchor_text' => $anchor_text,  // Stored for reference/debugging
				'target_url'  => $target_url,
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Check if a (post, URL) pair is blacklisted.
	 *
	 * Note: Check is at URL level only. Anchor text is not considered.
	 * This means rejecting ANY anchor to a URL blocks ALL anchors to that URL.
	 *
	 * @param int    $post_id    Source post ID
	 * @param string $target_url Target URL to check
	 * @return bool  True if blacklisted
	 */
	public static function is_blacklisted( int $post_id, string $target_url ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s LIMIT 1",
				$post_id,
				$target_url
			)
		);
	}

	/**
	 * Remove a specific entry from the blacklist (for restoring links).
	 */
	public static function remove_from_blacklist( int $post_id, string $target_url ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_links_blacklist
				 WHERE post_id = %d AND target_url = %s",
				$post_id,
				$target_url
			)
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	 * EMBEDDINGS (wp_semantic_embeddings)
	 * ═══════════════════════════════════════════════════════════════ */

	/**
	 * Write one embedding row.  Removes any previous row with the
	 * same (post_id, chunk_index) first.
	 *
	 * @param int    $post_id
	 * @param int    $chunk_index
	 * @param string $chunk_text
	 * @param array  $embedding
	 * @param string $content_hash
	 * @return bool  True on success, false on failure.
	 */
	public static function upsert_embedding( int $post_id, int $chunk_index, string $chunk_text, array $embedding, string $content_hash ): bool {
		global $wpdb;

		// Delete existing row first (if any)
		$delete_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d AND chunk_index = %d",
				$post_id,
				$chunk_index
			)
		);

		// Check for query error (false = error, 0 = no rows deleted is OK)
		if ( $delete_result === false ) {
			SL_Debug::log( 'db', 'ERROR: Failed to delete existing embedding', [
				'post_id'     => $post_id,
				'chunk_index' => $chunk_index,
				'db_error'    => $wpdb->last_error,
			] );
			return false;
		}

		// Insert new row
		$insert_result = $wpdb->insert(
			$wpdb->prefix . 'semantic_embeddings',
			[
				'post_id'      => $post_id,
				'chunk_index'  => $chunk_index,
				'chunk_text'   => $chunk_text,
				'embedding'    => json_encode( $embedding ),
				'content_hash' => $content_hash,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( $insert_result === false ) {
			SL_Debug::log( 'db', 'ERROR: Failed to insert embedding', [
				'post_id'     => $post_id,
				'chunk_index' => $chunk_index,
				'db_error'    => $wpdb->last_error,
			] );
			return false;
		}

		return true;
	}

	/** Delete every embedding row for a post (before re-indexing). */
	public static function delete_embeddings( int $post_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'semantic_embeddings',
			[ 'post_id' => $post_id ],
			[ '%d' ]
		);
	}

	/**
	 * All embedding rows for one post, ordered by chunk_index.
	 * JSON `embedding` column is decoded into a PHP float array.
	 *
	 * @return object[]
	 */
	public static function get_embeddings( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d ORDER BY chunk_index ASC",
				$post_id
			)
		);
		foreach ( $rows as $row ) {
			$row->embedding = json_decode( $row->embedding, true );
		}
		return $rows;
	}

	/**
	 * Title embeddings across ALL posts (chunk_index = 0).
	 * This is the "target set" for the matcher.
	 *
	 * @return object[]
	 */
	public static function get_title_embeddings(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}semantic_embeddings WHERE chunk_index = 0"
		);
		foreach ( $rows as $row ) {
			$row->embedding = json_decode( $row->embedding, true );
		}
		return $rows;
	}

	/**
	 * Quick staleness check: does a row exist for this post with the
	 * expected content_hash?  If yes the post has not changed since
	 * the last embedding run.
	 */
	public static function embeddings_are_current( int $post_id, string $content_hash ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}semantic_embeddings
				 WHERE post_id = %d AND content_hash = %s LIMIT 1",
				$post_id,
				$content_hash
			)
		);
	}

	/**
	 * Delete ALL embeddings (used by "Delete all" admin action for full reset).
	 * Returns number of deleted rows.
	 */
	public static function delete_all_embeddings(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}semantic_embeddings" );
	}
}
