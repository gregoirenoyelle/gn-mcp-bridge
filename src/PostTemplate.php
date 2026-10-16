<?php
/**
 * Validation of a post's single template slug (`_wp_page_template`).
 *
 * @package gn-mcp-bridge
 * @since   1.7.0
 */

namespace GN\McpBridge;

/**
 * Resolves and validates the `template` input of create-post/update-post.
 *
 * `_wp_page_template` is protected meta, so it never goes through
 * update-post-meta's allow-list: the value is validated here against the
 * templates the active theme offers for the post type (the same list core's
 * REST API checks), then handed to wp_insert_post()/wp_update_post() as
 * `page_template`, which writes the meta itself.
 *
 * @since 1.7.0
 */
class PostTemplate {

	/**
	 * Templates available for a post type in the active theme.
	 *
	 * For a block theme this includes theme.json `customTemplates` and custom
	 * templates created in the Site Editor, filtered by `theme_{$post_type}_templates`.
	 *
	 * @since 1.7.0
	 * @param string $post_type Post type.
	 * @return array<string, string> Template slug => title.
	 */
	public static function available( $post_type ) {
		$templates = wp_get_theme()->get_page_templates( null, $post_type );

		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Validate a requested template and return the `page_template` value to save.
	 *
	 * An empty string (or `default`) means the post type's default template.
	 *
	 * @since 1.7.0
	 * @param mixed  $template  Requested template slug.
	 * @param string $post_type Post type of the target post.
	 * @return string|\WP_Error `page_template` value (`default` or a valid slug), or error listing valid values.
	 */
	public static function resolve( $template, $post_type ) {
		$template = is_string( $template ) ? trim( $template ) : '';

		if ( '' === $template || 'default' === $template ) {
			return 'default';
		}

		$available = self::available( $post_type );

		if ( isset( $available[ $template ] ) ) {
			return $template;
		}

		// '' (default template) is always valid.
		$valid = array_merge( array( '' ), array_map( 'strval', array_keys( $available ) ) );

		return new \WP_Error(
			'gn_mcp_invalid_template',
			sprintf(
				/* translators: 1: requested template slug, 2: post type, 3: comma-separated list of valid values. */
				__( 'Template "%1$s" is not available for post type "%2$s". Valid values: %3$s.', 'gn-mcp-bridge' ),
				$template,
				$post_type,
				'"" (default), ' . implode( ', ', array_slice( $valid, 1 ) )
			),
			array(
				'status'          => 400,
				'valid_templates' => $valid,
			)
		);
	}

	/**
	 * Current template slug of a post, `''` for the default template.
	 *
	 * @since 1.7.0
	 * @param int|\WP_Post $post Post ID or object.
	 * @return string Template slug.
	 */
	public static function current( $post ) {
		$slug = get_page_template_slug( $post );

		return is_string( $slug ) ? $slug : '';
	}
}
