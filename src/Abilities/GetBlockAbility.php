<?php
/**
 * Ability: retrieve the raw markup of one block of a post.
 *
 * @package gn-mcp-bridge
 * @since   1.5.0
 */

namespace GN\McpBridge\Abilities;

use GN\McpBridge\BlockLocator;

/**
 * Registers and executes the gn-mcp/get-block ability.
 *
 * @since 1.5.0
 */
class GetBlockAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/get-block', array(
			'label'               => __( 'Get Block', 'gn-mcp-bridge' ),
			'description'         => __( 'Retrieve the raw markup of one block of a post (inner blocks included), located by path or by metadata name + occurrence as listed by get-post-outline. Returns the post\'s content_hash to pass to update-block.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => self::target_properties(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_hash' => array( 'type' => 'string' ),
					'block'        => array( 'type' => 'object' ),
					'markup'       => array( 'type' => 'string' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Input schema properties targeting one block, shared with update-block.
	 *
	 * @since 1.5.0
	 * @return array Schema properties.
	 */
	public static function target_properties() {
		return array(
			'id'         => array(
				'type'        => 'integer',
				'description' => 'Post ID.',
			),
			'path'       => array(
				'type'        => 'string',
				'description' => 'Block path from get-post-outline, e.g. "4/1/0" (child indexes, freeform gaps not counted). Use either path or name.',
			),
			'name'       => array(
				'type'        => 'string',
				'description' => 'Block metadata name (the editor\'s "Rename" field). Refused as ambiguous when several blocks share it and no occurrence is given.',
			),
			'occurrence' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => '0-based index among blocks sharing the same name, in document order. Only with name.',
			),
		);
	}

	/**
	 * Permission check for this ability.
	 *
	 * @since 1.5.0
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission() {
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/get-block' ) );
	}

	/**
	 * Execute the get-block ability.
	 *
	 * @since 1.5.0
	 * @param array $args Input arguments: id, path or name + occurrence.
	 * @return array|\WP_Error Block markup or error.
	 */
	public static function execute( $args ) {
		$post = BlockLocator::load_post( $args['id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content = $post->post_content;
		$node    = BlockLocator::resolve( BlockLocator::scan( $content ), $args );

		if ( is_wp_error( $node ) ) {
			return $node;
		}

		return array(
			'id'           => $post->ID,
			'content_hash' => BlockLocator::content_hash( $content ),
			'block'        => BlockLocator::summary( $node ),
			'markup'       => BlockLocator::markup( $content, $node ),
		);
	}
}
