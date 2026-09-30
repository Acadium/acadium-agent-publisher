<?php
/**
 * Acadium Agent Publisher uninstall: remove the plugin's role, settings,
 * activity log, OAuth table (all connections end) and .htaccess rule.
 *
 * Users that had the role are left in place with no role (WordPress keeps
 * their posts); reassign or delete them under Users. Posts and media the
 * agent created are site content and are not touched.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The Authorization header rule, if the setup screen added one.
require_once __DIR__ . '/includes/class-setup.php';
Agent_Publisher_Setup::remove_htaccess_block();

remove_role( 'agent_publisher_agent' );
delete_option( 'agent_publisher_role_version' );
delete_option( 'agent_publisher_settings' );
delete_option( 'agent_publisher_log' );
delete_option( 'agent_publisher_oauth_db' );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'agent_publisher_oauth' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
