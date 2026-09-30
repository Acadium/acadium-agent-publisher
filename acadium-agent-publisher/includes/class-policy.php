<?php
/**
 * Site policy: what agents may do, pre-publish checks and the activity log.
 *
 * The agent role never receives publishing capabilities. In every mode it
 * stays at read / edit_posts / delete_posts / upload_files, so the regular
 * REST API cannot publish or change live content. Publishing, scheduling,
 * unpublishing and editing live posts happen only through this plugin's
 * abilities, after the checks below.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_Policy {

	const OPTION     = 'agent_publisher_settings';
	const LOG_OPTION = 'agent_publisher_log';
	const LOG_SIZE   = 200;

	const ROLE         = 'agent_publisher_agent';
	const ROLE_VERSION = 2;

	/** Capabilities of the agent role, identical in every mode. */
	const ROLE_CAPS = array(
		'read'         => true,
		'edit_posts'   => true,
		'delete_posts' => true,
		'upload_files' => true,
	);

	/** Modes, least to most permissive. */
	const MODES = array( 'drafts', 'review', 'publish', 'publish_edit' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_update_role' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Role                                                                */
	/* ------------------------------------------------------------------ */

	public static function add_role() {
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'AI Agent', 'acadium-agent-publisher' ), self::ROLE_CAPS );
		update_option( 'agent_publisher_role_version', self::ROLE_VERSION );
	}

	public static function maybe_update_role() {
		if ( (int) get_option( 'agent_publisher_role_version' ) !== self::ROLE_VERSION || ! get_role( self::ROLE ) ) {
			self::add_role();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'mode'                   => 'drafts',
			'require_featured_image' => false,
			'allowed_categories'     => array(),
			'daily_limit'            => 0,
			'oauth_enabled'          => false,
			'image_min_width'        => 0,
			'image_aspect_ratios'    => '',
			'image_guidance'         => '',
			'image_checks_strict'    => false,
		);
	}

	public static function settings() {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mode  = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'drafts';
		$cats  = array_values( array_filter( array_map( 'absint', (array) ( $input['allowed_categories'] ?? array() ) ), function ( $id ) {
			return $id && term_exists( $id, 'category' );
		} ) );
		return array(
			'mode'                   => in_array( $mode, self::MODES, true ) ? $mode : 'drafts',
			'require_featured_image' => ! empty( $input['require_featured_image'] ),
			'allowed_categories'     => $cats,
			'daily_limit'            => min( 1000, absint( $input['daily_limit'] ?? 0 ) ),
			'oauth_enabled'          => ! empty( $input['oauth_enabled'] ),
			'image_min_width'        => min( 10000, absint( $input['image_min_width'] ?? 0 ) ),
			'image_aspect_ratios'    => self::sanitize_ratios( $input['image_aspect_ratios'] ?? '' ),
			'image_guidance'         => mb_substr( sanitize_textarea_field( (string) ( $input['image_guidance'] ?? '' ) ), 0, 2000 ),
			'image_checks_strict'    => ! empty( $input['image_checks_strict'] ),
		);
	}

	/** "2:1, 3/2, 16x9" -> "2:1, 3:2, 16:9" (at most 6). */
	private static function sanitize_ratios( $value ) {
		preg_match_all( '/(\d+(?:\.\d+)?)\s*[:x\/]\s*(\d+(?:\.\d+)?)/i', is_string( $value ) ? $value : '', $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $r ) {
			if ( (float) $r[1] > 0 && (float) $r[2] > 0 ) {
				$out[] = ( (float) $r[1] + 0 ) . ':' . ( (float) $r[2] + 0 );
			}
		}
		return implode( ', ', array_slice( array_unique( $out ), 0, 6 ) );
	}

	/** @return float[] Label ("2:1") => width / height. */
	public static function aspect_ratios() {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', self::settings()['image_aspect_ratios'] ) ) ) as $label ) {
			list( $w, $h ) = array_map( 'floatval', explode( ':', $label ) );
			if ( $w > 0 && $h > 0 ) {
				$out[ $label ] = $w / $h;
			}
		}
		return $out;
	}

	/**
	 * Advice about an image used as a featured image, from the site's image
	 * settings: too narrow, or a shape the theme's crops will cut into.
	 *
	 * @return string[]
	 */
	public static function image_warnings( $attachment_id ) {
		$s    = self::settings();
		$meta = wp_get_attachment_metadata( (int) $attachment_id );
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );
		if ( ! $w || ! $h ) {
			return array();
		}
		$out = array();
		if ( $s['image_min_width'] && $w < $s['image_min_width'] ) {
			$out[] = sprintf( 'the featured image is %1$dx%2$d px; this site asks for at least %3$d px wide', $w, $h, $s['image_min_width'] );
		}
		$ratios = self::aspect_ratios();
		if ( $ratios ) {
			$best = null;
			foreach ( $ratios as $label => $ratio ) {
				$off = abs( $w / $h - $ratio ) / $ratio;
				if ( null === $best || $off < $best[1] ) {
					$best = array( $label, $off );
				}
			}
			if ( $best[1] > 0.1 ) {
				$out[] = sprintf( 'the featured image is %1$dx%2$d px (%3$s:1), but this site\'s theme crops featured images to %4$s, so part of it will be cut off; use an image close to %5$s', $w, $h, round( $w / $h, 2 ), implode( ', ', array_keys( $ratios ) ), $best[0] );
			}
		}
		return $out;
	}

	public static function mode() {
		return self::settings()['mode'];
	}

	/**
	 * A user whose only role is AI Agent (and who can't manage the site).
	 * Only these users get the plugin's elevated publishing path and OAuth
	 * tokens; everyone else goes through their own WordPress capabilities.
	 *
	 * @param WP_User|int|null $user Defaults to the current user.
	 */
	public static function is_agent_user( $user = null ) {
		$user = null === $user ? wp_get_current_user() : ( $user instanceof WP_User ? $user : get_userdata( (int) $user ) );
		return $user && $user->exists()
			&& array( self::ROLE ) === array_values( (array) $user->roles )
			&& ! user_can( $user, 'manage_options' );
	}

	/** True if the site's mode is at least $mode. */
	public static function allows( $mode ) {
		return array_search( self::mode(), self::MODES, true ) >= array_search( $mode, self::MODES, true );
	}

	/** A WP_Error explaining which setting blocks an action. */
	public static function not_allowed( $action, $needed ) {
		$labels = self::mode_labels();
		return new WP_Error(
			'agent_publisher_mode',
			sprintf(
				'This site does not let agents %1$s (current mode: "%2$s"; needs "%3$s"). A site administrator can change this under Settings > Agent Publisher.',
				$action,
				$labels[ self::mode() ],
				$labels[ $needed ]
			),
			array( 'status' => 403 )
		);
	}

	public static function mode_labels() {
		return array(
			'drafts'       => __( 'Drafts only', 'acadium-agent-publisher' ),
			'review'       => __( 'Submit for review', 'acadium-agent-publisher' ),
			'publish'      => __( 'Publish', 'acadium-agent-publisher' ),
			'publish_edit' => __( 'Publish and edit live posts', 'acadium-agent-publisher' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Pre-publish checks                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Checks a post must pass before an agent publishes or schedules it.
	 * $counts_toward_limit is false for edits of already-live posts.
	 *
	 * @return true|WP_Error
	 */
	public static function check_publishable( WP_Post $post, $counts_toward_limit = true ) {
		$s        = self::settings();
		$problems = array();

		if ( '' === trim( $post->post_title ) ) {
			$problems[] = 'the post has no title';
		}
		if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			$problems[] = 'the post has no content';
		}

		if ( $s['require_featured_image'] ) {
			$thumb = get_post_thumbnail_id( $post );
			if ( ! $thumb ) {
				$problems[] = 'this site requires a featured image (pass featured_image to create-post, or use agent-publisher/upload-media with set_featured: true)';
			} elseif ( '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
				$problems[] = 'the featured image needs alt text';
			}
		}

		// Strict image checks: the image warnings (size, shape) block publishing.
		if ( $s['image_checks_strict'] && get_post_thumbnail_id( $post ) ) {
			foreach ( self::image_warnings( get_post_thumbnail_id( $post ) ) as $warning ) {
				$problems[] = $warning;
			}
		}

		if ( $s['allowed_categories'] && 'post' === $post->post_type ) {
			$cats       = wp_get_post_categories( $post->ID );
			$disallowed = array_diff( $cats, $s['allowed_categories'] );
			if ( ! $cats || $disallowed ) {
				$names      = array_map( function ( $id ) {
					$term = get_term( $id, 'category' );
					return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name ) . " ($id)" : (string) $id;
				}, $s['allowed_categories'] );
				$problems[] = 'agents may only publish in these categories: ' . implode( ', ', $names );
			}
		}

		if ( $counts_toward_limit && $s['daily_limit'] ) {
			$used = self::published_last_24h();
			if ( $used >= $s['daily_limit'] ) {
				$problems[] = sprintf( 'the daily limit of %d agent-published posts is reached (%d in the last 24 hours)', $s['daily_limit'], $used );
			}
		}

		if ( $problems ) {
			return new WP_Error(
				'agent_publisher_checks_failed',
				'Cannot publish: ' . implode( '; ', $problems ) . '. The post was left unchanged.',
				array( 'status' => 422 )
			);
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Activity log                                                        */
	/* ------------------------------------------------------------------ */

	public static function log( $action, $post_id = 0, $detail = '' ) {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'user'    => get_current_user_id(),
			'action'  => $action,
			'post'    => (int) $post_id,
			'title'   => $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : '',
			'detail'  => (string) $detail,
		);
		if ( count( $log ) > self::LOG_SIZE ) {
			$log = array_slice( $log, -self::LOG_SIZE );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	public static function entries( $limit = 50 ) {
		return array_reverse( array_slice( (array) get_option( self::LOG_OPTION, array() ), -$limit ) );
	}

	public static function published_last_24h() {
		$since = time() - DAY_IN_SECONDS;
		$n     = 0;
		foreach ( (array) get_option( self::LOG_OPTION, array() ) as $e ) {
			if ( $e['time'] >= $since && in_array( $e['action'], array( 'publish', 'schedule' ), true ) ) {
				++$n;
			}
		}
		return $n;
	}
}
