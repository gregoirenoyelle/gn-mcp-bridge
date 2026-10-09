<?php
/**
 * Ability: list existing post categories.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/list-categories ability.
 *
 * @since 1.0.0
 */
class ListCategoriesAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/list-categories', array(
			'label'               => __( 'List Categories', 'gn-mcp-bridge' ),
			'description'         => __( 'List existing post categories with their post counts.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string' ),
						'slug'  => array( 'type' => 'string' ),
						'count' => array( 'type' => 'integer' ),
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
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/list-categories' ) );
	}

	/**
	 * Execute the list-categories ability.
	 *
	 * @since 1.0.0
	 * @param array $args Unused, this ability takes no input.
	 * @return array List of categories.
	 */
	public static function execute( $args ) {
		$terms = get_categories( array( 'hide_empty' => false ) );

		return array_map( function( $term ) {
			return array(
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => (int) $term->count,
			);
		}, $terms );
	}
}
