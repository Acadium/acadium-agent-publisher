<?php
/**
 * Plugin Name:       Acadium Agent Publisher
 * Plugin URI:        https://github.com/Acadium/acadium-agent-publisher
 * Description:       Let Claude and other AI agents draft, review and publish posts, upload images and look up categories and tags, within rules you set. Works with the WordPress Abilities API and the MCP Adapter.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Acadium
 * Author URI:        https://acadium.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acadium-agent-publisher
 *
 * Lets AI agents (e.g. Claude through the MCP Adapter plugin) work on posts
 * through the WordPress Abilities API, within rules the site owner sets under
 * Settings > Agent Publisher: drafts only (default), submit for review,
 * publish, or publish and edit live posts. Optional OAuth lets claude.ai
 * (web, desktop and mobile) connect without an Application Password.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENT_PUBLISHER_VERSION', '1.2.0' );
define( 'AGENT_PUBLISHER_FILE', __FILE__ );
define( 'AGENT_PUBLISHER_DIR', __DIR__ );

// Bundled MCP Adapter (github.com/WordPress/mcp-adapter) via the Jetpack
// Autoloader, which loads only the newest copy when other plugins (or the
// standalone MCP Adapter plugin) bundle it too.
if ( is_readable( __DIR__ . '/vendor/autoload_packages.php' ) ) {
	require_once __DIR__ . '/vendor/autoload_packages.php';
}

require_once __DIR__ . '/includes/class-policy.php';
require_once __DIR__ . '/includes/class-abilities.php';
require_once __DIR__ . '/includes/class-settings-page.php';
require_once __DIR__ . '/includes/oauth/class-oauth-store.php';
require_once __DIR__ . '/includes/oauth/class-oauth-server.php';

Agent_Publisher_Policy::init();
Agent_Publisher_Abilities::init();
Agent_Publisher_Settings_Page::init();
Agent_Publisher_OAuth_Server::init();

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		\WP\MCP\Core\McpAdapter::instance();
	}
} );

register_activation_hook( __FILE__, function () {
	Agent_Publisher_Policy::add_role();
	Agent_Publisher_OAuth_Store::install();
} );
