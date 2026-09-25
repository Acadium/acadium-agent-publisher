<?php
/**
 * The plugin's own MCP server: /wp-json/acadium-agent-publisher/mcp
 *
 * Lists the plugin's abilities directly as MCP tools (create-draft-post,
 * publish-post, …), which AI clients pick up more reliably than the MCP
 * Adapter default server's generic discover / get-info / execute tools. The
 * default server (/wp-json/mcp/mcp-adapter-default-server) keeps working
 * for existing connections.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_MCP_Server {

	const SERVER_ID = 'acadium-agent-publisher';
	const NS        = 'acadium-agent-publisher';
	const ROUTE     = 'mcp';

	public static function init() {
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register' ) );
	}

	/** Recommended connection URL. */
	public static function url() {
		return rest_url( self::NS . '/' . self::ROUTE );
	}

	/** The MCP Adapter's default server, still supported. */
	public static function default_server_url() {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/** Names of this plugin's abilities, in registration order. */
	public static function ability_names() {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), Agent_Publisher_Abilities::CATEGORY . '/' ) ) {
				$names[] = $ability->get_name();
			}
		}
		return $names;
	}

	/**
	 * @param \WP\MCP\Core\McpAdapter $adapter
	 */
	public static function register( $adapter ) {
		$adapter->create_server(
			self::SERVER_ID,
			self::NS,
			self::ROUTE,
			'Acadium Agent Publisher',
			'Draft, review, publish and update posts on this WordPress site, upload images and look up categories and tags, within the rules the site owner set. Call get-capabilities first to see what this site allows.',
			AGENT_PUBLISHER_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			self::ability_names()
		);
	}
}
