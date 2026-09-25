<?php
/**
 * Plugin Name:       Acadium Agent Publisher
 * Plugin URI:        https://github.com/Acadium/acadium-agent-publisher
 * Description:       Let Claude and other AI agents draft, review and publish posts, upload images and look up categories and tags, within rules you set. Works with the WordPress Abilities API and the MCP Adapter.
 * Version:           1.1.0-dev
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
 * publish, or publish and edit live posts.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENT_PUBLISHER_VERSION', '1.1.0-dev' );
define( 'AGENT_PUBLISHER_FILE', __FILE__ );
define( 'AGENT_PUBLISHER_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-policy.php';
require_once __DIR__ . '/includes/class-abilities.php';

Agent_Publisher_Policy::init();
Agent_Publisher_Abilities::init();

register_activation_hook( __FILE__, array( 'Agent_Publisher_Policy', 'add_role' ) );
