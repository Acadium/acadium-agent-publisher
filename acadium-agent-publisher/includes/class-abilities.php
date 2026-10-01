<?php
/**
 * Abilities exposed to AI agents (WordPress Abilities API, 6.9+).
 *
 * The plugin's MCP server (class-mcp-server.php) lists them as MCP tools; the
 * MCP Adapter default server reaches them through its discover / get-info /
 * execute tools; the core REST API exposes them under
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
	 * Annotations are hints for clients, but core's Abilities REST API also
	 * derives the HTTP method from them: readonly → GET, destructive AND
	 * idempotent → DELETE (input only from the query string), otherwise POST.
	 * Write abilities therefore never combine destructive with idempotent,
	 * so they all take a JSON body via POST.
	 *
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

		$post_fields = array(
			'title'          => array( 'type' => 'string', 'description' => 'Post title (plain text).' ),
			'content'        => array( 'type' => 'string', 'description' => 'Post body as HTML. Use the markup the site\'s editor produces: plain HTML (<h2>, <p>, <ul>, <img>) for the classic editor, or block markup (<!-- wp:paragraph -->) for the block editor. Scripts, iframes and inline styles are removed unless the user may post unfiltered HTML.' ),
			'excerpt'        => array( 'type' => 'string', 'description' => 'Optional summary shown in listings and search results.' ),
			'slug'           => array( 'type' => 'string', 'description' => 'Optional URL slug. Generated from the title when omitted.' ),
			'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs (see agent-publisher/list-terms). Replaces existing categories.' ),
			'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names. Missing tags are created. Replaces existing tags.' ),
			'featured_media' => array( 'type' => 'integer', 'description' => 'ID of an image already in the Media Library to use as the featured image. To add a new image, use featured_image on create-post, or agent-publisher/upload-media.' ),
		);

		// Only offer custom fields when a site enables some. An empty
		// `properties` would be encoded as a JSON array ([]), an invalid
		// schema that makes MCP clients such as Claude Desktop drop the tool.
		if ( $meta_keys ) {
			$post_fields['meta'] = array(
				'type'                 => 'object',
				'description'          => 'Custom fields. Allowed keys: ' . implode( ', ', $meta_keys ) . '.',
				'properties'           => array_fill_keys( $meta_keys, array( 'type' => 'string' ) ),
				'additionalProperties' => false,
			);
		}

		$post_output = array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'edit_url'       => array( 'type' => 'string', 'description' => 'wp-admin editor URL for a human reviewer.' ),
				'preview_url'    => array( 'type' => 'string', 'description' => 'Preview URL (requires being logged in).' ),
				'featured_image' => array( 'type' => 'object', 'description' => 'The featured image: id, url, width, height, alt, and the sizes WordPress generated from it (name => url, width, height), to check how it is cropped.' ),
				'image_warnings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Problems with the featured image for this site (too small, or a shape the theme crops); see get-capabilities > images.' ),
			),
		);

		$id_only = array(
			'type'                 => 'object',
			'properties'           => array( 'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ) ),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
		$publish_output = array(
			'type'       => 'object',
			'properties' => array_merge( $post_output['properties'], array(
				'link' => array( 'type' => 'string', 'description' => 'Public URL (for scheduled posts, live from the scheduled time).' ),
				'date' => array( 'type' => 'string', 'description' => 'Publication date (site time, ISO 8601).' ),
			) ),
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
					'images'     => array( 'type' => 'object', 'description' => 'How to add images and what this site needs: accepted types, size limits, generated sizes, minimum width, aspect ratios the theme crops to, and guidance from the site owner.' ),
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
			'description'         => 'Lists existing categories or tags with their IDs, so posts can be filed correctly. Call this before create-post to pick category IDs. Supports a search filter.',
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
			'description'         => 'Returns one of your posts in any status (draft, pending, scheduled or published), including its raw HTML content, categories, tags, featured image with its generated sizes, and allowed custom fields. Use it before update-draft-post or update-published-post to see the current version.',
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
				return self::can_read( $input['id'] ?? 0 );
			},
			'meta'                => self::meta( true, false, true ),
		) );

		$image_source = array(
			'url'         => array( 'type' => 'string', 'description' => 'Public https URL of the image. Provide url OR data_base64.' ),
			'data_base64' => array( 'type' => 'string', 'description' => 'Base64-encoded image bytes. Requires filename. Only practical for small files (under about 100 KB); for larger ones use url, or find-media for images the user uploaded. Never reduce an image\'s resolution to make it fit here: a featured image needs to be at least the width in get-capabilities > images. Whitespace and line breaks are ignored.' ),
			'sha256'      => array( 'type' => 'string', 'description' => 'Optional SHA-256 (hex) of the image file. If given, the upload is refused unless the received bytes match, so a copying mistake can\'t save a corrupted image.' ),
			'filename'    => array( 'type' => 'string', 'description' => 'File name with extension, e.g. hero.jpg.' ),
			'alt_text'    => array( 'type' => 'string', 'description' => 'Describe the image for screen readers. Required.' ),
			'title'       => array( 'type' => 'string' ),
			'caption'     => array( 'type' => 'string' ),
		);

		$featured_image = array(
			'type'                 => 'object',
			'description'          => 'Uploads an image and makes it the featured image. Use this or featured_media, not both. It must be at least the width in get-capabilities > images (recommended_width to look sharp); smaller images are refused when publishing unless the site allows them. Never shrink an image to fit through data_base64: ask the user to upload the full-size image (Media > Add New) and pass its id as featured_media (see find-media).',
			'properties'           => $image_source,
			'required'             => array( 'alt_text' ),
			'additionalProperties' => false,
		);

		wp_register_ability( 'agent-publisher/create-post', array(
			'label'               => __( 'Create a post', 'acadium-agent-publisher' ),
			'description'         => 'Creates a new post in one call: title, content, categories, tags and optionally a featured image (featured_image uploads it for you). status "draft" (default) saves it for a human to review; "pending" submits it for review; "publish" publishes it now, or schedules it when you pass a future date. Use "pending" or "publish" only when the site mode allows it (see agent-publisher/get-capabilities). When the user asked for the post to be published, pass status "publish" here instead of creating a draft and calling publish-post. For "publish", the site\'s pre-publish checks run first; if any fail, nothing is created and the error says what to fix, so you can correct the input and call again.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'post_type' => array( 'type' => 'string', 'enum' => $post_types, 'default' => $post_types[0] ),
				), $post_fields, array(
					'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish' ), 'default' => 'draft', 'description' => 'draft (default), pending (submit for review) or publish (publish now, or schedule with date).' ),
					'date'           => array( 'type' => 'string', 'description' => 'Only with status "publish": future publication time, ISO 8601 (e.g. 2026-10-01T09:00:00-04:00; without an offset the site\'s timezone is used). Omit to publish now.' ),
					'featured_image' => $featured_image,
				) ),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $publish_output,
			'execute_callback'    => array( __CLASS__, 'create_post' ),
			'permission_callback' => function ( $input ) {
				$status = $input['status'] ?? 'draft';
				if ( 'pending' === $status && ! Agent_Publisher_Policy::allows( 'review' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'submit posts for review', 'review' );
				}
				if ( 'publish' === $status && ! Agent_Publisher_Policy::allows( 'publish' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'publish posts', 'publish' );
				}
				if ( ! empty( $input['featured_image'] ) && ! current_user_can( 'upload_files' ) ) {
					return false;
				}
				$type = get_post_type_object( $input['post_type'] ?? self::post_types()[0] );
				if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
					return false;
				}
				// People publish only if their own role lets them (AI Agent users: the mode decides).
				if ( 'publish' === $status && ! Agent_Publisher_Policy::can_publish( $type ) ) {
					return new WP_Error( 'agent_publisher_role', 'Your WordPress role can\'t publish posts, so Claude can\'t publish them for you. Create a draft or submit it for review instead.', array( 'status' => 403 ) );
				}
				return true;
			},
			'meta'                => self::meta( false, false, false ),
		) );

		wp_register_ability( 'agent-publisher/update-draft-post', array(
			'label'               => __( 'Update a draft post', 'acadium-agent-publisher' ),
			'description'         => 'Changes fields of a post that is still a draft or pending review. Only the fields you pass are changed. Published and scheduled posts are refused: use update-published-post if the site allows it, or unpublish-post first.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'id' => array( 'type' => 'integer', 'description' => 'ID of the draft to change.' ),
				), $post_fields, array( 'featured_image' => $featured_image ) ),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $post_output,
			'execute_callback'    => array( __CLASS__, 'update_draft' ),
			'permission_callback' => function ( $input ) {
				if ( ! empty( $input['featured_image'] ) && ! current_user_can( 'upload_files' ) ) {
					return false;
				}
				return self::can_edit( $input['id'] ?? 0 );
			},
			'meta'                => self::meta( false, true, false ),
		) );

		wp_register_ability( 'agent-publisher/submit-for-review', array(
			'label'               => __( 'Submit a draft for review', 'acadium-agent-publisher' ),
			'description'         => 'Moves one of your drafts to "Pending review" so a site editor can review and publish it. Only when the site mode allows review (see agent-publisher/get-capabilities).',
			'category'            => self::CATEGORY,
			'input_schema'        => $id_only,
			'output_schema'       => $post_output,
			'execute_callback'    => array( __CLASS__, 'submit_for_review' ),
			'permission_callback' => function ( $input ) {
				if ( ! Agent_Publisher_Policy::allows( 'review' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'submit posts for review', 'review' );
				}
				return self::can_manage( $input['id'] ?? 0 );
			},
			'meta'                => self::meta( false, false, true ),
		) );

		wp_register_ability( 'agent-publisher/publish-post', array(
			'label'               => __( 'Publish or schedule a post', 'acadium-agent-publisher' ),
			'description'         => 'Publishes one of your existing drafts (or pending/scheduled posts) now, or schedules it when you pass a future date. For a new post, use create-post with status "publish" instead. Publishing is visible to site visitors: go ahead when the user asked you to publish; otherwise ask them first. The site\'s pre-publish checks run first (see agent-publisher/get-capabilities); if any fail, nothing changes and the error says what to fix. Only when the site mode allows publishing.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array( 'type' => 'integer', 'description' => 'Post ID.' ),
					'date' => array( 'type' => 'string', 'description' => 'Optional future publication time, ISO 8601 (e.g. 2026-10-01T09:00:00-04:00; without an offset the site\'s timezone is used). Omit to publish now.' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $publish_output,
			'execute_callback'    => array( __CLASS__, 'publish_post' ),
			'permission_callback' => function ( $input ) {
				if ( ! Agent_Publisher_Policy::allows( 'publish' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'publish posts', 'publish' );
				}
				$ok = self::can_manage( $input['id'] ?? 0 );
				if ( true !== $ok ) {
					return $ok;
				}
				return Agent_Publisher_Policy::can_publish( get_post_type_object( get_post_type( (int) $input['id'] ) ) )
					? true
					: new WP_Error( 'agent_publisher_role', 'Your WordPress role can\'t publish posts, so Claude can\'t publish them for you.', array( 'status' => 403 ) );
			},
			'meta'                => self::meta( false, true, false ),
		) );

		wp_register_ability( 'agent-publisher/unpublish-post', array(
			'label'               => __( 'Unpublish a post', 'acadium-agent-publisher' ),
			'description'         => 'Takes one of your published or scheduled posts offline by switching it back to draft (content is kept; it can be published again). Use it to undo a publish. Only when the site mode allows publishing.',
			'category'            => self::CATEGORY,
			'input_schema'        => $id_only,
			'output_schema'       => $post_output,
			'execute_callback'    => array( __CLASS__, 'unpublish_post' ),
			'permission_callback' => function ( $input ) {
				if ( ! Agent_Publisher_Policy::allows( 'publish' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'unpublish posts', 'publish' );
				}
				$ok = self::can_manage( $input['id'] ?? 0 );
				if ( true !== $ok ) {
					return $ok;
				}
				return Agent_Publisher_Policy::can_publish( get_post_type_object( get_post_type( (int) $input['id'] ) ) )
					? true
					: new WP_Error( 'agent_publisher_role', 'Your WordPress role can\'t publish or unpublish posts.', array( 'status' => 403 ) );
			},
			'meta'                => self::meta( false, true, false ),
		) );

		wp_register_ability( 'agent-publisher/update-published-post', array(
			'label'               => __( 'Update a published post', 'acadium-agent-publisher' ),
			'description'         => 'Changes fields of one of your posts that is already live, including replacing its featured image in one step (featured_image uploads a new one; featured_media uses one from the Media Library). Only the fields you pass are changed, and visitors see the change immediately: go ahead when the user asked for the change; otherwise ask them first. Only when the site mode is "Publish and edit live posts". Use get-post first to see the current version.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'id' => array( 'type' => 'integer', 'description' => 'ID of the published post to change.' ),
				), $post_fields, array( 'featured_image' => $featured_image ) ),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $publish_output,
			'execute_callback'    => array( __CLASS__, 'update_published' ),
			'permission_callback' => function ( $input ) {
				if ( ! Agent_Publisher_Policy::allows( 'publish_edit' ) ) {
					return Agent_Publisher_Policy::not_allowed( 'edit published posts', 'publish_edit' );
				}
				if ( ! empty( $input['featured_image'] ) && ! current_user_can( 'upload_files' ) ) {
					return false;
				}
				$ok = self::can_manage( $input['id'] ?? 0 );
				if ( true !== $ok ) {
					return $ok;
				}
				return Agent_Publisher_Policy::can_edit_published( get_post_type_object( get_post_type( (int) $input['id'] ) ) )
					? true
					: new WP_Error( 'agent_publisher_role', 'Your WordPress role can\'t edit published posts.', array( 'status' => 403 ) );
			},
			'meta'                => self::meta( false, true, false ),
		) );

		wp_register_ability( 'agent-publisher/upload-media', array(
			'label'               => __( 'Upload an image', 'acadium-agent-publisher' ),
			'description'         => 'Adds an image to the Media Library from a public https URL or from base64 data, with alt text, and returns its ID, URL and generated sizes to use in post content (<img src>). For a featured image, use featured_image on create-post / update-draft-post / update-published-post instead. base64 is only practical for small images: for large ones use a public url, or ask the user to upload the image in WordPress (Media > Add New) and find it with find-media. Optionally attaches the image to an existing draft and makes it that draft\'s featured image.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge( $image_source, array(
					'post_id'      => array( 'type' => 'integer', 'description' => 'Optional draft to attach the image to.' ),
					'set_featured' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Make it the featured image of post_id.' ),
				) ),
				'required'             => array( 'alt_text' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'width'    => array( 'type' => 'integer' ),
					'height'   => array( 'type' => 'integer' ),
					'alt'      => array( 'type' => 'string' ),
					'sizes'    => array( 'type' => 'object' ),
					'mime'     => array( 'type' => 'string' ),
					'bytes'    => array( 'type' => 'integer', 'description' => 'Size of the uploaded file.' ),
					'sha256'   => array( 'type' => 'string', 'description' => 'SHA-256 of the uploaded file, to compare with the original.' ),
					'warnings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Problems if this image is used as a featured image on this site.' ),
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

		wp_register_ability( 'agent-publisher/find-media', array(
			'label'               => __( 'Find images in the Media Library', 'acadium-agent-publisher' ),
			'description'         => 'Searches the Media Library for images by title or file name, newest first, e.g. images the user uploaded in WordPress. Use a result\'s id as featured_media, or its url in post content. This is the best way to use large or high-resolution images: ask the user to upload them under Media > Add New, then find them here.',
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search' => array( 'type' => 'string', 'description' => 'Words from the image title or file name. Omit to list the newest images.' ),
					'mine'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'Only images you uploaded.' ),
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'filename'    => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'width'       => array( 'type' => 'integer' ),
						'height'      => array( 'type' => 'integer' ),
						'alt'         => array( 'type' => 'string' ),
						'mime'        => array( 'type' => 'string' ),
						'date'        => array( 'type' => 'string' ),
						'uploaded_by' => array( 'type' => 'string' ),
						'attached_to' => array( 'type' => 'integer', 'description' => 'Post the image was uploaded to (0 if none).' ),
						'warnings'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Problems if used as a featured image on this site.' ),
					),
				),
			),
			'execute_callback'    => array( __CLASS__, 'find_media' ),
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
			'meta'                => self::meta( true, false, true ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Configuration                                                       */
	/* ------------------------------------------------------------------ */

	private static function post_types() {
		$types = array_values( array_filter( (array) apply_filters( 'agent_publisher_post_types', array( 'post' ) ), 'post_type_exists' ) );
		return $types ? $types : array( 'post' );
	}

	private static function max_upload() {
		return (int) apply_filters( 'agent_publisher_max_upload', 10 * MB_IN_BYTES );
	}

	private static function upload_mimes() {
		return (array) apply_filters( 'agent_publisher_upload_mimes', array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		) );
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
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) && (int) $post->post_author === get_current_user_id() ) {
			return new WP_Error(
				'agent_publisher_not_a_draft',
				sprintf( 'Post %d is "%s". Use update-published-post (if the site allows it) or unpublish-post first; see agent-publisher/get-capabilities.', $post->ID, $post->post_status ),
				array( 'status' => 409 )
			);
		}
		return false;
	}

	/**
	 * The user may read this post in any status: an AI Agent user its own
	 * posts (the role can't edit published posts, but may read them);
	 * anyone else what WordPress lets them edit.
	 */
	private static function can_read( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'agent_publisher_not_found', 'No such post.', array( 'status' => 404 ) );
		}
		if ( current_user_can( 'edit_post', $post->ID ) || ( Agent_Publisher_Policy::acting_for_agent() && (int) $post->post_author === get_current_user_id() ) ) {
			return true;
		}
		return new WP_Error( 'agent_publisher_not_yours', 'You can only read your own posts.', array( 'status' => 403 ) );
	}

	/**
	 * The post exists, is a supported type, and the current user may change
	 * its status: an AI Agent user its own posts (the role has no capability
	 * for published posts, so ownership is checked here and the site mode by
	 * each ability); anyone else only what WordPress lets them edit.
	 */
	private static function can_manage( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'agent_publisher_not_found', 'No such post.', array( 'status' => 404 ) );
		}
		if ( Agent_Publisher_Policy::acting_for_agent() ? (int) $post->post_author === get_current_user_id() : current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		return new WP_Error( 'agent_publisher_not_yours', 'You can only change your own posts.', array( 'status' => 403 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Callbacks                                                           */
	/* ------------------------------------------------------------------ */

	public static function get_capabilities() {
		$s     = Agent_Publisher_Policy::settings();
		$user  = wp_get_current_user();
		$type  = get_post_type_object( self::post_types()[0] );
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
				'publish'           => Agent_Publisher_Policy::allows( 'publish' ) && Agent_Publisher_Policy::can_publish( $type ),
				'schedule'          => Agent_Publisher_Policy::allows( 'publish' ) && Agent_Publisher_Policy::can_publish( $type ),
				'unpublish'         => Agent_Publisher_Policy::allows( 'publish' ) && Agent_Publisher_Policy::can_publish( $type ),
				'edit_published'    => Agent_Publisher_Policy::allows( 'publish_edit' ) && Agent_Publisher_Policy::can_edit_published( $type ),
			),
			'checks'     => array(
				'require_featured_image' => (bool) $s['require_featured_image'],
				'allowed_categories'     => $names,
				'daily_limit'            => (int) $s['daily_limit'],
				'published_last_24h'     => Agent_Publisher_Policy::published_last_24h(),
				'strict_image_checks'    => (bool) $s['image_checks_strict'],
			),
			'images'     => array(
				'how_to_add'           => 'Prefer a public https url, or find-media for images the user uploaded to the Media Library (the way to use large images). data_base64 is only practical for small images (under about 100 KB); pass sha256 of the file to verify it arrived intact. Never reduce an image\'s resolution to make it fit through data_base64: if a full-size image is too large to send, ask the user to upload it (Media > Add New) and use find-media.',
				'accepted_types'       => array_values( self::upload_mimes() ),
				'max_upload_bytes'     => self::max_upload(),
				'scaled_down_above_px' => (int) apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'generated_sizes'      => wp_get_registered_image_subsizes(),
				'featured_image'       => array(
					'min_width'         => Agent_Publisher_Policy::min_image_width(),
					'min_width_source'  => $s['image_min_width'] ? 'site setting' : 'default',
					'recommended_width' => Agent_Publisher_Policy::recommended_image_width(),
					'small_blocks_publishing' => $s['image_checks_strict'] || $s['image_block_small'],
					'aspect_ratios'     => array_keys( Agent_Publisher_Policy::aspect_ratios() ),
					'guidance'          => $s['image_guidance'],
				),
			),
			'user'       => array(
				'id'           => (int) $user->ID,
				'name'         => $user->display_name,
				'roles'        => array_values( $user->roles ),
				'posts_credit' => 'Posts you create are credited to ' . $user->display_name . ' and owned by them' . ( Agent_Publisher_Policy::is_agent_user() ? ' (an AI Agent user).' : ': they can edit them in WordPress.' ),
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

	public static function create_post( $input ) {
		$status = $input['status'] ?? 'draft';
		if ( ! empty( $input['date'] ) && 'publish' !== $status ) {
			return new WP_Error( 'agent_publisher_date_needs_publish', 'date is only used with status "publish".', array( 'status' => 400 ) );
		}
		if ( ! empty( $input['featured_image'] ) && isset( $input['featured_media'] ) ) {
			return new WP_Error( 'agent_publisher_two_images', 'Pass featured_image or featured_media, not both.', array( 'status' => 400 ) );
		}
		$valid = self::validate_extras( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$dates = null;
		if ( 'publish' === $status ) {
			$dates = self::publish_dates( $input['date'] ?? '' );
			if ( is_wp_error( $dates ) ) {
				return $dates;
			}
		}

		// Upload first, so a failed download leaves nothing behind.
		$uploaded = 0;
		if ( ! empty( $input['featured_image'] ) ) {
			$uploaded = self::sideload_image( $input['featured_image'], 0 );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
		}
		$image = $uploaded ? $uploaded : (int) ( $input['featured_media'] ?? 0 );

		$postarr = self::postarr( $input );
		$postarr['post_type']   = $input['post_type'] ?? self::post_types()[0];
		$postarr['post_status'] = 'pending' === $status ? 'pending' : 'draft';
		$postarr['post_author'] = get_current_user_id();
		if ( isset( $input['categories'] ) ) {
			$postarr['post_category'] = array_map( 'intval', $input['categories'] );
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			if ( $uploaded ) {
				wp_delete_attachment( $uploaded, true );
			}
			return $id;
		}
		if ( $uploaded ) {
			wp_update_post( array( 'ID' => $uploaded, 'post_parent' => $id ) );
		}
		if ( $image ) {
			set_post_thumbnail( $id, $image );
		}

		if ( 'publish' === $status ) {
			$checks = Agent_Publisher_Policy::check_publishable( get_post( $id ) );
			if ( is_wp_error( $checks ) ) {
				// Undo, so the agent can fix its input and call again without leaving a stray draft.
				wp_delete_post( $id, true );
				if ( $uploaded ) {
					wp_delete_attachment( $uploaded, true );
				}
				return new WP_Error(
					$checks->get_error_code(),
					str_replace( 'The post was left unchanged.', 'Nothing was created.', $checks->get_error_message() ),
					$checks->get_error_data()
				);
			}
		}

		self::save_extras( $id, $input );
		Agent_Publisher_Policy::log( 'create', $id );
		if ( $uploaded ) {
			Agent_Publisher_Policy::log( 'upload', $id, wp_get_attachment_url( $uploaded ) );
		}
		if ( 'pending' === $status ) {
			Agent_Publisher_Policy::log( 'submit', $id );
		}
		if ( 'publish' === $status ) {
			return self::apply_publish( get_post( $id ), $dates );
		}
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
		// Upload first: if it fails, nothing is changed.
		$uploaded = self::upload_featured( $input, $post->ID );
		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}
		$postarr       = self::postarr( $input );
		$postarr['ID'] = $post->ID;
		if ( count( $postarr ) > 1 ) {
			$id = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				if ( $uploaded ) {
					wp_delete_attachment( $uploaded, true );
				}
				return $id;
			}
		}
		self::save_extras( $post->ID, $input );
		if ( $uploaded ) {
			set_post_thumbnail( $post->ID, $uploaded );
			Agent_Publisher_Policy::log( 'upload', $post->ID, wp_get_attachment_url( $uploaded ) );
		}
		Agent_Publisher_Policy::log( 'update', $post->ID );
		return self::summary( get_post( $post->ID ) );
	}

	public static function submit_for_review( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( 'pending' === $post->post_status ) {
			return self::summary( $post );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true ) ) {
			return new WP_Error( 'agent_publisher_not_a_draft', sprintf( 'Post %d is "%s"; only drafts can be submitted for review.', $post->ID, $post->post_status ), array( 'status' => 409 ) );
		}
		$id = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'pending' ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Agent_Publisher_Policy::log( 'submit', $post->ID );
		return self::summary( get_post( $post->ID ) );
	}

	public static function publish_post( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'agent_publisher_already_published', sprintf( 'Post %d is already published: %s', $post->ID, get_permalink( $post ) ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future' ), true ) ) {
			return new WP_Error( 'agent_publisher_bad_status', sprintf( 'Post %d is "%s" and cannot be published by an agent.', $post->ID, $post->post_status ), array( 'status' => 409 ) );
		}

		$dates = self::publish_dates( $input['date'] ?? '' );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		// Rescheduling an already scheduled post doesn't count toward the daily limit again.
		$checks = Agent_Publisher_Policy::check_publishable( $post, 'future' !== $post->post_status );
		if ( is_wp_error( $checks ) ) {
			return $checks;
		}
		return self::apply_publish( $post, $dates );
	}

	/** [ local, gmt ] publication dates: now, or a future ISO 8601 time. */
	private static function publish_dates( $date ) {
		if ( '' === (string) $date ) {
			return array( current_time( 'mysql' ), current_time( 'mysql', true ) );
		}
		$dates = rest_get_date_with_gmt( $date );
		if ( ! $dates ) {
			return new WP_Error( 'agent_publisher_bad_date', 'date must be ISO 8601, e.g. 2026-10-01T09:00:00-04:00.', array( 'status' => 400 ) );
		}
		if ( strtotime( $dates[1] . ' UTC' ) <= time() + MINUTE_IN_SECONDS ) {
			return new WP_Error( 'agent_publisher_past_date', 'date must be in the future. Omit it to publish now.', array( 'status' => 400 ) );
		}
		return $dates;
	}

	/** Publish (or schedule, for a future date) a post that passed the pre-publish checks. */
	private static function apply_publish( $post, $dates ) {
		$id = wp_update_post( array(
			'ID'            => $post->ID,
			'post_status'   => 'publish',
			'edit_date'     => true,
			'post_date'     => $dates[0],
			'post_date_gmt' => $dates[1],
		), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $post->ID );
		Agent_Publisher_Policy::log( 'future' === $post->post_status ? 'schedule' : 'publish', $post->ID, 'future' === $post->post_status ? $post->post_date : '' );
		return self::published_summary( $post );
	}

	public static function unpublish_post( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return new WP_Error( 'agent_publisher_not_published', sprintf( 'Post %d is "%s", not published or scheduled.', $post->ID, $post->post_status ), array( 'status' => 409 ) );
		}
		$id = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Agent_Publisher_Policy::log( 'unpublish', $post->ID );
		return self::summary( get_post( $post->ID ) );
	}

	public static function update_published( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'agent_publisher_not_published', sprintf( 'Post %d is "%s"; use update-draft-post for unpublished posts.', $post->ID, $post->post_status ), array( 'status' => 409 ) );
		}
		if ( ( isset( $input['title'] ) && '' === trim( $input['title'] ) ) || ( isset( $input['content'] ) && '' === trim( wp_strip_all_tags( $input['content'] ) ) ) ) {
			return new WP_Error( 'agent_publisher_empty', 'A published post cannot have an empty title or content.', array( 'status' => 400 ) );
		}
		$valid = self::validate_extras( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$allowed = Agent_Publisher_Policy::settings()['allowed_categories'];
		if ( $allowed && isset( $input['categories'] ) && ( ! $input['categories'] || array_diff( array_map( 'intval', $input['categories'] ), $allowed ) ) ) {
			return new WP_Error( 'agent_publisher_checks_failed', 'Agents may only use these category IDs on published posts: ' . implode( ', ', $allowed ) . '.', array( 'status' => 422 ) );
		}

		$uploaded = self::upload_featured( $input, $post->ID );
		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}
		// A live post's new featured image must pass the same image checks as a newly published one.
		$new_image = $uploaded ? $uploaded : (int) ( $input['featured_media'] ?? 0 );
		if ( $new_image ) {
			$problems = Agent_Publisher_Policy::image_problems( $new_image );
			if ( $problems ) {
				if ( $uploaded ) {
					wp_delete_attachment( $uploaded, true );
				}
				return new WP_Error( 'agent_publisher_checks_failed', 'Cannot use this featured image: ' . implode( '; ', $problems ) . '. The post was left unchanged.', array( 'status' => 422 ) );
			}
		}

		$postarr       = self::postarr( $input );
		$postarr['ID'] = $post->ID;
		if ( count( $postarr ) > 1 ) {
			$id = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				if ( $uploaded ) {
					wp_delete_attachment( $uploaded, true );
				}
				return $id;
			}
		}
		self::save_extras( $post->ID, $input );
		if ( $uploaded ) {
			set_post_thumbnail( $post->ID, $uploaded );
			Agent_Publisher_Policy::log( 'upload', $post->ID, wp_get_attachment_url( $uploaded ) );
		}
		if ( $uploaded || count( $postarr ) > 1 || array_intersect_key( $input, array_flip( array( 'categories', 'tags', 'featured_media', 'meta' ) ) ) ) {
			Agent_Publisher_Policy::log( 'update_published', $post->ID );
		}
		return self::published_summary( get_post( $post->ID ) );
	}

	public static function upload_media( $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$id      = self::sideload_image( $input, $post_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $post_id && ! empty( $input['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $id );
		}
		Agent_Publisher_Policy::log( 'upload', $post_id, wp_get_attachment_url( $id ) );

		$file = wp_get_original_image_path( $id );
		return self::image_info( $id ) + array(
			'mime'     => get_post_mime_type( $id ),
			'bytes'    => $file ? (int) filesize( $file ) : 0,
			'sha256'   => $file ? hash_file( 'sha256', $file ) : '',
			'warnings' => Agent_Publisher_Policy::image_warnings( $id ),
		);
	}

	public static function find_media( $input = array() ) {
		$limit  = max( 1, min( 50, (int) ( $input['limit'] ?? 20 ) ) );
		$search = trim( (string) ( $input['search'] ?? '' ) );
		$query  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( ! empty( $input['mine'] ) ) {
			$query['author'] = get_current_user_id();
		}
		if ( '' === $search ) {
			$ids = get_posts( $query );
		} else {
			// Title/caption matches, plus file name matches (not covered by WordPress search).
			$ids = array_unique( array_merge(
				get_posts( $query + array( 's' => $search ) ),
				get_posts( $query + array( 'meta_query' => array( array( 'key' => '_wp_attached_file', 'value' => $search, 'compare' => 'LIKE' ) ) ) ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by posts_per_page.
			) );
			rsort( $ids );
			$ids = array_slice( $ids, 0, $limit );
		}
		return array_map( function ( $id ) {
			$att    = get_post( $id );
			$author = get_userdata( (int) $att->post_author );
			$info   = self::image_info( $id );
			unset( $info['sizes'] );
			return $info + array(
				'title'       => html_entity_decode( get_the_title( $id ) ),
				'filename'    => wp_basename( (string) get_attached_file( $id ) ),
				'mime'        => get_post_mime_type( $id ),
				'date'        => mysql2date( 'c', $att->post_date, false ),
				'uploaded_by' => $author ? $author->display_name : '',
				'attached_to' => (int) $att->post_parent,
				'warnings'    => Agent_Publisher_Policy::image_warnings( $id ),
			);
		}, array_map( 'intval', $ids ) );
	}

	/**
	 * featured_image on the update abilities: upload it (attached to the post)
	 * before anything else changes. Returns the attachment ID, 0 if none, or an error.
	 */
	private static function upload_featured( $input, $post_id ) {
		if ( empty( $input['featured_image'] ) ) {
			return 0;
		}
		if ( isset( $input['featured_media'] ) ) {
			return new WP_Error( 'agent_publisher_two_images', 'Pass featured_image or featured_media, not both.', array( 'status' => 400 ) );
		}
		return self::sideload_image( $input['featured_image'], $post_id );
	}

	/** Base64 to bytes, tolerating whitespace, data: prefixes, URL-safe characters and missing padding; precise errors otherwise. */
	private static function decode_base64( $data ) {
		$data = preg_replace( '/^data:[^,]*,/', '', (string) $data );
		$data = strtr( preg_replace( '/\s+/', '', $data ), '-_', '+/' );
		if ( preg_match( '/[^A-Za-z0-9+\/=]/', $data, $m, PREG_OFFSET_CAPTURE ) ) {
			return new WP_Error( 'agent_publisher_bad_base64', sprintf( 'data_base64 has an invalid character %1$s at position %2$d of %3$d (whitespace ignored). Only A-Z, a-z, 0-9, + and / are allowed, with = padding at the end. Nothing was saved.', wp_json_encode( $m[0][0] ), $m[0][1], strlen( $data ) ), array( 'status' => 400 ) );
		}
		$body = rtrim( $data, '=' );
		$pad  = strpos( $body, '=' );
		if ( false !== $pad ) {
			return new WP_Error( 'agent_publisher_bad_base64', sprintf( 'data_base64 has "=" at position %1$d of %2$d; "=" may only appear at the end. Nothing was saved.', $pad, strlen( $data ) ), array( 'status' => 400 ) );
		}
		if ( 1 === strlen( $body ) % 4 ) {
			return new WP_Error( 'agent_publisher_bad_base64', sprintf( 'data_base64 has %d characters (without padding), which is not a valid base64 length: a character is missing or extra somewhere. Nothing was saved.', strlen( $body ) ), array( 'status' => 400 ) );
		}
		$bytes = base64_decode( $body . str_repeat( '=', ( 4 - strlen( $body ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image bytes sent by the agent.
		if ( false === $bytes ) {
			return new WP_Error( 'agent_publisher_bad_base64', 'data_base64 is not valid base64. Nothing was saved.', array( 'status' => 400 ) );
		}
		return $bytes;
	}

	/** An image attachment: id, url, dimensions, alt text and the sizes WordPress generated. */
	private static function image_info( $id ) {
		$meta  = wp_get_attachment_metadata( $id );
		$sizes = array();
		foreach ( array_keys( (array) ( $meta['sizes'] ?? array() ) ) as $name ) {
			$src = wp_get_attachment_image_src( $id, $name );
			if ( $src ) {
				$sizes[ $name ] = array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] );
			}
		}
		return array(
			'id'     => (int) $id,
			'url'    => (string) wp_get_attachment_url( $id ),
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'sizes'  => (object) $sizes,
		);
	}

	/**
	 * Download (https URL) or decode (base64) an image, check its size and real
	 * type, and add it to the Media Library with alt text.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload_image( $input, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$max = self::max_upload();

		if ( ! empty( $input['url'] ) ) {
			$url = esc_url_raw( $input['url'], array( 'https' ) );
			if ( ! $url ) {
				return new WP_Error( 'agent_publisher_bad_url', 'url must be a public https URL.', array( 'status' => 400 ) );
			}
			// wp_safe_remote_get() refuses private/local addresses; the download
			// stops at the size limit instead of fetching the whole file first.
			$tmp      = wp_tempnam( $url );
			$response = wp_safe_remote_get( $url, array(
				'timeout'             => 30,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
			) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				wp_delete_file( $tmp );
				return is_wp_error( $response ) ? $response : new WP_Error( 'agent_publisher_download_failed', sprintf( 'Could not download the image (HTTP %d).', (int) wp_remote_retrieve_response_code( $response ) ), array( 'status' => 400 ) );
			}
			$name = ! empty( $input['filename'] ) ? $input['filename'] : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
		} elseif ( ! empty( $input['data_base64'] ) ) {
			if ( empty( $input['filename'] ) ) {
				return new WP_Error( 'agent_publisher_missing_filename', 'filename is required with data_base64.', array( 'status' => 400 ) );
			}
			$bytes = self::decode_base64( $input['data_base64'] );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			if ( strlen( $bytes ) > $max ) {
				return new WP_Error( 'agent_publisher_too_large', sprintf( 'Image is larger than %s.', size_format( $max ) ), array( 'status' => 413 ) );
			}
			$tmp = wp_tempnam( $input['filename'] );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file from wp_tempnam(), handed to media_handle_sideload().
			$name = $input['filename'];
		} else {
			return new WP_Error( 'agent_publisher_no_source', 'Provide url or data_base64.', array( 'status' => 400 ) );
		}

		if ( filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'agent_publisher_too_large', sprintf( 'Image is larger than %s.', size_format( $max ) ), array( 'status' => 413 ) );
		}

		if ( ! empty( $input['sha256'] ) ) {
			$expected = strtolower( trim( (string) $input['sha256'] ) );
			$actual   = hash_file( 'sha256', $tmp );
			if ( ! hash_equals( $expected, $actual ) ) {
				$size = (int) filesize( $tmp );
				wp_delete_file( $tmp );
				return new WP_Error( 'agent_publisher_checksum', sprintf( 'Checksum mismatch: received %1$d bytes with sha256 %2$s, but sha256 %3$s was expected. The image data was changed or cut off on the way; send it again. Nothing was saved.', $size, $actual, $expected ), array( 'status' => 400 ) );
			}
		}

		// Check the real content type, not just the extension.
		$mimes = self::upload_mimes();
		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		if ( empty( $check['type'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'agent_publisher_bad_type', 'Only these file types are accepted: ' . implode( ', ', array_values( $mimes ) ) . '.', array( 'status' => 415 ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}

		$id = media_handle_sideload(
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
		// Lets image_warnings() tell an agent that shrank an image to fit base64 to ask for an upload instead.
		update_post_meta( $id, '_agent_publisher_source', ! empty( $input['url'] ) ? 'url' : 'base64' );

		return (int) $id;
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

	private static function published_summary( $post ) {
		return self::summary( $post ) + array(
			'link' => get_permalink( $post ),
			'date' => ( $dt = get_post_datetime( $post ) ) ? $dt->format( 'c' ) : '',
		);
	}

	private static function summary( $post ) {
		$out   = array(
			'id'          => (int) $post->ID,
			'status'      => $post->post_status,
			'title'       => html_entity_decode( get_the_title( $post ) ),
			'edit_url'    => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'preview_url' => get_preview_post_link( $post ),
		);
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$out['featured_image'] = self::image_info( $thumb );
			$warnings              = Agent_Publisher_Policy::image_warnings( $thumb );
			if ( $warnings ) {
				$out['image_warnings'] = $warnings;
			}
		}
		return $out;
	}
}
