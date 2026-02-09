<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level admin menu, the "Ustawienia" submenu page,
 * handles form save (with PRG redirect), and exposes static read
 * helpers used by every other class in the plugin.
 */
class SL_Settings {

	/** WordPress option key that stores the entire settings array. */
	const OPTION_KEY = 'semanticlinker_settings';

	public function __construct() {
		add_action( 'admin_init',            [ $this, 'handle_save' ] );
		add_action( 'admin_menu',            [ $this, 'add_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/* ── Menu registration ──────────────────────────────────────── */

	public function add_pages(): void {
		/* Top-level menu (icon + position) */
		add_menu_page(
			'SemanticLinker AI',
			'SemanticLinker AI',
			'manage_options',
			'semanticlinker',
			[ $this, 'render_settings' ],
			'dashicons-admin-links',
			59
		);
		/* "Ustawienia" submenu – replaces the auto-generated first submenu label */
		add_submenu_page(
			'semanticlinker',
			'Ustawienia – SemanticLinker AI',
			'Ustawienia',
			'manage_options',
			'semanticlinker',
			[ $this, 'render_settings' ]
		);
	}

	/* ── Assets (shared by Settings + Dashboard) ───────────────── */

	public function enqueue_assets( string $hook ): void {
		// Load on all SemanticLinker pages
		if ( strpos( $hook, 'semanticlinker' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'sl-admin',
			SL_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SL_VERSION . '.' . time()  // bust cache
		);
		wp_enqueue_script(
			'sl-admin',
			SL_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			SL_VERSION . '.' . time(),  // bust cache
			true  // in_footer
		);
		wp_localize_script( 'sl-admin', 'slAjax', [
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sl_ajax_nonce' ),
		] );
	}

	/* ── Render ─────────────────────────────────────────────────── */

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		require_once SL_PLUGIN_DIR . 'templates/settings.php';
	}

	/* ── Save (PRG pattern – called on admin_init, before output) ─ */

	public function handle_save(): void {
		if ( ! isset( $_POST['sl_save'] ) ) {
			return;
		}
		/* nonce check + capability */
		check_admin_referer( 'sl_settings_save', 'sl_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}

		$settings = self::sanitize( $_POST );
		update_option( self::OPTION_KEY, $settings );

		/* Update cron schedule AFTER settings are persisted */
		self::update_cron_schedule( $settings['cron_enabled'] );

		/* Signal for the template, then redirect (PRG) */
		set_transient( 'sl_settings_saved', true, 30 );
		wp_redirect( admin_url( 'admin.php?page=semanticlinker' ) );
		exit;
	}

	/* ── Sanitisation ───────────────────────────────────────────── */

	/**
	 * Sanitise raw POST/input into the canonical settings array.
	 *
	 * @param array $input  Typically $_POST
	 * @return array
	 */
	public static function sanitize( array $input ): array {
		$s = [];

		/* API key – encrypt before storing for security */
		$raw_key = sanitize_text_field( $input['api_key'] ?? '' );
		$s['api_key'] = ! empty( $raw_key ) ? SL_Security::encrypt_api_key( $raw_key ) : '';

		/* Embedding model – free text input with default */
		$model = sanitize_text_field( $input['embedding_model'] ?? '' );
		$s['embedding_model'] = ! empty( $model ) ? $model : 'gemini-embedding-001';

		/* Filter model (AI validation) – free text input with default */
		$filter_model = sanitize_text_field( $input['filter_model'] ?? '' );
		$s['filter_model'] = ! empty( $filter_model ) ? $filter_model : 'gemini-2.5-flash';

		/* Threshold – float clamped to [0.50 … 1.00] */
		$s['similarity_threshold'] = max( 0.50, min( 1.00, (float) ( $input['similarity_threshold'] ?? 0.75 ) ) );

		/* Max links – int clamped to [1 … 30] */
		$s['max_links_per_post'] = max( 1, min( 30, (int) ( $input['max_links_per_post'] ?? 10 ) ) );

		/* Anchor word count limits */
		$s['min_anchor_words'] = max( 1, min( 10, (int) ( $input['min_anchor_words'] ?? 3 ) ) );
		$s['max_anchor_words'] = max( 1, min( 15, (int) ( $input['max_anchor_words'] ?? 10 ) ) );

		/* Ensure min <= max */
		if ( $s['min_anchor_words'] > $s['max_anchor_words'] ) {
			$s['max_anchor_words'] = $s['min_anchor_words'];
		}

		/* Post types – array of sanitised strings; empty if nothing checked */
		$s['post_types'] = array_values(
			array_map( 'sanitize_text_field', $input['post_types'] ?? [] )
		);

		/* Excluded HTML tags – one per line in a <textarea> */
		$raw = is_string( $input['excluded_tags'] ?? '' )
			? explode( "\n", $input['excluded_tags'] )
			: (array) $input['excluded_tags'];

		$tags = array_filter(
			array_map(
				function ( $t ) { return preg_replace( '/[^a-z0-9]/i', '', trim( strtolower( $t ) ) ); },
				$raw
			),
			function ( $t ) { return $t !== ''; }
		);

		/* script + style must always be excluded */
		$tags[] = 'script';
		$tags[] = 'style';
		$s['excluded_tags'] = array_values( array_unique( $tags ) );

		/* Gemini anchor filter – boolean checkbox */
		$s['gemini_anchor_filter'] = ! empty( $input['gemini_anchor_filter'] );

		/* Same category only – boolean checkbox (default true) */
		$s['same_category_only'] = ! isset( $input['same_category_only'] ) || ! empty( $input['same_category_only'] );

		/* Excluded post IDs – one per line or comma-separated */
		$raw_ids = is_string( $input['excluded_post_ids'] ?? '' )
			? preg_split( '/[\s,]+/', $input['excluded_post_ids'] )
			: (array) $input['excluded_post_ids'];
		$s['excluded_post_ids'] = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );

		/* Cluster threshold – float clamped to [0.50 … 0.99] for anchor clustering */
		$s['cluster_threshold'] = max( 0.50, min( 0.99, (float) ( $input['cluster_threshold'] ?? 0.75 ) ) );

		/* Auto-indexing cron – boolean checkbox (default: disabled) */
		$s['cron_enabled'] = ! empty( $input['cron_enabled'] );

		return $s;
	}

	/* ── Cron scheduling ────────────────────────────────────────── */

	/**
	 * Update cron schedule based on setting.
	 * Clears cron when disabled, schedules when enabled.
	 *
	 * @param bool $enabled
	 */
	private static function update_cron_schedule( bool $enabled ): void {
		$hook = 'sl_run_indexing';

		if ( $enabled ) {
			// Schedule if not already scheduled
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook );
			}
		} else {
			// Clear scheduled event
			wp_clear_scheduled_hook( $hook );
		}
	}

	/* ── Read helpers ───────────────────────────────────────────── */

	/**
	 * Read a single setting value.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = get_option( self::OPTION_KEY, [] );
		return isset( $all[$key] ) ? $all[$key] : $default;
	}

	/**
	 * Get decrypted API key for use in API calls.
	 *
	 * @return string  Decrypted API key or empty string.
	 */
	public static function get_api_key(): string {
		$encrypted = self::get( 'api_key', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}
		return SL_Security::decrypt_api_key( $encrypted );
	}

	/**
	 * Full settings array merged with hard-coded defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		$defaults = [
			'api_key'              => '',
			'embedding_model'      => 'gemini-embedding-001',
			'filter_model'         => 'gemini-2.5-flash',
			'similarity_threshold' => 0.75,
			'max_links_per_post'   => 10,
			'min_anchor_words'     => 3,
			'max_anchor_words'     => 10,
			'post_types'           => [ 'post' ],
			'excluded_tags'        => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'script', 'style' ],
			'gemini_anchor_filter' => false,
			'same_category_only'   => true,
			'excluded_post_ids'    => [],
			'cluster_threshold'    => 0.75,
			'cron_enabled'         => false,
		];
		return array_merge( $defaults, get_option( self::OPTION_KEY, [] ) );
	}
}
