<?php
/**
 * Ability: retrieve full content of a single post.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/get-post ability.
 *
 * @since 1.0.0
 */
class GetPostAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/get-post', array(
			'label'               => __( 'Get Post', 'gn-mcp-bridge' ),
			'description'         => __( 'Retrieve full content of a single post by ID or slug.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Post ID.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Post slug.',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type to look up by slug. Defaults to "post"; must be one of the post types allowed on this site (see gn_mcp_bridge_allowed_post_types). Ignored when looking up by id.',
						'default'     => 'post',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array( 'type' => 'integer' ),
					'title'      => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'excerpt'    => array( 'type' => 'string' ),
					'url'        => array( 'type' => 'string' ),
					'date'       => array( 'type' => 'string' ),
					'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'status'     => array( 'type' => 'string' ),
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
	 * @since 1.0.0
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission() {
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/get-post' ) );
	}

	/**
	 * Execute the get-post ability.
	 *
	 * @since 1.0.0
	 * @since 1.4.4 Added `comment_status`/`ping_status` to the returned data.
	 * @param array $args Input arguments: id or slug, post_type.
	 * @return array|\WP_Error Post data or error.
	 */
	public static function execute( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';

		if ( ! in_array( $post_type, \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_type_not_allowed', __( 'This post type is not allowed on this site.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		$post = null;

		if ( ! empty( $args['id'] ) ) {
			$post = get_post( absint( $args['id'] ) );
		} elseif ( ! empty( $args['slug'] ) ) {
			$posts = get_posts( array(
				'name'           => sanitize_title( $args['slug'] ),
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
			) );
			$post  = ! empty( $posts ) ? $posts[0] : null;
		}

		if ( ! $post || ! in_array( get_post_type( $post ), \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'gn-mcp-bridge' ) );
		}

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		return array(
			'id'             => $post->ID,
			'title'          => get_the_title( $post ),
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'url'            => (string) get_permalink( $post ),
			'date'           => $post->post_date,
			'categories'     => is_array( $categories ) ? $categories : array(),
			'tags'           => is_array( $tags ) ? $tags : array(),
			'status'         => $post->post_status,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
		);
	}
}
