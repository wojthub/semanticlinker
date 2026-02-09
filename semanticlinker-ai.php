<?php
/**
 * Plugin Name: SemanticLinker AI
 * Description: Automatyzacja linkowania wewnętrznego via embeddings.
 *              Linki wstrzyknięte dynamicznie przy renderowaniu —
 *              wp_posts niemodyfikowane (non-destructive).
 * Version:     1.0.0
 * Author:      WojciechW
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       semanticlinker-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SL_VERSION',     '1.0.0' );
define( 'SL_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SL_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/* ─── Activation / Deactivation ──────────────────────────────────── */

register_activation_hook(   __FILE__, [ 'SL_Activation', 'activate' ] );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'sl_run_indexing' );
} );

/* ─── Class-map autoloader ───────────────────────────────────────── */

function sl_autoload( string $class ): void {
	$map = [
		'SL_Activation'     => SL_PLUGIN_DIR . 'includes/class-sl-activation.php',
		'SL_DB'             => SL_PLUGIN_DIR . 'includes/class-sl-db.php',
		'SL_Settings'       => SL_PLUGIN_DIR . 'includes/class-sl-settings.php',
		'SL_Dashboard'      => SL_PLUGIN_DIR . 'includes/class-sl-dashboard.php',
		'SL_Embedding_API'  => SL_PLUGIN_DIR . 'includes/class-sl-embedding-api.php',
		'SL_Indexer'        => SL_PLUGIN_DIR . 'includes/class-sl-indexer.php',
		'SL_Matcher'        => SL_PLUGIN_DIR . 'includes/class-sl-matcher.php',
		'SL_Injector'       => SL_PLUGIN_DIR . 'includes/class-sl-injector.php',
		'SL_Ajax'           => SL_PLUGIN_DIR . 'includes/class-sl-ajax.php',
		'SL_Debug'          => SL_PLUGIN_DIR . 'includes/class-sl-debug.php',
		'SL_Security'       => SL_PLUGIN_DIR . 'includes/class-sl-security.php',
	];
	if ( isset( $map[$class] ) ) {
		require_once $map[$class];
	}
}

spl_autoload_register( 'sl_autoload' );

/* ─── Bootstrap ──────────────────────────────────────────────────── */

function semanticlinker_init(): void {
	new SL_Security();  // Initialize security features first
	new SL_Settings();
	new SL_Dashboard();
	new SL_Injector();
	new SL_Indexer();
	new SL_Ajax();
}

add_action( 'plugins_loaded', 'semanticlinker_init' );

/* ─── Cleanup when post is deleted ──────────────────────────────────── */

/**
 * When a post is deleted, remove all associated data:
 *   - Links where this post is the source
 *   - Links where this post is the target
 *   - Embeddings for this post
 *   - Blacklist entries for this post
 */
function semanticlinker_on_post_delete( int $post_id ): void {
	SL_DB::delete_links_by_source( $post_id );
	SL_DB::delete_links_by_target( $post_id );
	SL_DB::delete_embeddings( $post_id );
	SL_DB::delete_blacklist_by_post( $post_id );
}

add_action( 'before_delete_post', 'semanticlinker_on_post_delete' );
