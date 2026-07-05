<?php
/**
 * Uninstall handler: remove plugin data.
 *
 * @package Headless_Mode
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'headless_wp_settings' );
delete_option( 'headless_wp_frontend_url' );
