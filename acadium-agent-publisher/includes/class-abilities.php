<?php
/**
 * Abilities exposed to AI agents (WordPress Abilities API, 6.9+).
 *
 * The MCP Adapter plugin exposes them to MCP clients through its
 * discover / get-info / execute tools; the core REST API exposes them under
 * /wp-json/wp-abilities/v1/. Each ability runs as the WordPress user the
 * client authenticated as, and WordPress' capability checks and HTML
 * filtering apply.
 *
 * Filters for site-specific configuration:
 *   agent_publisher_post_types   post types the post abilities work on (default: post)
 *   agent_publisher_post_meta    custom fields agents may read/write (default: none)
 *   agent_publisher_upload_mimes MIME types upload-media accepts (default: jpeg, png, gif, webp)
 *   agent_publisher_max_upload   max upload size in bytes (default: 10 MB)
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

final class Agent_Publisher_Abilities {

	const CATEGORY = 'agent-publisher';

	/** Post statuses an agent may modify. Published content is off-limits. */
	const EDITABLE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		wp_register_ability_category( self::CATEGORY, array(
			'label'       => __( 'Agent publishing', 'acadium-agent-publisher' ),
			'description' => __( 'Draft, review, publish and update posts, upload images and look up taxonomy terms, within the rules set by the site owner.', 'acadium-agent-publisher' ),
		) );
	}

	/**
	 * Exposure flags. `public` is the WordPress 7.1+ switch (it also seeds
	 * show_in_rest); `mcp.public` and `show_in_rest` make the same intent
	 * explicit on 6.9 / 7.0 and for the MCP Adapter.
	 */
	private static function meta( $readonly, $destructive, $idempotent ) {
		return array(
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	public static function register_abilities() {
		$post_types = self::post_types();
		$meta_keys  = self::meta_keys();

		$meta_schema = array(
			'type'                 => 'object',
			'description'          => $meta_keys
				? 'Custom fields. Allowed keys: ' . implode( ', ', $meta_keys ) . '.'
				: 'Custom fields (none are enabled on this site).',
			'properties'           => array_fill_keys( $meta_keys, array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		);

		$post_fields = array(
			'title'          => array( 'type' => 'string', 'description' => 'Post title (plain text).' ),
			'content'        => array( 'type' => 'string', 'description' => 'Post body as HTML. Use the markup the site\'s editor produces: plain HTML (<h2>, <p>, <ul>, <img>) for the classic editor, or block markup (<!-- wp:paragraph -->) for the block editor. Scripts, iframes and inline styles are removed unless the user may post unfiltered HTML.' ),
			'excerpt'        => array( 'type' => 'string', 'description' => 'Optional summary shown in listings and search results.' ),
			'slug'           => array( 'type' => 'string', 'description' => 'Optional URL slug. Generated from the title when omitted.' ),
			'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs (see agent-publisher/list-terms). Replaces existing categories.' ),
			'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names. Missing tags are created. Replaces existing tags.' ),
			'featured_media' => array( 'type' => 'integer', 'description' => 'Attachment ID for the featured image (see agent-publisher/upload-media).' ),
			'meta'           => $meta_schema,
		);

		$post_output = array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'edit_url'    => array( 'type' => 'string', 'description' => 'wp-admin editor URL for a human reviewer.' ),
				'preview_url' => array( 'type' => 'string', 'description' => 'Preview URL (requires being logged in).' ),
			),
		);

		wp_register_ability( 'agent-publisher/get-capabilities', array(
			'label'               => __( 'Get what the agent may do', 'acadium-agent-publisher' ),
			'description'         => 'Returns this site\'s rules for agents: the publishing mode (drafts only, submit for review, publish, or publish and edit live posts), which actions are therefore allowed, and the pre-publish checks (featured image required, allowed categories, daily limit). Call this first so you only offer actions the site allows.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'mode'       => array( 'type' => 'string', 'enum' => Agent_Publisher_Policy::MODES ),
					'mode_label' => array( 'type' => 'string' ),
					'allowed'    => array( 'type' => 'object' ),
					'checks'     => array( 'type' => 'object' ),
					'user'       => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => array( __CLASS__, 'get_capabilities' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => self::meta( true, false, true ),
		) );

		wp_register_ability( 'agent-publisher/list-terms', array(
			'label'               => __( 'List categories or tags', 'acadium-agent-publisher' ),
			'description'         => 'Lists existing categories or tags with their IDs, so posts can be filed correctly. Call this before create-draft-post to pick category IDs. Supports a search filter.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ), 'default' => 'category' ),
					'search'   => array( 'type' => 'string', 'description' => 'Optional name filter.' ),
					'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'name'   => array( 'type' => 'string' ),
						'slug'   => array( 'type' => 'string' ),
						'parent' => array( 'type' => 'integer' ),
						'count'  => array( 'type' => 'integer', 'description' => 'Published posts using the term.' ),
					),
				),
			),
			'execute_callback'    => array( __CLASS__, 'list_terms' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => self::meta( true, false, true ),
		) );

		wp_register_ability( 'agent-publisher/get-post', array(
			'label'               => __( 'Get a post', 'acadium-agent-publisher' ),
			'description'         => 'Returns a post the current user can edit, including its raw HTML content, categories, tags, featured image and allowed custom fields. Use it before update-draft-post to see the current text.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array( 'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ) ),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge( $post_output['properties'], $post_fields, array(
					'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'modified'   => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
				) ),
			),
			'execute_callback'    => array( __CLASS__, 'get_post' ),
			'permission_callback' => function ( $input ) {
				return self::can_edit( $input['id'] ?? 0 );
			},
			'meta'                => self::meta( true, false, true ),
		) );

		wp_register_ability( 'agent-publisher/create-draft-post', array(
			'label'               => __( 'Create a draft post', 'acadium-agent-publisher' ),
			'description'         => 'Creates a new post as a DRAFT (never published) for a human to review and publish in WordPress. Returns the post ID and an edit URL to share with the reviewer.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'post_type' => array( 'type' => 'string', 'enum' => $post_types, 'default' => $post_types[0] ),
				), $post_fields ),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $post_output,
			'execute_callback'    => array( __CLASS__, 'create_draft' ),
			'permission_callback' => function ( $input ) {
				$type = get_post_type_object( $input['post_type'] ?? self::post_types()[0] );
				return $type && current_user_can( $type->cap->edit_posts );
			},
			'meta'                => self::meta( false, false, false ),
		) );

		wp_register_ability( 'agent-publisher/update-draft-post', array(
			'label'               => __( 'Update a draft post', 'acadium-agent-publisher' ),
			'description'         => 'Changes fields of a post that is still a draft or pending review. Only the fields you pass are changed. Published and scheduled posts are refused: ask a human to revert them to draft first.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'id' => array( 'type' => 'integer', 'description' => 'ID of the draft to change.' ),
				), $post_fields ),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $post_output,
			'execute_callback'    => array( __CLASS__, 'update_draft' ),
			'permission_callback' => function ( $input ) {
				return self::can_edit( $input['id'] ?? 0 );
			},
			'meta'                => self::meta( false, true, true ),
		) );

		wp_register_ability( 'agent-publisher/upload-media', array(
			'label'               => __( 'Upload an image', 'acadium-agent-publisher' ),
			'description'         => 'Adds an image to the Media Library from a public https URL or from base64 data, with alt text. Optionally attaches it to a draft and makes it that draft\'s featured image. Returns the attachment ID and URL to use in post content.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'url'          => array( 'type' => 'string', 'description' => 'Public https URL of the image. Provide url OR data_base64.' ),
					'data_base64'  => array( 'type' => 'string', 'description' => 'Base64-encoded image bytes. Requires filename.' ),
					'filename'     => array( 'type' => 'string', 'description' => 'File name with extension, e.g. hero.jpg.' ),
					'alt_text'     => array( 'type' => 'string', 'description' => 'Describe the image for screen readers. Required.' ),
					'title'        => array( 'type' => 'string' ),
					'caption'      => array( 'type' => 'string' ),
					'post_id'      => array( 'type' => 'integer', 'description' => 'Optional draft to attach the image to.' ),
					'set_featured' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Make it the featured image of post_id.' ),
				),
				'required'             => array( 'alt_text' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'url'    => array( 'type' => 'string' ),
					'width'  => array( 'type' => 'integer' ),
					'height' => array( 'type' => 'integer' ),
					'mime'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( __CLASS__, 'upload_media' ),
			'permission_callback' => function ( $input ) {
				if ( ! current_user_can( 'upload_files' ) ) {
					return false;
				}
				if ( ! empty( $input['post_id'] ) ) {
					return self::can_edit( $input['post_id'] );
				}
				return true;
			},
			'meta'                => self::meta( false, false, false ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Configuration                                                       */
	/* ------------------------------------------------------------------ */

	private static function post_types() {
		$types = array_values( array_filter( (array) apply_filters( 'agent_publisher_post_types', array( 'post' ) ), 'post_type_exists' ) );
		return $types ? $types : array( 'post' );
	}

	private static function meta_keys() {
		return array_values( array_filter( (array) apply_filters( 'agent_publisher_post_meta', array() ), 'is_string' ) );
	}

	/** The user may edit this post, it is a supported type, and it is not published. */
	private static function can_edit( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'agent_publisher_not_found', 'No such post.', array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/* ------------------------------------------------------------------ */
	/* Callbacks                                                           */
	/* ------------------------------------------------------------------ */

	public static function get_capabilities() {
		$s     = Agent_Publisher_Policy::settings();
		$user  = wp_get_current_user();
		$names = array();
		foreach ( $s['allowed_categories'] as $id ) {
			$term = get_term( $id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = array( 'id' => (int) $id, 'name' => html_entity_decode( $term->name ) );
			}
		}
		return array(
			'mode'       => $s['mode'],
			'mode_label' => Agent_Publisher_Policy::mode_labels()[ $s['mode'] ],
			'allowed'    => array(
				'create_drafts'     => true,
				'update_drafts'     => true,
				'upload_media'      => current_user_can( 'upload_files' ),
				'submit_for_review' => Agent_Publisher_Policy::allows( 'review' ),
				'publish'           => Agent_Publisher_Policy::allows( 'publish' ),
				'schedule'          => Agent_Publisher_Policy::allows( 'publish' ),
				'unpublish'         => Agent_Publisher_Policy::allows( 'publish' ),
				'edit_published'    => Agent_Publisher_Policy::allows( 'publish_edit' ),
			),
			'checks'     => array(
				'require_featured_image' => (bool) $s['require_featured_image'],
				'allowed_categories'     => $names,
				'daily_limit'            => (int) $s['daily_limit'],
				'published_last_24h'     => Agent_Publisher_Policy::published_last_24h(),
			),
			'user'       => array(
				'id'    => (int) $user->ID,
				'name'  => $user->display_name,
				'roles' => array_values( $user->roles ),
			),
		);
	}

	public static function list_terms( $input = array() ) {
		$terms = get_terms( array(
			'taxonomy'   => $input['taxonomy'] ?? 'category',
			'hide_empty' => false,
			'search'     => $input['search'] ?? '',
			'number'     => $input['limit'] ?? 100,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array_map( function ( $t ) {
			return array(
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode( $t->name ),
				'slug'   => $t->slug,
				'parent' => (int) $t->parent,
				'count'  => (int) $t->count,
			);
		}, $terms );
	}

	public static function get_post( $input ) {
		$post = get_post( (int) $input['id'] );
		$out  = self::summary( $post );
		$out += array(
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'slug'           => $post->post_name,
			'categories'     => array_map( function ( $t ) {
				return array( 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name ) );
			}, get_the_category( $post->ID ) ),
			'tags'           => wp_list_pluck( (array) get_the_tags( $post->ID ), 'name' ),
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'meta'           => (object) array_map( function ( $key ) use ( $post ) {
				return (string) get_post_meta( $post->ID, $key, true );
			}, array_combine( self::meta_keys(), self::meta_keys() ) ?: array() ),
			// Drafts have no GMT modified date; the local one is always set.
			'modified'       => mysql2date( 'c', $post->post_modified, false ),
			'link'           => get_permalink( $post ),
		);
		return $out;
	}

	public static function create_draft( $input ) {
		$valid = self::validate_extras( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$postarr = self::postarr( $input );
		$postarr['post_type']   = $input['post_type'] ?? self::post_types()[0];
		$postarr['post_status'] = 'draft';
		$postarr['post_author'] = get_current_user_id();

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::save_extras( $id, $input );
		return self::summary( get_post( $id ) );
	}

	public static function update_draft( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! in_array( $post->post_status, self::EDITABLE_STATUSES, true ) ) {
			return new WP_Error(
				'agent_publisher_not_a_draft',
				sprintf( 'Post %d is "%s". Only drafts can be changed; ask a human to switch it back to draft in WordPress first.', $post->ID, $post->post_status ),
				array( 'status' => 409 )
			);
		}

		$valid = self::validate_extras( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$postarr       = self::postarr( $input );
		$postarr['ID'] = $post->ID;
		if ( count( $postarr ) > 1 ) {
			$id = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		self::save_extras( $post->ID, $input );
		return self::summary( get_post( $post->ID ) );
	}

	public static function upload_media( $input ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$max = (int) apply_filters( 'agent_publisher_max_upload', 10 * MB_IN_BYTES );

		if ( ! empty( $input['url'] ) ) {
			$url = esc_url_raw( $input['url'], array( 'https' ) );
			if ( ! $url ) {
				return new WP_Error( 'agent_publisher_bad_url', 'url must be a public https URL.', array( 'status' => 400 ) );
			}
			// download_url() uses wp_safe_remote_get(), which refuses private/local addresses.
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = ! empty( $input['filename'] ) ? $input['filename'] : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
		} elseif ( ! empty( $input['data_base64'] ) ) {
			if ( empty( $input['filename'] ) ) {
				return new WP_Error( 'agent_publisher_missing_filename', 'filename is required with data_base64.', array( 'status' => 400 ) );
			}
			$bytes = base64_decode( preg_replace( '/^data:[^,]*,/', '', $input['data_base64'] ), true );
			if ( false === $bytes ) {
				return new WP_Error( 'agent_publisher_bad_base64', 'data_base64 is not valid base64.', array( 'status' => 400 ) );
			}
			if ( strlen( $bytes ) > $max ) {
				return new WP_Error( 'agent_publisher_too_large', sprintf( 'Image is larger than %s.', size_format( $max ) ), array( 'status' => 413 ) );
			}
			$tmp = wp_tempnam( $input['filename'] );
			file_put_contents( $tmp, $bytes );
			$name = $input['filename'];
		} else {
			return new WP_Error( 'agent_publisher_no_source', 'Provide url or data_base64.', array( 'status' => 400 ) );
		}

		if ( filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'agent_publisher_too_large', sprintf( 'Image is larger than %s.', size_format( $max ) ), array( 'status' => 413 ) );
		}

		// Check the real content type, not just the extension.
		$mimes = (array) apply_filters( 'agent_publisher_upload_mimes', array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		) );
		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		if ( empty( $check['type'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'agent_publisher_bad_type', 'Only these file types are accepted: ' . implode( ', ', array_values( $mimes ) ) . '.', array( 'status' => 415 ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$id      = media_handle_sideload(
			array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ),
			$post_id,
			null,
			array_filter( array(
				'post_title'   => $input['title'] ?? '',
				'post_excerpt' => $input['caption'] ?? '',
			) )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}

		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );

		if ( $post_id && ! empty( $input['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $id );
		}

		$src = wp_get_attachment_image_src( $id, 'full' );
		return array(
			'id'     => (int) $id,
			'url'    => wp_get_attachment_url( $id ),
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
			'mime'   => get_post_mime_type( $id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function postarr( $input ) {
		$map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);
		$out = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $input[ $from ] ) ) {
				$out[ $to ] = $input[ $from ];
			}
		}
		return $out;
	}

	/**
	 * Check references before anything is written, so a bad request never
	 * leaves a half-created draft behind.
	 */
	private static function validate_extras( $input ) {
		foreach ( (array) ( $input['categories'] ?? array() ) as $cat ) {
			if ( ! term_exists( (int) $cat, 'category' ) ) {
				return new WP_Error( 'agent_publisher_bad_category', "Category $cat does not exist. Use agent-publisher/list-terms.", array( 'status' => 400 ) );
			}
		}
		if ( isset( $input['featured_media'] ) ) {
			$att = get_post( (int) $input['featured_media'] );
			if ( ! $att || 'attachment' !== $att->post_type || ! wp_attachment_is_image( $att ) ) {
				return new WP_Error( 'agent_publisher_bad_media', 'featured_media must be an image attachment ID.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/** Categories, tags, featured image, custom fields (validated beforehand). */
	private static function save_extras( $post_id, $input ) {
		if ( isset( $input['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $input['categories'] ) );
		}
		if ( isset( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $input['tags'] ) );
		}
		if ( isset( $input['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $input['featured_media'] );
		}
		if ( ! empty( $input['meta'] ) ) {
			$allowed = self::meta_keys();
			foreach ( (array) $input['meta'] as $key => $value ) {
				if ( in_array( $key, $allowed, true ) ) {
					update_post_meta( $post_id, $key, wp_slash( wp_kses_post( (string) $value ) ) );
				}
			}
		}
	}

	private static function summary( $post ) {
		return array(
			'id'          => (int) $post->ID,
			'status'      => $post->post_status,
			'title'       => html_entity_decode( get_the_title( $post ) ),
			'edit_url'    => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'preview_url' => get_preview_post_link( $post ),
		);
	}
}
