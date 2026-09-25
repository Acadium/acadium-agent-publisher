<?php
/**
 * Acadium Agent Publisher uninstall: remove the plugin's role, settings and
 * activity log.
 *
 * Users that had the role are left in place with no role (WordPress keeps
 * their posts); reassign or delete them under Users. Posts and media the
 * agent created are site content and are not touched.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

remove_role( 'agent_publisher_agent' );
delete_option( 'agent_publisher_role_version' );
delete_option( 'agent_publisher_settings' );
delete_option( 'agent_publisher_log' );
