<?php
/**
 * Plugin Name: OpenBB Zeffy Contact Sync
 * Description: Syncs Open Bulk Buying member contacts to Zeffy.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: open-bulk-buying
 * License: GPL-2.0-or-later
 * Text Domain: openbb-zeffy-plugin
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-openbb-zeffy-api.php';
require_once __DIR__ . '/includes/class-openbb-zeffy-sync.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'OPENBB_Member' ) ) {
			add_action(
			'admin_notices',
				static function (): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'OpenBB Zeffy Contact Sync requires the Open Bulk Buying plugin.', 'openbb-zeffy-plugin' ) . '</p></div>';
				}
				}
			);
			return;
		}

		OPENBB_Zeffy_Sync::register_hooks();
	},
	20
);
