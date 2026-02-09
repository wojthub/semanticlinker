<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Active Links" submenu page under the SemanticLinker
 * top-level menu.  Rendering is delegated to a template file.
 */
class SL_Dashboard {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'semanticlinker',
			'Active Links – SemanticLinker AI',
			'Active Links',
			'manage_options',
			'semanticlinker-dashboard',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		require_once SL_PLUGIN_DIR . 'templates/dashboard.php';
	}
}
