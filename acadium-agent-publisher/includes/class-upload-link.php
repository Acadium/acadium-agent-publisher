<?php
/**
 * Upload links: Claude can't pass a file the user has (or one too large for
 * data_base64) through a tool call, so request-upload creates a one-time
 * page on this site where the person drops the image. It goes straight into
 * the Media Library under their account; get-upload then gives Claude the
 * attachment.
 *
 * The link is a capability URL: a 256-bit secret, single use, valid for
 * 30 minutes, bound to the user who requested it, and only usable while
 * that user may upload files. Only the secret's hash is stored.
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_Upload_Link {

	const PREFIX    = 'agent-publisher-upload';
	const TTL       = 30 * MINUTE_IN_SECONDS;
	const RESULT_TTL = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
	}

	private static function key( $upload_id ) {
		return 'agent_publisher_upload_' . $upload_id;
	}

	/** Public, non-secret ID of an upload link (what get-upload takes). */
	private static function upload_id( $token ) {
		return substr( hash( 'sha256', 'id|' . $token ), 0, 24 );
	}

	/**
	 * Create a link for the current user.
	 *
	 * @return array upload_id, upload_url, expires_at, max_bytes.
	 */
	public static function create( $alt_text = '' ) {
		$token = bin2hex( random_bytes( 32 ) );
		$id    = self::upload_id( $token );
		set_transient( self::key( $id ), array(
			'hash'    => hash( 'sha256', $token ),
			'user'    => get_current_user_id(),
			'expires' => time() + self::TTL,
			'alt'     => sanitize_text_field( (string) $alt_text ),
			'status'  => 'pending',
		), self::TTL );
		return array(
			'upload_id'  => $id,
			'upload_url' => home_url( '/' . self::PREFIX . '/' . $token ),
			'expires_at' => gmdate( 'c', time() + self::TTL ),
			'max_bytes'  => self::max_bytes(),
		);
	}

	/** Status of one of the current user's links. */
	public static function status( $upload_id ) {
		$data = get_transient( self::key( preg_replace( '/[^a-f0-9]/', '', (string) $upload_id ) ) );
		if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() ) {
			return new WP_Error( 'agent_publisher_upload_unknown', 'No such upload link (it may have expired). Call request-upload for a new one.', array( 'status' => 404 ) );
		}
		if ( 'done' === $data['status'] ) {
			return array( 'status' => 'done', 'image' => Agent_Publisher_Abilities::image_info( (int) $data['attachment'] ) + array( 'warnings' => Agent_Publisher_Policy::image_warnings( (int) $data['attachment'] ) ) );
		}
		return array( 'status' => time() > (int) $data['expires'] ? 'expired' : 'pending' );
	}

	public static function max_bytes() {
		return (int) apply_filters( 'agent_publisher_max_link_upload', wp_max_upload_size() );
	}

	/* ------------------------------------------------------------------ */
	/* The upload page                                                     */
	/* ------------------------------------------------------------------ */

	public static function route( WP $wp ) {
		$path = trim( (string) $wp->request, '/' );
		if ( 0 !== strpos( $path, self::PREFIX . '/' ) ) {
			return;
		}
		$token = substr( $path, strlen( self::PREFIX ) + 1 );
		$data  = preg_match( '/^[a-f0-9]{64}$/', $token ) ? get_transient( self::key( self::upload_id( $token ) ) ) : false;
		$valid = is_array( $data ) && hash_equals( (string) $data['hash'], hash( 'sha256', $token ) );
		$user  = $valid ? get_userdata( (int) $data['user'] ) : false;
		$post  = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET' );

		if ( ! $valid || ! $user ) {
			self::fail( $post, __( 'This upload link is not valid. Ask Claude for a new one.', 'acadium-agent-publisher' ), 404 );
		}
		if ( 'done' === $data['status'] ) {
			self::fail( $post, __( 'This upload link has already been used. Ask Claude for a new one to add another image.', 'acadium-agent-publisher' ), 410 );
		}
		if ( time() > (int) $data['expires'] ) {
			self::fail( $post, __( 'This upload link has expired. Ask Claude for a new one.', 'acadium-agent-publisher' ), 410 );
		}
		if ( ! user_can( $user, 'upload_files' ) || ! Agent_Publisher_Policy::can_connect( $user ) ) {
			self::fail( $post, __( 'Your WordPress account can\'t upload files on this site.', 'acadium-agent-publisher' ), 403 );
		}
		if ( $post ) {
			self::handle_upload( $token, $data, $user );
		}
		self::page( $data, $user );
	}

	/**
	 * Authenticated by the single-use secret in the URL (a capability URL),
	 * so there is no login or nonce; the file is checked like any upload.
	 */
	private static function handle_upload( $token, array $data, WP_User $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- capability URL: the secret token in the path authenticates this request (see class comment).
		if ( empty( $_FILES['file']['tmp_name'] ) || ! empty( $_FILES['file']['error'] ) ) {
			$too_big = ! empty( $_FILES['file']['error'] ) && in_array( (int) $_FILES['file']['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			self::json( array( 'error' => $too_big ? self::too_big_message() : __( 'No file was received. Try again.', 'acadium-agent-publisher' ) ), $too_big ? 413 : 400 );
		}
		if ( ( isset( $_FILES['file']['size'] ) ? (int) $_FILES['file']['size'] : 0 ) > self::max_bytes() ) {
			self::json( array( 'error' => self::too_big_message() ), 413 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// The upload belongs to the person who asked Claude for the link.
		wp_set_current_user( $user->ID );
		$id = media_handle_upload( 'file', 0, array(), array(
			'test_form' => false,
			'mimes'     => Agent_Publisher_Abilities::upload_mimes(),
		) );
		if ( is_wp_error( $id ) ) {
			self::json( array( 'error' => $id->get_error_message() ), 400 );
		}
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : $data['alt'];
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $id, '_agent_publisher_source', 'upload_link' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$data['status']     = 'done';
		$data['attachment'] = (int) $id;
		set_transient( self::key( self::upload_id( $token ) ), $data, self::RESULT_TTL );
		Agent_Publisher_Policy::log( 'upload', 0, wp_get_attachment_url( $id ) );

		$meta = wp_get_attachment_metadata( $id );
		self::json( array(
			'id'     => (int) $id,
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
		) );
	}

	private static function too_big_message() {
		/* translators: %s: maximum file size, e.g. "80 MB". */
		return sprintf( __( 'This file is larger than this site allows (%s).', 'acadium-agent-publisher' ), size_format( self::max_bytes() ) );
	}

	private static function headers( $status ) {
		status_header( $status );
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	private static function json( array $body, $status = 200 ) {
		self::headers( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}

	private static function fail( $post, $message, $status ) {
		if ( $post ) {
			self::json( array( 'error' => $message ), $status );
		}
		self::open( $status, __( 'Upload not possible', 'acadium-agent-publisher' ) );
		echo '<h1>' . esc_html__( 'Upload not possible', 'acadium-agent-publisher' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	private static function open( $status, $title ) {
		self::headers( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
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
.card{max-width:30rem;margin:6vh auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px}
h1{font-size:20px;margin:0 0 12px}.muted{color:#50575e;font-size:13px}
#drop{border:2px dashed #8c8f94;border-radius:8px;padding:28px;text-align:center;cursor:pointer;margin:14px 0}
#drop.over{border-color:#2271b1;background:#f0f6fc}#preview{max-width:100%;max-height:220px;display:none;margin:10px auto 0;border-radius:4px}
label{display:block;font-weight:600;margin-top:10px}input[type=text]{width:100%;box-sizing:border-box;padding:8px;font-size:15px;margin-top:4px}
button{width:100%;margin-top:16px;padding:11px;font-size:15px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}
button:disabled{opacity:.5;cursor:default}.ok{background:#edfaef;border-left:4px solid #00a32a;padding:10px 12px}.err{background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px}
</style>
</head>
<body><div class="card">
		<?php
	}

	private static function page( array $data, WP_User $user ) {
		$types = implode( ', ', array_map( function ( $mime ) {
			return strtoupper( str_replace( 'image/', '', $mime ) );
		}, array_values( Agent_Publisher_Abilities::upload_mimes() ) ) );
		self::open( 200, __( 'Add an image for Claude', 'acadium-agent-publisher' ) );
		?>
		<h1><?php esc_html_e( 'Add an image for Claude', 'acadium-agent-publisher' ); ?></h1>
		<p class="muted">
			<?php
			/* translators: 1: user's display name, 2: site name. */
			echo esc_html( sprintf( __( 'The image goes into the Media Library of %2$s, uploaded by %1$s. Claude can then use it in your post.', 'acadium-agent-publisher' ), $user->display_name, get_bloginfo( 'name' ) ) );
			?>
		</p>
		<form id="f">
			<div id="drop">
				<strong><?php esc_html_e( 'Drop an image here, or click to choose one', 'acadium-agent-publisher' ); ?></strong><br />
				<span class="muted">
					<?php
					/* translators: 1: file types, e.g. "JPEG, PNG", 2: maximum size, e.g. "80 MB". */
					echo esc_html( sprintf( __( '%1$s, up to %2$s. Use the full-size original.', 'acadium-agent-publisher' ), $types, size_format( self::max_bytes() ) ) );
					?>
				</span>
				<img id="preview" alt="" />
			</div>
			<input id="file" name="file" type="file" accept="<?php echo esc_attr( implode( ',', array_values( Agent_Publisher_Abilities::upload_mimes() ) ) ); ?>" hidden />
			<label for="alt"><?php esc_html_e( 'Short description (alt text)', 'acadium-agent-publisher' ); ?></label>
			<input id="alt" name="alt" type="text" value="<?php echo esc_attr( $data['alt'] ); ?>" />
			<button id="go" type="submit" disabled><?php esc_html_e( 'Upload', 'acadium-agent-publisher' ); ?></button>
		</form>
		<div id="msg" style="margin-top:14px"></div>
		<script>
		(function () {
			var f = document.getElementById('f'), drop = document.getElementById('drop'), input = document.getElementById('file'),
				go = document.getElementById('go'), msg = document.getElementById('msg'), prev = document.getElementById('preview'), file = null;
			function pick(x) { if (!x) { return; } file = x; go.disabled = false; prev.src = URL.createObjectURL(x); prev.style.display = 'block'; msg.textContent = ''; }
			drop.onclick = function () { input.click(); };
			input.onchange = function () { pick(input.files[0]); };
			drop.ondragover = function (e) { e.preventDefault(); drop.className = 'over'; };
			drop.ondragleave = function () { drop.className = ''; };
			drop.ondrop = function (e) { e.preventDefault(); drop.className = ''; pick(e.dataTransfer.files[0]); };
			f.onsubmit = function (e) {
				e.preventDefault(); if (!file) { return; }
				var d = new FormData(); d.append('file', file); d.append('alt', document.getElementById('alt').value);
				go.disabled = true; go.textContent = <?php echo wp_json_encode( __( 'Uploading…', 'acadium-agent-publisher' ) ); ?>;
				fetch(location.href, { method: 'POST', body: d }).then(function (r) { return r.json(); }).then(function (j) {
					if (j.error) { msg.className = 'err'; msg.textContent = j.error; go.disabled = false; go.textContent = <?php echo wp_json_encode( __( 'Upload', 'acadium-agent-publisher' ) ); ?>; return; }
					f.style.display = 'none'; msg.className = 'ok';
					msg.textContent = <?php echo wp_json_encode( __( 'Image added. Go back to Claude and tell it the image is uploaded.', 'acadium-agent-publisher' ) ); ?> + ' (' + j.width + '×' + j.height + ')';
				}).catch(function () { msg.className = 'err'; msg.textContent = <?php echo wp_json_encode( __( 'The upload failed. Check your connection and try again.', 'acadium-agent-publisher' ) ); ?>; go.disabled = false; });
			};
		})();
		</script>
		</div></body></html>
		<?php
		exit;
	}
}
