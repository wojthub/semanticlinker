<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation.
 *   – Creates the three shadow tables.
 *   – Schedules the hourly indexing cron event.
 */
class SL_Activation {

	public static function activate(): void {
		self::create_tables();
		self::schedule_cron();
	}

	/* ── Tables ──────────────────────────────────────────────────── */

	private static function create_tables(): void {
		global $wpdb;
		$wpdb->show_errors();
		$cc = $wpdb->get_charset_collate();

		/* 1. wp_semantic_links – proposed / active / rejected links */
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}semantic_links (
				ID               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id          bigint(20) unsigned NOT NULL,
				anchor_text      varchar(500)        NOT NULL,
				target_url       varchar(2083)       NOT NULL,
				target_post_id   bigint(20) unsigned NOT NULL DEFAULT 0,
				similarity_score float               NOT NULL DEFAULT 0,
				status           varchar(20)         NOT NULL DEFAULT 'active',
				created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP
					ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (ID),
				KEY idx_post_status (post_id, status),
				KEY idx_target      (target_post_id)
			) ENGINE=InnoDB $cc;"
		);

		/* 2. wp_semantic_links_blacklist – permanently suppressed links */
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}semantic_links_blacklist (
				ID           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id      bigint(20) unsigned NOT NULL,
				anchor_text  varchar(500)        NOT NULL,
				target_url   varchar(2083)       NOT NULL,
				created_at   datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (ID),
				KEY idx_post_target (post_id, target_url(200))
			) ENGINE=InnoDB $cc;"
		);

		/* 3. wp_semantic_embeddings – cached embedding vectors */
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}semantic_embeddings (
				ID            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id       bigint(20) unsigned NOT NULL,
				chunk_index   smallint(5) unsigned NOT NULL DEFAULT 0,
				chunk_text    mediumtext          NOT NULL,
				embedding     longtext            NOT NULL,
				content_hash  char(32)            NOT NULL,
				created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY   (ID),
				KEY idx_post_chunk (post_id, chunk_index),
				KEY idx_hash       (content_hash)
			) ENGINE=InnoDB $cc;"
		);
	}

	/* ── Cron ────────────────────────────────────────────────────── */

	private static function schedule_cron(): void {
		// Only schedule if cron is enabled in settings (default: disabled)
		$cron_enabled = SL_Settings::get( 'cron_enabled', false );

		if ( $cron_enabled && ! wp_next_scheduled( 'sl_run_indexing' ) ) {
			// Schedule first run 1 hour from now (not immediately)
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sl_run_indexing' );
		} elseif ( ! $cron_enabled ) {
			// Ensure cron is cleared if disabled
			wp_clear_scheduled_hook( 'sl_run_indexing' );
		}
	}
}
