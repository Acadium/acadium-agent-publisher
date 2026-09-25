<?php
/**
 * Settings > Agent Publisher: publishing mode, pre-publish checks, setup
 * status and recent agent activity.
 *
 * Uses the Settings API (options.php handles the nonce and capability check;
 * Agent_Publisher_Policy::sanitize() cleans the input).
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_Settings_Page {

	const SLUG  = 'acadium-agent-publisher';
	const GROUP = 'agent_publisher';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AGENT_PUBLISHER_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function register() {
		register_setting(
			self::GROUP,
			Agent_Publisher_Policy::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Agent_Publisher_Policy', 'sanitize' ),
				'default'           => Agent_Publisher_Policy::defaults(),
			)
		);
	}

	public static function menu() {
		add_options_page(
			__( 'Agent Publisher', 'acadium-agent-publisher' ),
			__( 'Agent Publisher', 'acadium-agent-publisher' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'acadium-agent-publisher' ) )
		);
		return $links;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Agent_Publisher_Policy::settings();
		$name   = Agent_Publisher_Policy::OPTION;
		$labels = Agent_Publisher_Policy::mode_labels();
		$help   = array(
			'drafts'       => __( 'Agents create and edit their own drafts. A person reviews and publishes them.', 'acadium-agent-publisher' ),
			'review'       => __( 'Agents can also move their drafts to "Pending review" for an editor to publish.', 'acadium-agent-publisher' ),
			'publish'      => __( 'Agents can also publish and schedule their own posts after the checks below, and unpublish them again.', 'acadium-agent-publisher' ),
			'publish_edit' => __( 'Agents can also change their own posts after they are live.', 'acadium-agent-publisher' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Publisher', 'acadium-agent-publisher' ); ?></h1>
			<p><?php esc_html_e( 'Rules for AI agents (such as Claude) that work on this site through the Abilities API or MCP. They apply to every user with the AI Agent role.', 'acadium-agent-publisher' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'What agents may do', 'acadium-agent-publisher' ); ?></h2>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Publishing mode', 'acadium-agent-publisher' ); ?></legend>
					<?php foreach ( Agent_Publisher_Policy::MODES as $mode ) : ?>
						<p>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $s['mode'], $mode ); ?> />
								<strong><?php echo esc_html( $labels[ $mode ] ); ?></strong>
							</label>
							<br /><span class="description"><?php echo esc_html( $help[ $mode ] ); ?></span>
						</p>
					<?php endforeach; ?>
				</fieldset>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Publishing can trigger things that cannot be undone, such as subscriber emails, social media posts and notifications from other plugins. Unpublishing a post does not recall them. Start with "Drafts only" and allow publishing only when you trust the agent\'s work.', 'acadium-agent-publisher' ); ?></p>
				</div>

				<h2><?php esc_html_e( 'Checks before an agent publishes', 'acadium-agent-publisher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Applied when an agent publishes or schedules a post. A post always needs a title and content.', 'acadium-agent-publisher' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Featured image', 'acadium-agent-publisher' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[require_featured_image]" value="1" <?php checked( $s['require_featured_image'] ); ?> />
								<?php esc_html_e( 'Require a featured image with alt text', 'acadium-agent-publisher' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed categories', 'acadium-agent-publisher' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Allowed categories', 'acadium-agent-publisher' ); ?></legend>
								<?php
								$categories = get_categories( array( 'hide_empty' => false ) );
								foreach ( $categories as $cat ) :
									?>
									<label style="display:inline-block;min-width:14em;">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allowed_categories][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $s['allowed_categories'], true ) ); ?> />
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Agents may only publish posts in these categories. Leave all unchecked to allow any category.', 'acadium-agent-publisher' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agent-publisher-daily-limit"><?php esc_html_e( 'Daily limit', 'acadium-agent-publisher' ); ?></label></th>
						<td>
							<input type="number" min="0" max="1000" step="1" class="small-text" id="agent-publisher-daily-limit" name="<?php echo esc_attr( $name ); ?>[daily_limit]" value="<?php echo esc_attr( $s['daily_limit'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %d: number of posts agents published or scheduled in the last 24 hours. */
									esc_html__( 'Maximum posts agents may publish or schedule per 24 hours (0 = no limit). In the last 24 hours: %d.', 'acadium-agent-publisher' ),
									(int) Agent_Publisher_Policy::published_last_24h()
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Connections from claude.ai and mobile apps', 'acadium-agent-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'OAuth', 'acadium-agent-publisher' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[oauth_enabled]" value="1" <?php checked( $s['oauth_enabled'] ); ?> />
								<?php esc_html_e( 'Allow OAuth connections', 'acadium-agent-publisher' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Lets you add this site as a custom connector in claude.ai (web, desktop and mobile apps) without an Application Password. Each connection must be approved by an administrator, who picks the AI Agent user it acts as.', 'acadium-agent-publisher' ); ?></p>
							<?php if ( $s['oauth_enabled'] ) : ?>
								<p><?php esc_html_e( 'Connector URL:', 'acadium-agent-publisher' ); ?> <code><?php echo esc_html( Agent_Publisher_OAuth_Server::resource() ); ?></code></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_connections( $s ); ?>
			<?php self::render_status(); ?>
			<?php self::render_activity(); ?>
		</div>
		<?php
	}

	private static function render_connections( array $s ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-checked redirect.
		if ( isset( $_GET['agent-publisher-revoked'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Connection removed. The app can no longer access this site.', 'acadium-agent-publisher' ) . '</p></div>';
		}
		$grants = Agent_Publisher_OAuth_Store::grants();
		if ( ! $s['oauth_enabled'] && ! $grants ) {
			return;
		}
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2><?php esc_html_e( 'Connected apps', 'acadium-agent-publisher' ); ?></h2>
		<?php if ( ! $s['oauth_enabled'] && $grants ) : ?>
			<p class="description"><?php esc_html_e( 'OAuth connections are turned off, so these apps cannot access the site until you turn them back on.', 'acadium-agent-publisher' ); ?></p>
		<?php endif; ?>
		<?php if ( ! $grants ) : ?>
			<p><?php esc_html_e( 'No apps connected yet. In claude.ai, open Settings > Connectors > Add custom connector and enter the connector URL above.', 'acadium-agent-publisher' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:60em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'App', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Acts as', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Approved', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'acadium-agent-publisher' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $grants as $g ) : ?>
					<?php $user = get_userdata( (int) $g['user_id'] ); ?>
					<tr>
						<td>
							<?php echo esc_html( $g['client_name'] ); ?>
							<br /><span class="description"><?php echo esc_html( (string) wp_parse_url( $g['redirect'], PHP_URL_HOST ) ); ?></span>
						</td>
						<td><?php echo esc_html( $user ? $user->display_name : '#' . (int) $g['user_id'] ); ?></td>
						<td><?php echo esc_html( wp_date( $format, strtotime( $g['created'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( $g['last_used'] ? wp_date( $format, strtotime( $g['last_used'] . ' UTC' ) ) : '—' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="agent_publisher_revoke_grant" />
								<input type="hidden" name="grant" value="<?php echo esc_attr( $g['grant_id'] ); ?>" />
								<?php wp_nonce_field( 'agent_publisher_revoke_' . $g['grant_id'] ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'acadium-agent-publisher' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_status() {
		$agents   = get_users( array( 'role' => Agent_Publisher_Policy::ROLE, 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
		$mcp      = class_exists( 'WP\MCP\Core\McpAdapter' );
		$app_pw   = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
		$endpoint = Agent_Publisher_MCP_Server::url();
		$yes      = esc_html__( 'Yes', 'acadium-agent-publisher' );
		$no       = esc_html__( 'No', 'acadium-agent-publisher' );
		?>
		<h2><?php esc_html_e( 'Setup', 'acadium-agent-publisher' ); ?></h2>
		<table class="widefat striped" style="max-width:60em;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Agent users (role "AI Agent")', 'acadium-agent-publisher' ); ?></td>
					<td>
						<?php if ( $agents ) : ?>
							<?php
							echo esc_html( implode( ', ', array_map( function ( $u ) {
								return $u->display_name . ' (' . $u->user_login . ')';
							}, $agents ) ) );
							?>
						<?php else : ?>
							<?php esc_html_e( 'None yet.', 'acadium-agent-publisher' ); ?>
							<a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'Add a user with the AI Agent role', 'acadium-agent-publisher' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Site uses HTTPS', 'acadium-agent-publisher' ); ?></td>
					<td><?php echo is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Application Passwords available', 'acadium-agent-publisher' ); ?></td>
					<td><?php echo $app_pw ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'MCP server ready', 'acadium-agent-publisher' ); ?></td>
					<td>
						<?php echo $mcp ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						<?php if ( $mcp ) : ?>
							&mdash; <?php esc_html_e( 'Connection URL:', 'acadium-agent-publisher' ); ?> <code><?php echo esc_html( $endpoint ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_activity() {
		$entries = Agent_Publisher_Policy::entries( 50 );
		$actions = array(
			'create'           => __( 'Created draft', 'acadium-agent-publisher' ),
			'update'           => __( 'Updated draft', 'acadium-agent-publisher' ),
			'submit'           => __( 'Submitted for review', 'acadium-agent-publisher' ),
			'publish'          => __( 'Published', 'acadium-agent-publisher' ),
			'schedule'         => __( 'Scheduled', 'acadium-agent-publisher' ),
			'unpublish'        => __( 'Unpublished', 'acadium-agent-publisher' ),
			'update_published' => __( 'Updated live post', 'acadium-agent-publisher' ),
			'upload'           => __( 'Uploaded image', 'acadium-agent-publisher' ),
		);
		?>
		<h2><?php esc_html_e( 'Recent agent activity', 'acadium-agent-publisher' ); ?></h2>
		<?php if ( ! $entries ) : ?>
			<p><?php esc_html_e( 'No agent activity yet.', 'acadium-agent-publisher' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:60em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Agent', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Action', 'acadium-agent-publisher' ); ?></th>
					<th><?php esc_html_e( 'Post', 'acadium-agent-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $e ) : ?>
					<?php
					$user = get_userdata( (int) $e['user'] );
					$link = $e['post'] ? get_edit_post_link( (int) $e['post'] ) : '';
					?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $e['time'] ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '#' . (int) $e['user'] ); ?></td>
						<td><?php echo esc_html( $actions[ $e['action'] ] ?? $e['action'] ); ?><?php echo 'schedule' === $e['action'] && $e['detail'] ? ' (' . esc_html( $e['detail'] ) . ')' : ''; ?></td>
						<td>
							<?php if ( $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $e['title'] ? $e['title'] : '#' . (int) $e['post'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $e['title'] ? $e['title'] : ( $e['post'] ? '#' . (int) $e['post'] : '—' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
