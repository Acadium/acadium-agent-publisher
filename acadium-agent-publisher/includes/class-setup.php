<?php
/**
 * "Connect Claude" setup on Settings > Agent Publisher: one-click actions
 * (create the AI Agent user, turn on OAuth, create an Application Password,
 * fix the Authorization header on Apache) and connection checks.
 *
 * Actions post back to the settings page. Each is nonce- and
 * capability-checked in handle(), which runs on the page's load-* hook
 * before any output. The Application Password is shown once in the same
 * request and never stored.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_Setup {

	const CHECKS_TRANSIENT = 'agent_publisher_checks';
	const HTACCESS_MARKER  = 'Acadium Agent Publisher';
	const SELFTEST_USER    = 'agent-publisher-selftest';
	const SELFTEST_TOKEN   = 'agent_publisher_selftest';
	const SELFTEST_SEEN    = 'agent_publisher_selftest_seen';

	/** Set when an Application Password was just created: array( 'user' => login, 'password' => string ). */
	private static $new_password = null;

	/** @var WP_Error|null Error from an action in this request. */
	private static $error = null;

	public static function init() {
		add_action( 'load-settings_page_' . Agent_Publisher_Settings_Page::SLUG, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'record_selftest' ) );
		// Setting changes that affect the checks.
		add_action( 'update_option_' . Agent_Publisher_Policy::OPTION, array( __CLASS__, 'forget_checks' ) );
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'forget_checks' ) );
	}

	public static function forget_checks() {
		delete_transient( self::CHECKS_TRANSIENT );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle() {
		if ( empty( $_POST['agent_publisher_setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'acadium-agent-publisher' ), 403 );
		}
		$action = sanitize_key( wp_unslash( $_POST['agent_publisher_setup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked on the next line.
		check_admin_referer( 'agent_publisher_setup_' . $action );

		switch ( $action ) {
			case 'create_agent':
				$result = self::create_agent();
				break;
			case 'enable_oauth':
				$settings                  = Agent_Publisher_Policy::settings();
				$settings['oauth_enabled'] = true;
				update_option( Agent_Publisher_Policy::OPTION, Agent_Publisher_Policy::sanitize( $settings ) );
				$result = 'oauth_enabled';
				break;
			case 'app_password':
				$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
				$result  = self::create_app_password( $user_id );
				if ( ! is_wp_error( $result ) ) {
					return; // Rendered once in this request; no redirect, nothing stored.
				}
				break;
			case 'fix_auth_header':
				$result = self::fix_auth_header();
				break;
			case 'recheck':
				$result = 'rechecked';
				break;
			default:
				return;
		}

		if ( is_wp_error( $result ) ) {
			self::$error = $result;
			return;
		}
		self::forget_checks();
		wp_safe_redirect( add_query_arg( 'agent-publisher-done', $result, self::page_url() ) );
		exit;
	}

	/** Create a user named "claude" (or claude-2, …) with only the AI Agent role. */
	private static function create_agent() {
		$login = 'claude';
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = 'claude-' . $i;
		}
		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => 'Claude',
			'nickname'     => 'Claude',
			'first_name'   => 'Claude',
			'role'         => Agent_Publisher_Policy::ROLE,
		) );
		return is_wp_error( $user_id ) ? $user_id : 'agent_created';
	}

	private static function create_app_password( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( Agent_Publisher_Policy::ROLE, (array) $user->roles, true ) ) {
			return new WP_Error( 'agent_publisher_not_agent', __( 'Choose a user with the AI Agent role.', 'acadium-agent-publisher' ) );
		}
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'agent_publisher_no_app_passwords', __( 'Application Passwords are not available on this site. They need HTTPS, and a security plugin or your host may have turned them off.', 'acadium-agent-publisher' ) );
		}
		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => sprintf( 'Claude Desktop (%s)', wp_date( 'Y-m-d' ) ) )
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		self::$new_password = array(
			'user'     => $user->user_login,
			'password' => WP_Application_Passwords::chunk_password( $created[0] ),
		);
		return 'app_password';
	}

	/** Apache only: pass the Authorization header to PHP via .htaccess. */
	private static function fix_auth_header() {
		$file = self::htaccess_file();
		if ( ! $file ) {
			return new WP_Error( 'agent_publisher_htaccess', __( 'This fix only works on Apache with a writable .htaccess file. Ask your host to pass the Authorization header to PHP.', 'acadium-agent-publisher' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$ok = insert_with_markers( $file, self::HTACCESS_MARKER, array(
			'<IfModule mod_setenvif.c>',
			'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
			'</IfModule>',
		) );
		return $ok ? 'auth_header_fixed' : new WP_Error( 'agent_publisher_htaccess', __( 'Could not write .htaccess.', 'acadium-agent-publisher' ) );
	}

	/** Path of a writable .htaccess on Apache, or ''. */
	private static function htaccess_file() {
		global $is_apache;
		if ( ! $is_apache ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = get_home_path() . '.htaccess';
		return ( file_exists( $file ) ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) ) ) ? $file : '';
	}

	/** Remove the .htaccess block (uninstall). */
	public static function remove_htaccess_block() {
		$file = self::htaccess_file();
		if ( $file && file_exists( $file ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			insert_with_markers( $file, self::HTACCESS_MARKER, array() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Checks                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array[] Each: id, status (ok|warn|fail|unknown), label, detail, fix (setup action or '').
	 */
	public static function checks() {
		$cached = get_transient( self::CHECKS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$checks = array();
		$oauth  = Agent_Publisher_OAuth_Server::enabled();

		$https    = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$checks[] = array(
			'id'     => 'https',
			'status' => $https ? 'ok' : 'fail',
			'label'  => __( 'Site uses HTTPS', 'acadium-agent-publisher' ),
			'detail' => $https ? '' : __( 'Claude connects only over HTTPS, and WordPress turns off Application Passwords without it. Set up HTTPS with your host, then update the addresses under Settings > General.', 'acadium-agent-publisher' ),
			'fix'    => '',
		);

		$pretty   = Agent_Publisher_Settings_Page::has_pretty_permalinks();
		$checks[] = array(
			'id'     => 'permalinks',
			'status' => $pretty ? 'ok' : 'fail',
			'label'  => __( 'Pretty permalinks', 'acadium-agent-publisher' ),
			'detail' => $pretty ? '' : __( 'The connection URL needs pretty permalinks.', 'acadium-agent-publisher' ),
			'fix'    => $pretty ? '' : 'permalinks',
		);

		$app_pw   = wp_is_application_passwords_available();
		$checks[] = array(
			'id'     => 'app_passwords',
			'status' => $app_pw ? 'ok' : ( $oauth ? 'warn' : 'fail' ),
			'label'  => __( 'Application Passwords available', 'acadium-agent-publisher' ),
			'detail' => $app_pw ? '' : __( 'Needed for the Claude Desktop extension, not for the connector. They need HTTPS; a security plugin or your host may have turned them off.', 'acadium-agent-publisher' ),
			'fix'    => '',
		);

		// Requests from the server to itself, as Site Health does.
		$url  = Agent_Publisher_MCP_Server::url();
		$anon = self::loopback( $url );
		if ( is_wp_error( $anon ) ) {
			$checks[] = array(
				'id'     => 'endpoint',
				'status' => 'unknown',
				'label'  => __( 'Connection URL responds', 'acadium-agent-publisher' ),
				/* translators: %s: error message. */
				'detail' => sprintf( __( 'This server could not reach its own site to test it (%s). That is common on some hosts and does not mean Claude cannot connect.', 'acadium-agent-publisher' ), $anon->get_error_message() ),
				'fix'    => '',
			);
			set_transient( self::CHECKS_TRANSIENT, $checks, 10 * MINUTE_IN_SECONDS );
			return $checks;
		}

		$endpoint_ok = 401 === $anon['status'] && $anon['json'];
		if ( 404 === $anon['status'] ) {
			$detail = __( 'Not Found: the web server is not applying WordPress rewrite rules. On Apache allow .htaccess (AllowOverride All); on nginx add try_files $uri $uri/ /index.php?$args;', 'acadium-agent-publisher' );
		} elseif ( ! $anon['json'] ) {
			/* translators: %d: HTTP status code. */
			$detail = sprintf( __( 'The request was answered by something other than WordPress (HTTP %d), usually a firewall or security rule. Allow POST requests to the connection URL.', 'acadium-agent-publisher' ), $anon['status'] );
		} elseif ( ! $endpoint_ok ) {
			/* translators: %d: HTTP status code. */
			$detail = sprintf( __( 'Unexpected response (HTTP %d).', 'acadium-agent-publisher' ), $anon['status'] );
		} else {
			$detail = '';
		}
		$checks[] = array(
			'id'     => 'endpoint',
			'status' => $endpoint_ok ? 'ok' : 'fail',
			'label'  => __( 'Connection URL responds', 'acadium-agent-publisher' ),
			'detail' => $detail,
			'fix'    => '',
		);

		if ( $endpoint_ok ) {
			// Send a one-time token both ways and see which reached PHP (record_selftest()).
			$token = wp_generate_password( 32, false );
			set_transient( self::SELFTEST_TOKEN, $token, MINUTE_IN_SECONDS );
			delete_transient( self::SELFTEST_SEEN );
			self::loopback( $url, array( 'Authorization' => 'Bearer ' . self::SELFTEST_USER . '.' . $token ) );
			self::loopback( $url, array( 'Authorization' => 'Basic ' . base64_encode( self::SELFTEST_USER . ':' . $token ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
			$seen = (array) get_transient( self::SELFTEST_SEEN );
			delete_transient( self::SELFTEST_TOKEN );
			delete_transient( self::SELFTEST_SEEN );

			$bearer = ! empty( $seen['bearer'] );
			$basic  = ! empty( $seen['basic'] );
			$fix    = ! $bearer && self::htaccess_file() ? 'fix_auth_header' : '';
			$ask    = $fix ? '' : ' ' . __( 'Ask your host to pass the Authorization header to PHP (Apache: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1).', 'acadium-agent-publisher' );
			if ( $bearer ) {
				$status = 'ok';
				$detail = '';
			} elseif ( $basic ) {
				$status = $oauth ? 'fail' : 'warn';
				$detail = __( 'Application Passwords work, but the connector\'s sign-in tokens do not reach WordPress, so connecting as a connector fails with 401.', 'acadium-agent-publisher' ) . $ask;
			} else {
				$status = 'fail';
				$detail = __( 'The web server strips the Authorization header, so Claude\'s logins fail with 401.', 'acadium-agent-publisher' ) . $ask;
			}
			$checks[] = array(
				'id'     => 'auth_header',
				'status' => $status,
				'label'  => __( 'Authorization header reaches WordPress', 'acadium-agent-publisher' ),
				'detail' => $detail,
				'fix'    => $fix,
			);
		}

		if ( $oauth ) {
			$root = '' === trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
			$meta = $root ? self::loopback( Agent_Publisher_OAuth_Server::resource_metadata_url(), array(), 'GET' ) : null;
			$ok   = $root && ! is_wp_error( $meta ) && 200 === $meta['status'] && isset( $meta['body']['resource'] );
			if ( ! $root ) {
				$detail = __( 'WordPress is installed in a subfolder. The connector needs it at the root of the domain (example.com, not example.com/blog), because Claude looks for /.well-known/ there. Use the Claude Desktop extension instead.', 'acadium-agent-publisher' );
			} elseif ( ! $ok ) {
				$detail = __( 'Requests to /.well-known/oauth-protected-resource do not reach WordPress. Allow /.well-known/ and /agent-publisher-oauth/ through your web server, CDN and firewall.', 'acadium-agent-publisher' );
			} else {
				$detail = '';
			}
			$checks[] = array(
				'id'     => 'oauth_discovery',
				'status' => $ok ? 'ok' : 'fail',
				'label'  => __( 'Connector sign-in (OAuth) can be discovered', 'acadium-agent-publisher' ),
				'detail' => $detail,
				'fix'    => '',
			);
		}

		set_transient( self::CHECKS_TRANSIENT, $checks, 10 * MINUTE_IN_SECONDS );
		return $checks;
	}

	/**
	 * rest_api_init: during a connection check, note which form of the
	 * one-time token reached PHP. Other requests return after two cheap checks.
	 */
	public static function record_selftest() {
		$header = Agent_Publisher_OAuth_Server::authorization_header();
		$user   = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ) : '';
		if ( false === strpos( $header, self::SELFTEST_USER ) && self::SELFTEST_USER !== $user && 0 !== strpos( $header, 'Basic ' ) ) {
			return;
		}
		$token = get_transient( self::SELFTEST_TOKEN );
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		$seen = (array) get_transient( self::SELFTEST_SEEN );
		if ( hash_equals( 'Bearer ' . self::SELFTEST_USER . '.' . $token, $header ) ) {
			$seen['bearer'] = true;
		}
		$pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with hash_equals only.
		if ( ( self::SELFTEST_USER === $user && hash_equals( $token, $pass ) ) || hash_equals( 'Basic ' . base64_encode( self::SELFTEST_USER . ':' . $token ), $header ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
			$seen['basic'] = true;
		}
		set_transient( self::SELFTEST_SEEN, $seen, MINUTE_IN_SECONDS );
	}

	/**
	 * @return array|WP_Error status, code (JSON error code or ''), json (bool), body (decoded JSON or null).
	 */
	private static function loopback( $url, array $headers = array(), $method = 'POST' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => $headers + array(
				'Accept'       => 'application/json, text/event-stream',
				'Content-Type' => 'application/json',
			),
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopback requests, as Site Health uses.
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new stdClass(),
					'clientInfo'      => array( 'name' => 'agent-publisher-selftest', 'version' => AGENT_PUBLISHER_VERSION ),
				),
			) );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'code'   => is_array( $body ) && isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '',
			'json'   => is_array( $body ),
			'body'   => is_array( $body ) ? $body : null,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_url() {
		return admin_url( 'options-general.php?page=' . Agent_Publisher_Settings_Page::SLUG );
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . Agent_Publisher_Settings_Page::SLUG !== $hook ) {
			return;
		}
		wp_register_script( 'agent-publisher-admin', false, array(), AGENT_PUBLISHER_VERSION, true );
		wp_enqueue_script( 'agent-publisher-admin' );
		wp_add_inline_script(
			'agent-publisher-admin',
			'document.addEventListener("click",function(e){var b=e.target.closest("[data-agent-publisher-copy]");if(!b||!navigator.clipboard){return;}var t=document.getElementById(b.getAttribute("data-agent-publisher-copy"));if(!t){return;}e.preventDefault();navigator.clipboard.writeText(t.textContent.trim()).then(function(){var o=b.textContent;b.textContent=b.getAttribute("data-copied");setTimeout(function(){b.textContent=o;},1500);});});'
		);
	}

	/** A button that posts one setup action back to the settings page. */
	private static function action_button( $action, $label, $primary = true, array $fields = array() ) {
		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" style="display:inline-block;margin:0 .5em .5em 0;">
			<input type="hidden" name="agent_publisher_setup" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( 'agent_publisher_setup_' . $action ); ?>
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function copy_field( $id, $value ) {
		?>
		<code id="<?php echo esc_attr( $id ); ?>" style="font-size:13px;padding:4px 6px;"><?php echo esc_html( $value ); ?></code>
		<button type="button" class="button button-small" data-agent-publisher-copy="<?php echo esc_attr( $id ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'acadium-agent-publisher' ); ?>"><?php esc_html_e( 'Copy', 'acadium-agent-publisher' ); ?></button>
		<?php
	}

	private static function notices() {
		$messages = array(
			'agent_created'     => __( 'AI Agent user created. It has no password you need to know: Claude connects with the steps below.', 'acadium-agent-publisher' ),
			'oauth_enabled'     => __( 'OAuth connections are on. You can now add this site to Claude as a connector.', 'acadium-agent-publisher' ),
			'auth_header_fixed' => __( 'Added the Authorization header rule to .htaccess.', 'acadium-agent-publisher' ),
			'rechecked'         => __( 'Checks ran again.', 'acadium-agent-publisher' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-checked redirect.
		$done = isset( $_GET['agent-publisher-done'] ) ? sanitize_key( wp_unslash( $_GET['agent-publisher-done'] ) ) : '';
		if ( isset( $messages[ $done ] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
		if ( self::$error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::$error->get_error_message() ) . '</p></div>';
		}
	}

	/** The "Connect Claude" section at the top of the settings page. */
	public static function render() {
		$agents = get_users( array( 'role' => Agent_Publisher_Policy::ROLE, 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
		$s      = Agent_Publisher_Policy::settings();
		$url    = Agent_Publisher_MCP_Server::url();
		$labels = Agent_Publisher_Policy::mode_labels();
		?>
		<h2><?php esc_html_e( 'Connect Claude', 'acadium-agent-publisher' ); ?></h2>
		<?php self::notices(); ?>

		<ol class="agent-publisher-steps" style="max-width:60em;">
			<li>
				<p><strong><?php esc_html_e( 'A user for Claude', 'acadium-agent-publisher' ); ?></strong></p>
				<?php if ( $agents ) : ?>
					<p>
						<span class="dashicons dashicons-yes-alt" style="color:#008a20;"></span>
						<?php
						echo esc_html( implode( ', ', array_map( function ( $u ) {
							return $u->display_name . ' (' . $u->user_login . ')';
						}, $agents ) ) );
						?>
						<span class="description"><?php esc_html_e( '— role "AI Agent"', 'acadium-agent-publisher' ); ?></span>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Claude signs in as its own user with the AI Agent role, so everything it does is labelled and limited by the rules on this page.', 'acadium-agent-publisher' ); ?></p>
					<?php self::action_button( 'create_agent', __( 'Create AI Agent user', 'acadium-agent-publisher' ) ); ?>
				<?php endif; ?>
			</li>
			<li>
				<p><strong><?php esc_html_e( 'What Claude may do', 'acadium-agent-publisher' ); ?></strong></p>
				<p>
					<?php
					/* translators: %s: publishing mode, e.g. "Drafts only". */
					printf( esc_html__( 'Current mode: %s.', 'acadium-agent-publisher' ), '<strong>' . esc_html( $labels[ $s['mode'] ] ) . '</strong>' );
					?>
					<a href="#agent-publisher-mode"><?php esc_html_e( 'Change it below', 'acadium-agent-publisher' ); ?></a>
				</p>
			</li>
			<li>
				<p><strong><?php esc_html_e( 'Connect Claude Desktop', 'acadium-agent-publisher' ); ?></strong> <span class="description"><?php esc_html_e( '(also works in claude.ai and the Claude mobile apps)', 'acadium-agent-publisher' ); ?></span></p>
				<?php if ( ! $s['oauth_enabled'] ) : ?>
					<p><?php esc_html_e( 'Add this site to Claude as a connector: nothing to install and no password to copy. This needs OAuth connections turned on.', 'acadium-agent-publisher' ); ?></p>
					<?php self::action_button( 'enable_oauth', __( 'Turn on OAuth connections', 'acadium-agent-publisher' ) ); ?>
				<?php else : ?>
					<p><?php esc_html_e( 'Connection URL:', 'acadium-agent-publisher' ); ?> <?php self::copy_field( 'agent-publisher-url', $url ); ?></p>
					<ol>
						<li><?php esc_html_e( 'In Claude Desktop, open Settings > Connectors and click "Add custom connector".', 'acadium-agent-publisher' ); ?></li>
						<li><?php esc_html_e( 'Enter a name (e.g. the site name), paste the connection URL and click Add.', 'acadium-agent-publisher' ); ?></li>
						<li><?php esc_html_e( 'Click Connect. This site\'s login page opens: log in as an administrator.', 'acadium-agent-publisher' ); ?></li>
						<li><?php esc_html_e( 'Under "Act as", choose the AI Agent user and click Allow.', 'acadium-agent-publisher' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'In a chat, turn the connector on from the tools menu. On Claude Team and Enterprise plans, an owner may need to allow custom connectors first.', 'acadium-agent-publisher' ); ?></p>
				<?php endif; ?>
				<?php self::render_extension( $agents ); ?>
			</li>
		</ol>
		<?php
		self::render_checks();
	}

	/** Alternative for sites the connector can't reach: Application Password + Claude Desktop config. */
	private static function render_extension( array $agents ) {
		$open = null !== self::$new_password;
		?>
		<details <?php echo $open ? 'open' : ''; ?> style="margin-top:1em;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'Can\'t use the connector? Connect with an Application Password instead', 'acadium-agent-publisher' ); ?></summary>
			<div style="padding:.5em 0 0 1.2em;">
				<p class="description"><?php esc_html_e( 'For sites the connector cannot reach, e.g. WordPress in a subfolder or a CDN that blocks /.well-known/. Claude Desktop only.', 'acadium-agent-publisher' ); ?></p>
				<?php if ( self::$new_password ) : ?>
					<?php self::render_desktop_config( self::$new_password ); ?>
				<?php elseif ( ! $agents ) : ?>
					<p><?php esc_html_e( 'Create the AI Agent user first (step 1).', 'acadium-agent-publisher' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Create an Application Password for the AI Agent user. It is shown once.', 'acadium-agent-publisher' ); ?></p>
					<?php foreach ( $agents as $agent ) : ?>
						<?php
						/* translators: %s: user login. */
						self::action_button( 'app_password', sprintf( __( 'Create Application Password for %s', 'acadium-agent-publisher' ), $agent->user_login ), false, array( 'user' => $agent->ID ) );
						?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** Shown once, right after the password is created. */
	private static function render_desktop_config( array $creds ) {
		$config = array(
			'mcpServers' => array(
				sanitize_key( wp_parse_url( home_url(), PHP_URL_HOST ) ) => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@0.4.0' ),
					'env'     => array(
						'WP_API_URL'      => Agent_Publisher_MCP_Server::url(),
						'WP_API_USERNAME' => $creds['user'],
						'WP_API_PASSWORD' => $creds['password'],
					),
				),
			),
		);
		?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Copy this now: the password is not shown again.', 'acadium-agent-publisher' ); ?></strong> <?php esc_html_e( 'You can revoke it any time under Users > Profile > Application Passwords.', 'acadium-agent-publisher' ); ?></p></div>
		<p><?php esc_html_e( 'Application Password:', 'acadium-agent-publisher' ); ?> <?php self::copy_field( 'agent-publisher-password', $creds['password'] ); ?></p>
		<p><?php esc_html_e( 'In Claude Desktop, open Settings > Developer > Edit Config, add this to claude_desktop_config.json, save, and restart Claude Desktop. Needs Node.js; if Claude cannot find npx, replace "npx" with its full path (run "which npx" in Terminal).', 'acadium-agent-publisher' ); ?></p>
		<pre id="agent-publisher-config" style="background:#f6f7f7;padding:1em;overflow:auto;max-width:60em;"><?php echo esc_html( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		<button type="button" class="button" data-agent-publisher-copy="agent-publisher-config" data-copied="<?php esc_attr_e( 'Copied', 'acadium-agent-publisher' ); ?>"><?php esc_html_e( 'Copy', 'acadium-agent-publisher' ); ?></button>
		<?php
	}

	private static function render_checks() {
		$checks = self::checks();
		$icons  = array(
			'ok'      => array( 'yes-alt', '#008a20', __( 'OK', 'acadium-agent-publisher' ) ),
			'warn'    => array( 'warning', '#996800', __( 'Warning', 'acadium-agent-publisher' ) ),
			'fail'    => array( 'dismiss', '#d63638', __( 'Problem', 'acadium-agent-publisher' ) ),
			'unknown' => array( 'editor-help', '#646970', __( 'Not tested', 'acadium-agent-publisher' ) ),
		);
		?>
		<h2><?php esc_html_e( 'Connection checks', 'acadium-agent-publisher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Tested from this server. A CDN or firewall in front of the site can still block Claude; if connecting fails, check that it passes the connection URL, /.well-known/ and the Authorization header through.', 'acadium-agent-publisher' ); ?></p>
		<table class="widefat striped" style="max-width:60em;">
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<?php $icon = $icons[ $check['status'] ]; ?>
					<tr>
						<td style="width:2em;"><span class="dashicons dashicons-<?php echo esc_attr( $icon[0] ); ?>" style="color:<?php echo esc_attr( $icon[1] ); ?>;" title="<?php echo esc_attr( $icon[2] ); ?>"></span><span class="screen-reader-text"><?php echo esc_html( $icon[2] ); ?></span></td>
						<td>
							<strong><?php echo esc_html( $check['label'] ); ?></strong>
							<?php if ( $check['detail'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $check['detail'] ); ?></span>
							<?php endif; ?>
							<?php if ( 'fix_auth_header' === $check['fix'] ) : ?>
								<br /><?php self::action_button( 'fix_auth_header', __( 'Fix it in .htaccess', 'acadium-agent-publisher' ), false ); ?>
							<?php elseif ( 'permalinks' === $check['fix'] ) : ?>
								<br /><a class="button" href="#agent-publisher-permalinks-notice"><?php esc_html_e( 'Fix at the top of this page', 'acadium-agent-publisher' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php self::action_button( 'recheck', __( 'Run checks again', 'acadium-agent-publisher' ), false ); ?></p>
		<?php
	}
}
