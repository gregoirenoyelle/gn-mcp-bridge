<?php
/**
 * Ability: move an existing post to the trash.
 *
 * @package gn-mcp-bridge
 * @since   1.4.5
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/delete-post ability.
 *
 * @since 1.4.5
 */
class DeletePostAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/delete-post', array(
			'label'               => __( 'Delete Post', 'gn-mcp-bridge' ),
			'description'         => __( 'Move an existing post to the trash.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'ID of the post to trash.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'status' => array( 'type' => 'string' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Permission check for this ability.
	 *
	 * Beyond the ability's default capability (`delete_posts`), a per-post
	 * `current_user_can( 'delete_post', $id )` check runs in execute() —
	 * same pattern as `UpdatePostAbility`, since WordPress's own
	 * `map_meta_cap()` is what actually resolves ownership/post-type-specific
	 * capabilities (e.g. `delete_page` for a `page`, `delete_others_posts`
	 * for someone else's `post`), not the generic ability-level capability.
	 *
	 * @since 1.4.5
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission() {
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/delete-post' ) );
	}

	/**
	 * Execute the delete-post ability.
	 *
	 * Trashes the post (`wp_trash_post()`) rather than deleting it
	 * permanently — reversible, matches the TODO's default pick and
	 * WordPress's own UI behavior for a single delete action.
	 *
	 * @since 1.4.5
	 * @param array $args Input arguments: id.
	 * @return array|\WP_Error Trashed post data or error.
	 */
	public static function execute( $args ) {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( get_post_type( $post ), \GN\McpBridge\Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_not_found', __( 'Post not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to delete this post.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return new \WP_Error( 'gn_mcp_delete_failed', __( 'The post could not be trashed.', 'gn-mcp-bridge' ), array( 'status' => 500 ) );
		}

		return array(
			'id'     => $post_id,
			'status' => get_post_status( $post_id ),
		);
	}
}
