<?php
/**
 * Ability: find existing posts by keyword, category, or tag.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/search-posts ability.
 *
 * @since 1.0.0
 */
class SearchPostsAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/search-posts', array(
			'label'               => __( 'Search Posts', 'gn-mcp-bridge' ),
			'description'         => __( 'Find existing posts by keyword, category, or tag.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'keyword'  => array(
						'type'        => 'string',
						'description' => 'Keyword to search for in post title and content.',
					),
					'category' => array(
						'type'        => 'string',
						'description' => 'Category slug to filter posts.',
					),
					'tag'      => array(
						'type'        => 'string',
						'description' => 'Tag slug to filter posts.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Maximum number of posts to return.',
						'default'     => 5,
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type to search. Defaults to "post"; must be one of the post types allowed on this site (see gn_mcp_bridge_allowed_post_types).',
						'default'     => 'post',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'title'      => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'date'       => array( 'type' => 'string' ),
						'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
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
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/search-posts' ) );
	}

	/**
	 * Execute the search-posts ability.
	 *
	 * @since 1.0.0
	 * @param array $args Input arguments: keyword, category, tag, limit, post_type.
	 * @return array|\WP_Error List of matching posts, or error.
	 */
	public static function execute( $args ) {
		$keyword   = isset( $args['keyword'] ) ? sanitize_text_field( $args['keyword'] ) : '';
		$category  = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
		$tag       = isset( $args['tag'] ) ? sanitize_text_field( $args['tag'] ) : '';
		$limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 5;
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';

		if ( ! in_array( $post_type, \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_type_not_allowed', __( 'This post type is not allowed on this site.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		);

		if ( $keyword ) {
			$query_args['s'] = $keyword;
		}

		$tax_query = array();

		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => $category,
			);
		}

		if ( $tag ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => $tag,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$tax_query['relation']   = 'AND';
			$query_args['tax_query'] = $tax_query;
		}

		$query   = new \WP_Query( $query_args );
		$results = array();

		foreach ( $query->posts as $post ) {
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

			$results[] = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'excerpt'    => get_the_excerpt( $post ),
				'url'        => get_permalink( $post ),
				'date'       => $post->post_date,
				'categories' => is_array( $categories ) ? $categories : array(),
				'tags'       => is_array( $tags ) ? $tags : array(),
			);
		}

		return $results;
	}
}
