<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data
 * and the must-use loader.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array(
	'disable_plugins_per_page_settings',
	'disable_plugins_per_page_compiled',
	'disable_plugins_per_page_state',
	'disable_plugins_per_page_secret',
	'disable_plugins_per_page_recovery_key',
	'disable_plugins_per_page_info',
	'disable_plugins_per_page_report',
) as $disable_plugins_per_page_option ) {
	delete_option( $disable_plugins_per_page_option );
}

// Each page's own settings from the editor.
delete_post_meta_by_key( '_disable_plugins_per_page' );

// Measuring tokens and results that haven't expired yet.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dppp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dppp\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// The must-use loader, but only if the file is ours.
$disable_plugins_per_page_loader = WPMU_PLUGIN_DIR . '/0-disable-plugins-per-page.php';

if ( file_exists( $disable_plugins_per_page_loader ) && false !== strpos( (string) file_get_contents( $disable_plugins_per_page_loader ), '@disable-plugins-per-page-loader' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	wp_delete_file( $disable_plugins_per_page_loader );
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-disable-plugins-per-page' );
