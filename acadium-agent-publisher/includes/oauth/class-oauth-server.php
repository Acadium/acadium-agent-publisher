<?php
/**
 * OAuth 2.1 authorization server for MCP clients (e.g. claude.ai connectors
 * and the Claude mobile apps), following the MCP authorization spec:
 *
 *   /.well-known/oauth-protected-resource      RFC 9728 (points to us)
 *   /.well-known/oauth-authorization-server    RFC 8414
 *   /agent-publisher-oauth/register            RFC 7591 dynamic client registration
 *   /agent-publisher-oauth/authorize           authorization code + PKCE (S256)
 *   /agent-publisher-oauth/token               code and refresh_token grants
 *   /agent-publisher-oauth/revoke              RFC 7009
 *
 * The endpoints are served before WordPress' query parsing rather than
 * through the REST API, so plugins that block anonymous REST requests don't
 * break the flow (a client must reach them before it has a token).
 *
 * Access tokens authenticate only requests to the MCP and Abilities REST
 * routes, and always act as an "AI Agent" user chosen by a site
 * administrator on the consent screen, never as the administrator.
 *
 * Off unless "Allow OAuth connections" is enabled under Settings > Agent
 * Publisher.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_OAuth_Server {

	const PREFIX = 'agent-publisher-oauth';

	public static function init() {
		add_action( 'plugins_loaded', array( 'Agent_Publisher_OAuth_Store', 'maybe_install' ) );
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'deleted_user', array( 'Agent_Publisher_OAuth_Store', 'revoke_user' ) );
	}

	public static function enabled() {
		return (bool) Agent_Publisher_Policy::settings()['oauth_enabled'];
	}

	/* ------------------------------------------------------------------ */
	/* URLs                                                                */
	/* ------------------------------------------------------------------ */

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function endpoint( $name ) {
		return home_url( '/' . self::PREFIX . '/' . $name );
	}

	/** The protected resource: the MCP Adapter's default server. */
	public static function resource() {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	public static function resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	/* ------------------------------------------------------------------ */
	/* Routing                                                             */
	/* ------------------------------------------------------------------ */

	public static function route( WP $wp ) {
		if ( ! self::enabled() ) {
			return;
		}
		$path = trim( (string) $wp->request, '/' );

		// RFC 9728 / 8414 also allow a path suffix (the resource's or issuer's path).
		if ( preg_match( '#^\.well-known/oauth-protected-resource(/.*)?$#', $path ) ) {
			self::json( self::protected_resource_metadata() );
		}
		if ( preg_match( '#^\.well-known/(oauth-authorization-server|openid-configuration)(/.*)?$#', $path ) ) {
			self::json( self::authorization_server_metadata() );
		}
		if ( 0 !== strpos( $path, self::PREFIX . '/' ) ) {
			return;
		}
		switch ( substr( $path, strlen( self::PREFIX ) + 1 ) ) {
			case 'register':
				self::register();
				break;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Metadata                                                            */
	/* ------------------------------------------------------------------ */

	public static function protected_resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	public static function authorization_server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'registration_endpoint'                 => self::endpoint( 'register' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Dynamic client registration (RFC 7591)                              */
	/* ------------------------------------------------------------------ */

	private static function register() {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		self::rate_limit( 'register', 20, HOUR_IN_SECONDS );

		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			self::oauth_error( 'invalid_client_metadata', 'Request body must be a JSON object.' );
		}

		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		if ( ! $uris || count( $uris ) > 10 ) {
			self::oauth_error( 'invalid_redirect_uri', 'Provide 1 to 10 redirect_uris.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::redirect_uri_allowed( $uri ) ) {
				self::oauth_error( 'invalid_redirect_uri', 'Redirect URIs must be https (or http on localhost) without a fragment.' );
			}
		}

		$method = isset( $body['token_endpoint_auth_method'] ) ? (string) $body['token_endpoint_auth_method'] : 'none';
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			self::oauth_error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}
		$grants = isset( $body['grant_types'] ) && is_array( $body['grant_types'] ) ? $body['grant_types'] : array( 'authorization_code', 'refresh_token' );
		if ( array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
			self::oauth_error( 'invalid_client_metadata', 'Only the authorization_code and refresh_token grants are supported.' );
		}

		$name   = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';
		$name   = '' !== $name ? substr( $name, 0, 100 ) : wp_parse_url( $uris[0], PHP_URL_HOST );
		$client = Agent_Publisher_OAuth_Store::create_client( $name, $uris, $method );

		$response = array(
			'client_id'                  => $client['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => array_values( $uris ),
			'grant_types'                => array_values( $grants ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);
		if ( $client['client_secret'] ) {
			$response['client_secret']            = $client['client_secret'];
			$response['client_secret_expires_at'] = 0;
		}
		self::json( $response, 201 );
	}

	public static function redirect_uri_allowed( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 2000 || false !== strpos( $uri, '#' ) ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		return 'http' === $parts['scheme'] && in_array( $parts['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* HTTP helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/** Send JSON and stop. Metadata and token responses are never cached. */
	public static function json( $data, $status = 200 ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function oauth_error( $error, $description = '', $status = 400 ) {
		self::json( array_filter( array( 'error' => $error, 'error_description' => $description ) ), $status );
	}

	/** Simple per-IP throttle for unauthenticated endpoints. */
	private static function rate_limit( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'agent_publisher_rl_' . md5( $bucket . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			header( 'Retry-After: ' . (int) $window );
			self::oauth_error( 'slow_down', 'Too many requests. Try again later.', 429 );
		}
		set_transient( $key, $n + 1, $window );
	}
}
