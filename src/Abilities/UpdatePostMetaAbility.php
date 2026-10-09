<?php
/**
 * Ability: update custom fields (post meta) of an existing post.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/update-post-meta ability.
 *
 * @since 1.0.0
 */
class UpdatePostMetaAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/update-post-meta', array(
			'label'               => __( 'Update Post Meta', 'gn-mcp-bridge' ),
			'description'         => __( 'Set, update, or delete custom fields (post meta) of an existing post.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'ID of the post to update meta on.',
					),
					'meta'   => array(
						'type'        => 'object',
						'description' => 'Map of meta key => value to set or update.',
					),
					'delete' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Meta keys to delete.',
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
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/update-post-meta' ) );
	}

	/**
	 * Execute the update-post-meta ability.
	 *
	 * @since 1.0.0
	 * @param array $args Input arguments: id, meta, delete.
	 * @return array|\WP_Error Updated post meta, or error.
	 */
	public static function execute( $args ) {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( get_post_type( $post ), \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_not_found', __( 'Post not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to edit this post\'s meta.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		$meta_updates = is_array( $args['meta'] ?? null ) ? $args['meta'] : array();
		$meta_deletes = is_array( $args['delete'] ?? null ) ? array_map( 'sanitize_text_field', $args['delete'] ) : array();

		$disallowed = array();

		foreach ( array_merge( array_keys( $meta_updates ), $meta_deletes ) as $key ) {
			if ( ! \GN\McpBridge\Config::meta_key_allowed( $key ) ) {
				$disallowed[] = $key;
			}
		}

		if ( ! empty( $disallowed ) ) {
			return new \WP_Error(
				'gn_mcp_meta_key_forbidden',
				sprintf(
					/* translators: %s: comma-separated list of meta keys */
					__( 'These meta keys are not allowed: %s', 'gn-mcp-bridge' ),
					implode( ', ', $disallowed )
				),
				array( 'status' => 403 )
			);
		}

		foreach ( $meta_updates as $key => $value ) {
			update_post_meta( $post_id, sanitize_text_field( $key ), self::sanitize_value( $value ) );
		}

		foreach ( $meta_deletes as $key ) {
			delete_post_meta( $post_id, $key );
		}

		$meta = array();

		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( \GN\McpBridge\Config::meta_key_allowed( $key ) ) {
				$meta[ $key ] = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			}
		}

		return array(
			'id'   => $post_id,
			'meta' => $meta,
		);
	}

	/**
	 * Sanitize a meta value recursively, leaving non-string types untouched.
	 *
	 * @since 1.0.0
	 * @param mixed $value Raw value from input.
	 * @return mixed Sanitized value.
	 */
	protected static function sanitize_value( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( array( static::class, 'sanitize_value' ), $value );
		}

		return $value;
	}
}
