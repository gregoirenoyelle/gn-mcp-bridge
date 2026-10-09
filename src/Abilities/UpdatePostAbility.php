<?php
/**
 * Ability: update an existing post.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/update-post ability.
 *
 * @since 1.0.0
 */
class UpdatePostAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/update-post', array(
			'label'               => __( 'Update Post', 'gn-mcp-bridge' ),
			'description'         => __( 'Update an existing post (title, content, excerpt, status, categories, tags).', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'ID of the post to update.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'Post title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'Post body as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->. Plain HTML without block comments is stored as-is but opens in the editor as a single Classic block. Replaces the whole post body.',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'Post excerpt.',
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Category names or slugs. Replaces the post\'s current categories.',
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Tag names. Replaces the post\'s current tags.',
					),
					'status'     => array(
						'type'        => 'string',
						'description' => 'Post status (draft, publish, pending, private).',
					),
					'comment_status' => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Whether comments are allowed on this post.',
					),
					'ping_status'    => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Whether pingbacks/trackbacks are allowed on this post.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'url'            => array( 'type' => 'string' ),
					'edit_url'       => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'comment_status' => array( 'type' => 'string' ),
					'ping_status'    => array( 'type' => 'string' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Permission check for this ability.
	 *
	 * The ability's default capability (`edit_posts`) is generic across post
	 * types, but WordPress's default roles don't necessarily bundle the
	 * generic `edit_posts`/`publish_posts` with a given post type's own
	 * mapped capabilities — e.g. a `page` uses `edit_pages`/`publish_pages`,
	 * a `wp_block` uses `edit_blocks`/`publish_blocks`, neither of which a
	 * Contributor/Author necessarily holds even though they hold the generic
	 * ones. When the target post is anything other than the built-in `post`
	 * type, this resolves the real capability names from the post type's own
	 * registration (`get_post_type_object()->cap`) instead of assuming
	 * `post`'s generic capabilities apply — the per-post `edit_post` check in
	 * execute() still applies afterward and is unaffected by this change,
	 * since `map_meta_cap()` already resolves it to the right primitive
	 * capability per post type.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Added the post-type-aware `edit_pages`/`publish_pages` check.
	 * @since 1.4.0 Generalized from a `page`-only special case to any post type
	 *              (needed for `wp_block`, whose capabilities are `edit_blocks`/
	 *              `publish_blocks`, not `edit_pages`/`publish_pages`).
	 * @param array $args Input arguments (see input_schema).
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission( $args = array() ) {
		$post_id     = absint( $args['id'] ?? 0 );
		$post        = $post_id ? get_post( $post_id ) : null;
		$post_type   = $post ? get_post_type( $post ) : null;
		$type_object = $post_type ? get_post_type_object( $post_type ) : null;

		if ( ! $type_object || 'post' === $post_type ) {
			return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/update-post' ) );
		}

		$status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : null;
		$capability = in_array( $status, array( 'publish', 'private' ), true ) ? $type_object->cap->publish_posts : $type_object->cap->edit_posts;

		return current_user_can( $capability );
	}

	/**
	 * Execute the update-post ability.
	 *
	 * @since 1.0.0
	 * @since 1.4.1 Skip `wp_kses_post()` for users with `unfiltered_html` (as core
	 *              does) and slash content before saving, so block comment JSON
	 *              with nested HTML or backslash escapes survives intact.
	 * @since 1.4.2 Run `filter_block_content()` before `wp_kses_post()` for users
	 *              without `unfiltered_html`, so block attributes holding raw
	 *              HTML are sanitized and re-escaped instead of the whole block
	 *              comment being HTML-encoded.
	 * @since 1.4.4 Added optional `comment_status`/`ping_status`.
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Updated post data or error.
	 */
	public static function execute( $args ) {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( get_post_type( $post ), \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_not_found', __( 'Post not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to edit this post.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		if ( isset( $args['status'] ) && in_array( sanitize_key( $args['status'] ), array( 'publish', 'private' ), true )
			&& ! current_user_can( 'publish_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to publish this post.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		$update = array( 'ID' => $post_id );

		if ( array_key_exists( 'title', $args ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}

		if ( array_key_exists( 'content', $args ) ) {
			// Same rule as core: only users without unfiltered_html get kses. filter_block_content() runs first: it sanitizes
			// block attribute values and re-escapes their HTML as unicode escapes — otherwise core's wp_pre_kses_less_than() sees a raw
			// `<` inside the block comment JSON and HTML-encodes the whole comment.
			// Slashed because wp_insert_post() unslashes, which would strip backslashes from block JSON (unicode and quote escapes).
			$content                = current_user_can( 'unfiltered_html' ) ? $args['content'] : wp_kses_post( filter_block_content( $args['content'] ) );
			$update['post_content'] = wp_slash( $content );
		}

		if ( array_key_exists( 'excerpt', $args ) ) {
			$update['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
		}

		if ( array_key_exists( 'status', $args ) ) {
			$status = sanitize_key( $args['status'] );

			if ( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				$update['post_status'] = $status;
			}
		}

		if ( array_key_exists( 'comment_status', $args ) && in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ) {
			$update['comment_status'] = $args['comment_status'];
		}

		if ( array_key_exists( 'ping_status', $args ) && in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ) {
			$update['ping_status'] = $args['ping_status'];
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( array_key_exists( 'categories', $args ) ) {
			$category_ids = self::resolve_category_ids( (array) $args['categories'] );
			wp_set_post_categories( $post_id, $category_ids );
		}

		if ( array_key_exists( 'tags', $args ) ) {
			wp_set_post_tags( $post_id, (array) $args['tags'] );
		}

		return array(
			'id'             => $post_id,
			'url'            => (string) get_permalink( $post_id ),
			'edit_url'       => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'status'         => get_post_status( $post_id ),
			'comment_status' => get_post_field( 'comment_status', $post_id ),
			'ping_status'    => get_post_field( 'ping_status', $post_id ),
		);
	}

	/**
	 * Resolve category names/slugs to IDs, creating them if needed.
	 *
	 * @since 1.0.0
	 * @param array $categories Category names or slugs.
	 * @return int[] Category IDs.
	 */
	protected static function resolve_category_ids( array $categories ) {
		$ids = array();

		foreach ( $categories as $cat ) {
			$cat  = sanitize_text_field( $cat );
			$term = get_term_by( 'name', $cat, 'category' );

			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $cat ), 'category' );
			}

			if ( $term ) {
				$ids[] = $term->term_id;
			} else {
				$result = wp_insert_term( $cat, 'category' );
				if ( ! is_wp_error( $result ) ) {
					$ids[] = $result['term_id'];
				}
			}
		}

		return $ids;
	}
}
