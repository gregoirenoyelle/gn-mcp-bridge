<?php
/**
 * Ability: update an existing taxonomy term (name, description).
 *
 * @package gn-mcp-bridge
 * @since   1.6.0
 */

namespace GN\McpBridge\Abilities;

/**
 * Registers and executes the gn-mcp/update-term ability.
 *
 * @since 1.6.0
 */
class UpdateTermAbility {

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/update-term', array(
			'label'               => __( 'Update Term', 'gn-mcp-bridge' ),
			'description'         => __( 'Update the name and/or description of an existing taxonomy term (category, tag...), found by ID or slug.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => array(
						'type'        => 'string',
						'default'     => 'category',
						'description' => 'Taxonomy slug. Defaults to "category".',
					),
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Term ID. Takes precedence over slug.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'Term slug, used when no ID is given. With Polylang, each language has its own slug.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'New term name.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New term description (plain text or basic HTML). An empty string clears it.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'                   => array( 'type' => 'integer' ),
					'taxonomy'             => array( 'type' => 'string' ),
					'slug'                 => array( 'type' => 'string' ),
					'name'                 => array( 'type' => 'string' ),
					'description'          => array( 'type' => 'string' ),
					'previous_description' => array( 'type' => 'string' ),
					'url'                  => array( 'type' => 'string' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Permission check for this ability.
	 *
	 * The per-term `edit_term` check in execute() still applies afterward,
	 * so the taxonomy's own capability mapping is respected too.
	 *
	 * @since 1.6.0
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission() {
		return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/update-term' ) );
	}

	/**
	 * Execute the update-term ability.
	 *
	 * @since 1.6.0
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Updated term data or error.
	 */
	public static function execute( $args ) {
		$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'gn_mcp_taxonomy_not_found', __( 'Taxonomy not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $args['id'] ) ) {
			$term = get_term( absint( $args['id'] ), $taxonomy );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $args['slug'] ?? '' ), $taxonomy );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'gn_mcp_term_not_found', __( 'Term not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to edit this term.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		$update = array();

		if ( array_key_exists( 'name', $args ) ) {
			$update['name'] = sanitize_text_field( $args['name'] );
		}

		if ( array_key_exists( 'description', $args ) ) {
			// Core's pre_term_description filter applies kses for users without unfiltered_html.
			$update['description'] = (string) $args['description'];
		}

		if ( ! $update ) {
			return new \WP_Error( 'gn_mcp_nothing_to_update', __( 'Provide a name and/or a description to update.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		$previous_description = $term->description;

		// Slashed because wp_update_term() unslashes name and description.
		$result = wp_update_term( $term->term_id, $taxonomy, wp_slash( $update ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $term->term_id, $taxonomy );
		$link = get_term_link( $term );

		return array(
			'id'                   => (int) $term->term_id,
			'taxonomy'             => $taxonomy,
			'slug'                 => $term->slug,
			'name'                 => $term->name,
			'description'          => $term->description,
			'previous_description' => $previous_description,
			'url'                  => is_wp_error( $link ) ? '' : $link,
		);
	}
}
