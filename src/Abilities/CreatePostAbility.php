<?php
/**
 * Ability: create a new post.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/create-post ability.
 *
 * @since 1.0.0
 */
class CreatePostAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/create-post', array(
			'label'               => __( 'Create Post', 'gn-mcp-bridge' ),
			'description'         => __( 'Create a new post.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'title', 'content' ),
				'properties' => array(
					'title'      => array(
						'type'        => 'string',
						'description' => 'Post title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'Post body as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->. Plain HTML without block comments is stored as-is but opens in the editor as a single Classic block.',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'Post excerpt.',
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Category names or slugs.',
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Tag names.',
					),
					'status'     => array(
						'type'        => 'string',
						'description' => 'Post status (draft, publish, pending).',
						'default'     => 'draft',
					),
					'post_type'  => array(
						'type'        => 'string',
						'description' => 'Post type to create. Defaults to "post"; must be one of the post types allowed on this site (see gn_mcp_bridge_allowed_post_types).',
						'default'     => 'post',
					),
					'comment_status' => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Whether comments are allowed on this post. Defaults to the site\'s default_comment_status.',
					),
					'ping_status'    => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Whether pingbacks/trackbacks are allowed on this post. Defaults to the site\'s default_ping_status.',
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
	 * Beyond the ability's default capability, a request that asks to create
	 * the post directly as `publish` also requires that post type's own
	 * publish capability — closes the gap where a Contributor-tier default
	 * would otherwise let a Contributor publish directly, bypassing
	 * WordPress's own contributor-can't-publish rule. The capability checked
	 * is resolved from the target post type's own registration
	 * (`get_post_type_object()->cap->publish_posts`) rather than hardcoded to
	 * the literal `publish_posts` string, since that string is `post`-specific
	 * — a `page` maps to `publish_pages`, a `wp_block` to `publish_blocks`,
	 * and a caller could hold the generic capability without holding the
	 * post type's own one.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Added the status-aware `publish_posts` check.
	 * @since 1.4.0 Resolved the publish capability per post type instead of
	 *              hardcoding `publish_posts` (needed for `wp_block`, whose
	 *              capability is `publish_blocks`).
	 * @param array $args Input arguments (see input_schema).
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission( $args = array() ) {
		if ( ! current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/create-post' ) ) ) {
			return false;
		}

		$post_type   = sanitize_key( $args['post_type'] ?? 'post' );
		$type_object = get_post_type_object( $post_type );
		$status      = sanitize_key( $args['status'] ?? 'draft' );

		if ( 'publish' === $status ) {
			$capability = $type_object ? $type_object->cap->publish_posts : 'publish_posts';

			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Execute the create-post ability.
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
	 * @return array|\WP_Error Created post data or error.
	 */
	public static function execute( $args ) {
		$title     = sanitize_text_field( $args['title'] ?? '' );
		$content   = (string) ( $args['content'] ?? '' );
		// Same rule as core: only users without unfiltered_html get kses. filter_block_content() runs first: it sanitizes
		// block attribute values and re-escapes their HTML as unicode escapes — otherwise core's wp_pre_kses_less_than() sees a raw
		// `<` inside the block comment JSON and HTML-encodes the whole comment.
		$content   = current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( filter_block_content( $content ) );
		$excerpt   = sanitize_text_field( $args['excerpt'] ?? '' );
		$status    = sanitize_key( $args['status'] ?? 'draft' );
		$post_type = sanitize_key( $args['post_type'] ?? 'post' );

		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			$status = 'draft';
		}

		if ( ! in_array( $post_type, \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_type_not_allowed', __( 'This post type is not allowed on this site.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => wp_slash( $content ), // wp_insert_post() unslashes — keep block JSON backslashes.
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => $post_type,
		);

		if ( isset( $args['comment_status'] ) && in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ) {
			$post_data['comment_status'] = $args['comment_status'];
		}

		if ( isset( $args['ping_status'] ) && in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ) {
			$post_data['ping_status'] = $args['ping_status'];
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $args['categories'] ) ) {
			$category_ids = self::resolve_category_ids( (array) $args['categories'] );
			wp_set_post_categories( $post_id, $category_ids );
		}

		if ( ! empty( $args['tags'] ) ) {
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
