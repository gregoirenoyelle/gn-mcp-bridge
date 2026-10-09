<?php
/**
 * Ability: read custom fields (post meta) of an existing post.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/get-post-meta ability.
 *
 * @since 1.0.0
 */
class GetPostMetaAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/get-post-meta', array(
			'label'               => __( 'Get Post Meta', 'gn-mcp-bridge' ),
			'description'         => __( 'Read custom fields (post meta) of an existing post.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'ID of the post to read meta from.',
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Meta keys to return. Omit to return every allowed (non-protected) key.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'integer' ),
					'meta' => array( 'type' => 'object' ),
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
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/get-post-meta' ) );
	}

	/**
	 * Execute the get-post-meta ability.
	 *
	 * @since 1.0.0
	 * @param array $args Input arguments: id, keys.
	 * @return array|\WP_Error Post meta, or error.
	 */
	public static function execute( $args ) {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( get_post_type( $post ), \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_not_found', __( 'Post not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to read this post\'s meta.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		$requested_keys = ! empty( $args['keys'] ) ? array_map( 'sanitize_text_field', (array) $args['keys'] ) : null;
		$all_meta       = get_post_meta( $post_id );
		$meta           = array();

		foreach ( $all_meta as $key => $values ) {
			if ( ! \GN\McpBridge\Config::meta_key_allowed( $key ) ) {
				continue;
			}

			if ( null !== $requested_keys && ! in_array( $key, $requested_keys, true ) ) {
				continue;
			}

			$meta[ $key ] = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}

		return array(
			'id'   => $post_id,
			'meta' => $meta,
		);
	}
}
