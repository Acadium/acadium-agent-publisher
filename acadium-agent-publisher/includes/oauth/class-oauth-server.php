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
			case 'authorize':
				self::authorize();
				break;
			case 'token':
				self::token();
				break;
			case 'revoke':
				self::revoke();
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
	/* Authorization endpoint (consent screen)                             */
	/* ------------------------------------------------------------------ */

	// Parameters arrive from the OAuth client, not from a WordPress form, so
	// there is no nonce on the initial GET; the approval POST carries one.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	private static function param( $key ) {
		$src = 'POST' === self::method() ? $_POST : $_GET;
		return isset( $src[ $key ] ) && is_string( $src[ $key ] ) ? sanitize_text_field( wp_unslash( $src[ $key ] ) ) : '';
	}

	private static function raw_param( $key ) {
		$src = 'POST' === self::method() ? $_POST : $_GET;
		return isset( $src[ $key ] ) && is_string( $src[ $key ] ) ? trim( wp_unslash( $src[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- redirect_uri/state are compared or passed back verbatim, never output unescaped.
	}

	private static function authorize() {
		$client_id    = self::param( 'client_id' );
		$redirect_uri = self::raw_param( 'redirect_uri' );
		$client       = Agent_Publisher_OAuth_Store::get_client( $client_id );

		// Never redirect to an unverified URI: show the error instead.
		if ( ! $client ) {
			self::page_error( __( 'This app is not registered with this site. Try connecting again from the app.', 'acadium-agent-publisher' ) );
		}
		if ( ! in_array( $redirect_uri, (array) $client['data']['redirect_uris'], true ) ) {
			self::page_error( __( 'The app sent a redirect address that does not match its registration.', 'acadium-agent-publisher' ) );
		}

		$state = self::raw_param( 'state' );
		if ( 'code' !== self::param( 'response_type' ) ) {
			self::redirect_error( $redirect_uri, 'unsupported_response_type', 'Only response_type=code is supported.', $state );
		}
		$challenge = self::param( 'code_challenge' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) || 'S256' !== self::param( 'code_challenge_method' ) ) {
			self::redirect_error( $redirect_uri, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', $state );
		}
		$resource = self::raw_param( 'resource' );
		if ( '' !== $resource && ! self::resource_ok( $resource ) ) {
			self::redirect_error( $redirect_uri, 'invalid_target', 'Unknown resource.', $state );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::page_error( __( 'Only a site administrator can approve a connection. Log in as an administrator and try again.', 'acadium-agent-publisher' ) );
		}

		$agents = get_users( array( 'role' => Agent_Publisher_Policy::ROLE, 'orderby' => 'display_name' ) );

		if ( 'POST' === self::method() ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agent_publisher_authorize_' . $client_id ) ) {
				self::page_error( __( 'This approval form has expired. Start the connection again from the app.', 'acadium-agent-publisher' ) );
			}
			if ( 'allow' !== self::param( 'decision' ) ) {
				self::redirect_error( $redirect_uri, 'access_denied', 'The administrator denied the request.', $state );
			}
			$agent = get_userdata( absint( self::param( 'agent_user' ) ) );
			if ( ! $agent || ! in_array( Agent_Publisher_Policy::ROLE, (array) $agent->roles, true ) ) {
				self::page_error( __( 'Choose a user with the AI Agent role.', 'acadium-agent-publisher' ) );
			}
			$code = Agent_Publisher_OAuth_Store::create_code( $client_id, $agent->ID, array(
				'redirect_uri' => $redirect_uri,
				'challenge'    => $challenge,
				'resource'     => '' !== $resource ? $resource : self::resource(),
				'approved_by'  => get_current_user_id(),
			) );
			self::redirect_to_client( $redirect_uri, array( 'code' => $code, 'state' => $state, 'iss' => self::issuer() ) );
		}

		self::consent_page( $client, $redirect_uri, $agents );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	private static function resource_ok( $resource ) {
		$resource = untrailingslashit( $resource );
		return in_array( $resource, array( untrailingslashit( self::resource() ), self::issuer(), untrailingslashit( rest_url() ) ), true );
	}

	/** This authorize request, rebuilt from its (string) query parameters, for the login redirect. */
	private static function current_url() {
		$args = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope' ) as $key ) {
			$value = self::raw_param( $key );
			if ( '' !== $value ) {
				$args[ $key ] = rawurlencode( $value );
			}
		}
		return add_query_arg( $args, self::endpoint( 'authorize' ) );
	}

	private static function redirect_error( $redirect_uri, $error, $description, $state ) {
		self::redirect_to_client( $redirect_uri, array( 'error' => $error, 'error_description' => $description, 'state' => $state, 'iss' => self::issuer() ) );
	}

	/** Redirect to the client's registered redirect URI (external by design). */
	private static function redirect_to_client( $redirect_uri, array $args ) {
		$args = array_filter( $args, function ( $v ) {
			return '' !== $v && null !== $v;
		} );
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ), 302, 'Acadium Agent Publisher' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri was matched exactly against the client's registered URIs.
		exit;
	}

	private static function page_headers() {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
	}

	private static function page_open( $title ) {
		self::page_headers();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $title ); ?></title>
<style>
body{margin:0;background:#f0f0f1;color:#1d2327;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.card{max-width:28rem;margin:8vh auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px}
h1{font-size:20px;margin:0 0 12px}.muted{color:#50575e;font-size:13px}.warn{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;margin:14px 0}
ul{padding-left:20px}select{width:100%;padding:6px;font-size:15px;margin:6px 0 14px}
.buttons{display:flex;gap:10px;margin-top:18px}button{flex:1;padding:10px;font-size:15px;border-radius:4px;border:1px solid #2271b1;cursor:pointer}
.allow{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}code{word-break:break-all}
</style>
</head>
<body><div class="card">
		<?php
	}

	private static function page_close() {
		echo '</div></body></html>';
		exit;
	}

	private static function page_error( $message ) {
		self::page_open( __( 'Connection not possible', 'acadium-agent-publisher' ) );
		echo '<h1>' . esc_html__( 'Connection not possible', 'acadium-agent-publisher' ) . '</h1><p>' . esc_html( $message ) . '</p>';
		self::page_close();
	}

	private static function consent_page( array $client, $redirect_uri, array $agents ) {
		$labels = Agent_Publisher_Policy::mode_labels();
		$mode   = Agent_Publisher_Policy::mode();
		$host   = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		/* translators: 1: app name, 2: site name. */
		self::page_open( sprintf( __( 'Connect %1$s to %2$s', 'acadium-agent-publisher' ), $client['data']['name'], get_bloginfo( 'name' ) ) );
		?>
		<h1>
			<?php
			/* translators: 1: app name, 2: site name. */
			echo esc_html( sprintf( __( 'Allow %1$s to work on %2$s?', 'acadium-agent-publisher' ), $client['data']['name'], get_bloginfo( 'name' ) ) );
			?>
		</h1>
		<p class="muted">
			<?php
			/* translators: %s: host name the app redirects to, e.g. claude.ai */
			echo esc_html( sprintf( __( 'After approval you return to %s.', 'acadium-agent-publisher' ), $host ) );
			?>
		</p>
		<?php if ( ! $agents ) : ?>
			<div class="warn"><?php esc_html_e( 'There is no user with the AI Agent role yet. Create one under Users > Add New User, then connect again.', 'acadium-agent-publisher' ); ?></div>
			<?php self::page_close(); ?>
		<?php endif; ?>
		<p><?php esc_html_e( 'It will act as an AI Agent user, not as you, and can:', 'acadium-agent-publisher' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'create and edit its own drafts, upload images, and read categories and tags', 'acadium-agent-publisher' ); ?></li>
			<?php if ( Agent_Publisher_Policy::allows( 'review' ) ) : ?>
				<li><?php esc_html_e( 'submit its drafts for review', 'acadium-agent-publisher' ); ?></li>
			<?php endif; ?>
			<?php if ( Agent_Publisher_Policy::allows( 'publish' ) ) : ?>
				<li><strong><?php esc_html_e( 'publish, schedule and unpublish its own posts', 'acadium-agent-publisher' ); ?></strong></li>
			<?php endif; ?>
			<?php if ( Agent_Publisher_Policy::allows( 'publish_edit' ) ) : ?>
				<li><strong><?php esc_html_e( 'change its own posts after they are live', 'acadium-agent-publisher' ); ?></strong></li>
			<?php endif; ?>
		</ul>
		<p class="muted">
			<?php
			/* translators: %s: publishing mode, e.g. "Drafts only". */
			echo esc_html( sprintf( __( 'Current mode: %s (Settings > Agent Publisher). You can disconnect it there at any time.', 'acadium-agent-publisher' ), $labels[ $mode ] ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( self::endpoint( 'authorize' ) ); ?>">
			<?php wp_nonce_field( 'agent_publisher_authorize_' . $client['client_id'] ); ?>
			<?php foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope' ) as $field ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( self::raw_param( $field ) ); ?>" />
			<?php endforeach; ?>
			<label for="agent_user"><strong><?php esc_html_e( 'Act as', 'acadium-agent-publisher' ); ?></strong></label>
			<select id="agent_user" name="agent_user">
				<?php foreach ( $agents as $agent ) : ?>
					<option value="<?php echo esc_attr( $agent->ID ); ?>"><?php echo esc_html( $agent->display_name . ' (' . $agent->user_login . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="buttons">
				<button type="submit" name="decision" value="deny" class="deny"><?php esc_html_e( 'Deny', 'acadium-agent-publisher' ); ?></button>
				<button type="submit" name="decision" value="allow" class="allow"><?php esc_html_e( 'Allow', 'acadium-agent-publisher' ); ?></button>
			</div>
		</form>
		<?php
		self::page_close();
	}

	/* ------------------------------------------------------------------ */
	/* Token endpoint                                                      */
	/* ------------------------------------------------------------------ */

	// Token and revocation requests are authenticated by client credentials
	// and PKCE, not WordPress nonces.
	// phpcs:disable WordPress.Security.NonceVerification.Missing

	private static function token() {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		self::rate_limit( 'token', 120, 10 * MINUTE_IN_SECONDS );

		$client = self::authenticate_client();
		$grant  = self::param( 'grant_type' );

		if ( 'authorization_code' === $grant ) {
			$row = Agent_Publisher_OAuth_Store::consume_code( self::param( 'code' ) );
			if ( ! $row || $row['client_id'] !== $client['client_id'] ) {
				self::oauth_error( 'invalid_grant', 'The authorization code is invalid, expired or already used.' );
			}
			if ( self::raw_param( 'redirect_uri' ) !== $row['data']['redirect_uri'] ) {
				self::oauth_error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
			}
			$verifier = self::raw_param( 'code_verifier' );
			if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || ! hash_equals( $row['data']['challenge'], self::s256( $verifier ) ) ) {
				self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			$resource = self::raw_param( 'resource' );
			if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( $row['data']['resource'] ) ) {
				self::oauth_error( 'invalid_target', 'resource does not match the authorization request.' );
			}
			self::require_agent( (int) $row['user_id'] );
			$grant_id = Agent_Publisher_OAuth_Store::create_grant( $client['client_id'], (int) $row['user_id'], $row['data']['resource'], (int) $row['data']['approved_by'] );
			self::token_response( Agent_Publisher_OAuth_Store::issue_tokens( $grant_id, $client['client_id'], (int) $row['user_id'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			$row = Agent_Publisher_OAuth_Store::consume_refresh( self::param( 'refresh_token' ) );
			if ( ! $row || $row['client_id'] !== $client['client_id'] || ! Agent_Publisher_OAuth_Store::grant_exists( $row['grant_id'] ) ) {
				self::oauth_error( 'invalid_grant', 'The refresh token is invalid, expired or revoked.' );
			}
			self::require_agent( (int) $row['user_id'] );
			self::token_response( Agent_Publisher_OAuth_Store::issue_tokens( $row['grant_id'], $client['client_id'], (int) $row['user_id'] ) );
		}

		self::oauth_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.' );
	}

	private static function revoke() {
		if ( 'POST' !== self::method() ) {
			self::oauth_error( 'invalid_request', 'Use POST.', 405 );
		}
		self::rate_limit( 'token', 120, 10 * MINUTE_IN_SECONDS );
		self::authenticate_client();
		Agent_Publisher_OAuth_Store::revoke_token( self::param( 'token' ) );
		self::json( new stdClass() ); // RFC 7009: 200 whether or not the token existed.
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/** Identify the client (client_secret_basic, client_secret_post or public). */
	private static function authenticate_client() {
		$client_id = self::param( 'client_id' );
		$secret    = self::raw_param( 'client_secret' );
		$header    = self::authorization_header();
		if ( 0 === stripos( $header, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $header, 6 ), true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $client_id, $secret ) = array_map( 'rawurldecode', explode( ':', $decoded, 2 ) );
			}
		}
		$client = Agent_Publisher_OAuth_Store::get_client( $client_id );
		if ( ! $client || ! Agent_Publisher_OAuth_Store::client_secret_ok( $client, $secret ) ) {
			header( 'WWW-Authenticate: Basic realm="agent-publisher"' );
			self::oauth_error( 'invalid_client', 'Unknown client or bad client credentials.', 401 );
		}
		return $client;
	}

	private static function require_agent( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( Agent_Publisher_Policy::ROLE, (array) $user->roles, true ) ) {
			self::oauth_error( 'invalid_grant', 'The connected user no longer has the AI Agent role.' );
		}
	}

	private static function token_response( array $tokens ) {
		self::json( array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
		) );
	}

	public static function s256( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public static function authorization_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			}
		}
		return '';
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
