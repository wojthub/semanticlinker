<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug helper – logs pipeline events to a WordPress option
 * for easy viewing in the admin dashboard.
 *
 * Logs are stored as a rolling buffer (max 200 entries) in
 * the option 'sl_debug_log'.
 */
class SL_Debug {

	/** Maximum number of log entries to keep. */
	private const MAX_ENTRIES = 500;

	/** Option key for the log storage. */
	private const OPTION_KEY = 'sl_debug_log';

	/**
	 * Add a log entry.
	 *
	 * @param string $context  e.g. 'indexer', 'matcher', 'api'
	 * @param string $message  Human-readable message
	 * @param array  $data     Optional structured data
	 */
	public static function log( string $context, string $message, array $data = [] ): void {
		$logs   = get_option( self::OPTION_KEY, [] );
		$logs[] = [
			'time'    => current_time( 'mysql' ),
			'context' => $context,
			'message' => $message,
			'data'    => $data,
		];

		// Keep only the last MAX_ENTRIES
		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * Get all log entries.
	 *
	 * @return array
	 */
	public static function get_logs(): array {
		return get_option( self::OPTION_KEY, [] );
	}

	/**
	 * Clear all logs.
	 */
	public static function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Check if required tables exist.
	 *
	 * @return array
	 */
	public static function check_tables(): array {
		global $wpdb;

		$tables = [
			'semantic_links'           => false,
			'semantic_links_blacklist' => false,
			'semantic_embeddings'      => false,
		];

		foreach ( array_keys( $tables ) as $table ) {
			$full_name = $wpdb->prefix . $table;
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SHOW TABLES LIKE %s", $full_name )
			);
			$tables[ $table ] = ( $exists === $full_name );
		}

		return $tables;
	}

	/**
	 * Force create tables (useful if activation didn't run).
	 */
	public static function ensure_tables(): void {
		SL_Activation::activate();
	}

	/**
	 * Get a summary of current state for debugging.
	 *
	 * @return array
	 */
	public static function get_state_summary(): array {
		global $wpdb;

		// First check if tables exist
		$tables = self::check_tables();
		$all_tables_exist = ! in_array( false, $tables, true );

		if ( ! $all_tables_exist ) {
			return [
				'error'           => 'Tabele nie istnieją w bazie danych!',
				'tables'          => $tables,
				'fix_hint'        => 'Dezaktywuj i aktywuj wtyczkę ponownie, lub kliknij "Napraw tabele".',
				'embeddings_total'       => 0,
				'posts_indexed'          => 0,
				'title_embeddings'       => 0,
				'content_chunks'         => 0,
				'active_links'           => 0,
				'rejected_links'         => 0,
				'sample_similarities'    => [],
				'settings'               => SL_Settings::all(),
				'last_indexing_run'      => get_option( 'sl_last_indexing_run', 'never' ),
			];
		}

		$embeddings_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_embeddings"
		);

		$posts_with_embeddings = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}semantic_embeddings"
		);

		$title_embeddings = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_embeddings WHERE chunk_index = 0"
		);

		$content_chunks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_embeddings WHERE chunk_index > 0"
		);

		$active_links = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links WHERE status = 'active'"
		);

		$rejected_links = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}semantic_links WHERE status = 'rejected'"
		);

		// Sample of similarity scores from embeddings
		$sample_similarities = [];
		if ( $title_embeddings >= 2 ) {
			$titles = $wpdb->get_results(
				"SELECT post_id, chunk_text, embedding FROM {$wpdb->prefix}semantic_embeddings
				 WHERE chunk_index = 0 LIMIT 5"
			);

			for ( $i = 0; $i < count( $titles ); $i++ ) {
				for ( $j = $i + 1; $j < count( $titles ); $j++ ) {
					$vec_a = json_decode( $titles[$i]->embedding, true );
					$vec_b = json_decode( $titles[$j]->embedding, true );

					if ( $vec_a && $vec_b ) {
						$score = self::cosine( $vec_a, $vec_b );
						$sample_similarities[] = [
							'post_a'      => $titles[$i]->post_id,
							'title_a'     => $titles[$i]->chunk_text,
							'post_b'      => $titles[$j]->post_id,
							'title_b'     => $titles[$j]->chunk_text,
							'similarity'  => round( $score, 4 ),
						];
					}
				}
			}
		}

		$settings = SL_Settings::all();

		// Check API key (don't expose any part of the key for security)
		$api_key_status = ! empty( $settings['api_key'] ) ? 'configured' : 'not_set';

		return [
			'embeddings_total'       => $embeddings_count,
			'posts_indexed'          => $posts_with_embeddings,
			'title_embeddings'       => $title_embeddings,
			'content_chunks'         => $content_chunks,
			'active_links'           => $active_links,
			'rejected_links'         => $rejected_links,
			'sample_similarities'    => $sample_similarities,
			'settings'               => $settings,
			'api_key_status'         => $api_key_status,
			'last_indexing_run'      => get_option( 'sl_last_indexing_run', 'never' ),
			'tables'                 => $tables,
		];
	}

	/**
	 * Cosine similarity (copied from SL_Matcher for independence).
	 */
	private static function cosine( array $a, array $b ): float {
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

		if ( $mag_a <= 0.0 || $mag_b <= 0.0 ) {
			return 0.0;
		}

		$denom = sqrt( $mag_a ) * sqrt( $mag_b );
		return $dot / $denom;
	}
}
