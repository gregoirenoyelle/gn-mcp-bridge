<?php
/**
 * Ability: summarize a post's block tree without returning its content.
 *
 * @package gn-mcp-bridge
 * @since   1.5.0
 */

namespace GN\McpBridge\Abilities;

use GN\McpBridge\BlockLocator;

/**
 * Registers and executes the gn-mcp/get-post-outline ability.
 *
 * @since 1.5.0
 */
class GetPostOutlineAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/get-post-outline', array(
			'label'               => __( 'Get Post Outline', 'gn-mcp-bridge' ),
			'description'         => __( 'Summarize a post\'s block tree (path, block name, metadata name, occurrence, size) without its content, plus a content_hash to pass to update-block. Use it before get-block/update-block on long posts instead of get-post.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => 'Post ID.',
					),
					'max_depth' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'List every block down to this depth (0 = top level only). Omitted: top-level blocks, every block with a metadata name (the name set in the editor\'s "Rename" field), and their direct children.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'title'        => array( 'type' => 'string' ),
					'content_hash' => array( 'type' => 'string' ),
					'bytes'        => array( 'type' => 'integer' ),
					'block_count'  => array( 'type' => 'integer' ),
					'listed_count' => array( 'type' => 'integer' ),
					'blocks'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'path'       => array( 'type' => 'string' ),
								'blockName'  => array( 'type' => 'string' ),
								'name'       => array( 'type' => 'string' ),
								'occurrence' => array( 'type' => 'integer' ),
								'depth'      => array( 'type' => 'integer' ),
								'bytes'      => array( 'type' => 'integer' ),
								'children'   => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Permission check for this ability.
	 *
	 * @since 1.5.0
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission() {
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/get-post-outline' ) );
	}

	/**
	 * Execute the get-post-outline ability.
	 *
	 * @since 1.5.0
	 * @param array $args Input arguments: id, max_depth.
	 * @return array|\WP_Error Outline or error.
	 */
	public static function execute( $args ) {
		$post = BlockLocator::load_post( $args['id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content   = $post->post_content;
		$nodes     = BlockLocator::scan( $content );
		$max_depth = isset( $args['max_depth'] ) ? absint( $args['max_depth'] ) : null;
		$blocks    = array();

		foreach ( $nodes as $node ) {
			if ( null !== $max_depth ) {
				$listed = $node['depth'] <= $max_depth;
			} else {
				$listed = 0 === $node['depth'] || null !== $node['name'] || ( -1 !== $node['parent'] && null !== $nodes[ $node['parent'] ]['name'] );
			}

			if ( $listed ) {
				$blocks[] = BlockLocator::summary( $node );
			}
		}

		return array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post ),
			'content_hash' => BlockLocator::content_hash( $content ),
			'bytes'        => strlen( $content ),
			'block_count'  => count( $nodes ),
			'listed_count' => count( $blocks ),
			'blocks'       => $blocks,
		);
	}
}
