<?php
/**
 * Ability: replace, insert, or delete one block of a post without resending
 * the whole post_content.
 *
 * @package gn-mcp-bridge
 * @since   1.5.0
 */

namespace GN\McpBridge\Abilities;

use GN\McpBridge\BlockLocator;

/**
 * Registers and executes the gn-mcp/update-block ability.
 *
 * @since 1.5.0
 */
class UpdateBlockAbility {

	/**
	 * Supported operations.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const OPERATIONS = array( 'replace', 'insert_before', 'insert_after', 'delete' );

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'gn-mcp/update-block', array(
			'label'               => __( 'Update Block', 'gn-mcp-bridge' ),
			'description'         => __( 'Replace, insert before/after, or delete one block of a post, located by path or metadata name + occurrence (see get-post-outline). Only the target range of the raw content changes; a revision is created. Pass expected_hash (content_hash from get-post-outline/get-block) so the write is refused if the post changed since it was read.', 'gn-mcp-bridge' ),
			'category'            => 'gn-mcp',
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => array( static::class, 'check_permission' ),
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id', 'operation' ),
				'properties' => array_merge(
					GetBlockAbility::target_properties(),
					array(
						'operation'     => array(
							'type'        => 'string',
							'enum'        => self::OPERATIONS,
							'description' => 'replace: swap the target block for markup. insert_before/insert_after: add markup as a sibling of the target. delete: remove the target block (markup ignored).',
						),
						'markup'        => array(
							'type'        => 'string',
							'description' => 'Exactly one block as Gutenberg block markup (inner blocks allowed), e.g. <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->. Required except for delete. Send raw < > and \" in block comment JSON, never \\uXXXX escapes.',
						),
						'expected_hash' => array(
							'type'        => 'string',
							'description' => 'content_hash returned by get-post-outline or get-block. The write is refused (gn_mcp_stale_content) if the post content no longer matches. Strongly recommended, required in practice when targeting by path.',
						),
					)
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'            => array( 'type' => 'integer' ),
					'operation'     => array( 'type' => 'string' ),
					'block'         => array( 'type' => array( 'object', 'null' ) ),
					'markup'        => array( 'type' => array( 'string', 'null' ) ),
					'valid'         => array( 'type' => 'boolean' ),
					'saved_as_sent' => array( 'type' => 'boolean' ),
					'new_hash'      => array( 'type' => 'string' ),
					'bytes_before'  => array( 'type' => 'integer' ),
					'bytes_after'   => array( 'type' => 'integer' ),
				),
			),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/**
	 * Permission check for this ability.
	 *
	 * Same post-type-aware rule as update-post: the generic capability for the
	 * built-in `post` type, the post type's own mapped `edit_posts` otherwise
	 * (`edit_pages`, `edit_blocks`, ...). The per-post `edit_post` check runs
	 * in execute().
	 *
	 * @since 1.5.0
	 * @param array $args Input arguments (see input_schema).
	 * @return bool True if the current user may run this ability.
	 */
	public static function check_permission( $args = array() ) {
		$post_id     = absint( $args['id'] ?? 0 );
		$post        = $post_id ? get_post( $post_id ) : null;
		$post_type   = $post ? get_post_type( $post ) : null;
		$type_object = $post_type ? get_post_type_object( $post_type ) : null;

		if ( ! $type_object || 'post' === $post_type ) {
			return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/update-block' ) );
		}

		return current_user_can( $type_object->cap->edit_posts );
	}

	/**
	 * Execute the update-block ability.
	 *
	 * Splices the raw post_content by byte offset — never re-serializes the
	 * block tree — so bytes outside the target are untouched by this ability.
	 * Core's own `content_save_pre` kses filters still run on the whole content
	 * for users without `unfiltered_html`: the write is simulated through them
	 * first and refused if they would alter anything outside what was sent
	 * (`saved_as_sent` still reports any other save-time change).
	 *
	 * @since 1.5.0
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Result or error.
	 */
	public static function execute( $args ) {
		$post = BlockLocator::load_post( $args['id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$operation = isset( $args['operation'] ) ? (string) $args['operation'] : '';

		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new \WP_Error( 'gn_mcp_invalid_operation', __( 'Unknown operation.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		$content = $post->post_content;
		$hash    = BlockLocator::content_hash( $content );

		if ( ! empty( $args['expected_hash'] ) && ! hash_equals( $hash, (string) $args['expected_hash'] ) ) {
			return new \WP_Error(
				'gn_mcp_stale_content',
				__( 'The post changed since it was read: expected_hash does not match. Re-read it with get-post-outline or get-block and retry.', 'gn-mcp-bridge' ),
				array( 'status' => 409, 'current_hash' => $hash )
			);
		}

		$node = BlockLocator::resolve( BlockLocator::scan( $content ), $args );

		if ( is_wp_error( $node ) ) {
			return $node;
		}

		if ( ! $node['closed'] ) {
			return new \WP_Error( 'gn_mcp_block_unclosed', __( 'The target block has no closing comment; fix the markup in the editor before editing it here.', 'gn-mcp-bridge' ), array( 'status' => 422 ) );
		}

		$fragment = '';

		if ( 'delete' !== $operation ) {
			$fragment = self::prepare_fragment( isset( $args['markup'] ) ? (string) $args['markup'] : '' );

			if ( is_wp_error( $fragment ) ) {
				return $fragment;
			}
		}

		switch ( $operation ) {
			case 'replace':
				$new_content = substr_replace( $content, $fragment, $node['start'], $node['bytes'] );
				$new_path    = $node['path'];
				break;

			case 'insert_before':
				$new_content = substr_replace( $content, $fragment . "\n\n", $node['start'], 0 );
				$new_path    = $node['path'];
				break;

			case 'insert_after':
				$new_content = substr_replace( $content, "\n\n" . $fragment, $node['end'], 0 );
				$segments    = explode( '/', $node['path'] );
				$segments[]  = (int) array_pop( $segments ) + 1;
				$new_path    = implode( '/', $segments );
				break;

			default: // delete: also drop the separator on one side, so no blank gap is left behind.
				$prefix      = substr( $content, 0, $node['start'] );
				$after       = strspn( $content, " \t\r\n", $node['end'] );
				$before      = $after ? 0 : strlen( $prefix ) - strlen( rtrim( $prefix, " \t\r\n" ) );
				$new_content = substr_replace( $content, '', $node['start'] - $before, $node['bytes'] + $before + $after );
				$new_path    = null;
		}

		// Core's content_save_pre kses runs on the whole content for users without unfiltered_html, and HTML-encodes any block
		// comment whose JSON holds a raw `<` (e.g. saved over MCP by an admin). Simulate it first and refuse rather than corrupt
		// blocks outside the target — re-filtering the whole content instead would rewrite every block's attributes.
		if ( ! current_user_can( 'unfiltered_html' ) && wp_unslash( wp_filter_post_kses( wp_slash( $new_content ) ) ) !== $new_content ) {
			return new \WP_Error(
				'gn_mcp_kses_would_alter',
				__( 'Refused: WordPress\'s HTML filter for your role would alter blocks outside the target (typically a block comment whose JSON holds raw HTML). Ask a user with the unfiltered_html capability to make this edit, or re-save the post once in the block editor, then retry.', 'gn-mcp-bridge' ),
				array( 'status' => 422 )
			);
		}

		// Slashed because wp_update_post() unslashes, which would strip backslashes from block JSON (unicode and quote escapes).
		$result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $new_content ) ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved     = (string) get_post_field( 'post_content', $post->ID, 'raw' );
		$new_node  = null;
		$new_block = null;
		$valid     = 'delete' === $operation;

		if ( null !== $new_path ) {
			$new_node = BlockLocator::resolve( BlockLocator::scan( $saved ), array( 'path' => $new_path ) );
			$new_node = is_wp_error( $new_node ) ? null : $new_node;

			if ( $new_node ) {
				$new_block = BlockLocator::markup( $saved, $new_node );
				$valid     = $new_node['closed'] && true === self::validate_fragment( $new_block );
			}
		}

		return array(
			'id'            => $post->ID,
			'operation'     => $operation,
			'block'         => $new_node ? BlockLocator::summary( $new_node ) : null,
			'markup'        => $new_block,
			'valid'         => $valid,
			'saved_as_sent' => $saved === $new_content,
			'new_hash'      => BlockLocator::content_hash( $saved ),
			'bytes_before'  => strlen( $content ),
			'bytes_after'   => strlen( $saved ),
		);
	}

	/**
	 * Validate and sanitize an incoming block fragment.
	 *
	 * Same sanitization rule as update-post, applied to the fragment only:
	 * `filter_block_content()` + `wp_kses_post()` for users without
	 * `unfiltered_html`. Validated before and after sanitization, so kses
	 * can't silently turn a block into freeform HTML.
	 *
	 * @since 1.5.0
	 * @param string $markup Raw fragment.
	 * @return string|\WP_Error Trimmed, sanitized fragment, or an error.
	 */
	protected static function prepare_fragment( $markup ) {
		$markup = trim( $markup );
		$check  = self::validate_fragment( $markup );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $markup;
		}

		$markup = trim( wp_kses_post( filter_block_content( $markup ) ) );
		$check  = self::validate_fragment( $markup );

		if ( is_wp_error( $check ) ) {
			return new \WP_Error( 'gn_mcp_invalid_fragment', __( 'The markup is no longer a single valid block once sanitized for your role.', 'gn-mcp-bridge' ), array( 'status' => 422 ) );
		}

		return $markup;
	}

	/**
	 * Whether a string is exactly one closed block, with nothing around it.
	 *
	 * Checked with both the locator's scanner and core's `parse_blocks()`, so
	 * classic/freeform HTML, several sibling blocks, stray text outside the
	 * block, and unbalanced delimiters are all refused.
	 *
	 * @since 1.5.0
	 * @param string $markup Trimmed fragment.
	 * @return true|\WP_Error True if valid, or an error.
	 */
	protected static function validate_fragment( $markup ) {
		$top = array_values( array_filter( BlockLocator::scan( $markup ), function( $node ) {
			return 0 === $node['depth'];
		} ) );

		$parsed = array_values( array_filter( parse_blocks( $markup ), function( $block ) {
			return null !== $block['blockName'];
		} ) );

		$valid = 1 === count( $top ) && 1 === count( $parsed )
			&& $top[0]['closed'] && 0 === $top[0]['start'] && strlen( $markup ) === $top[0]['end']
			&& $top[0]['blockName'] === $parsed[0]['blockName'] && 'core/freeform' !== $parsed[0]['blockName'];

		if ( ! $valid ) {
			return new \WP_Error( 'gn_mcp_invalid_fragment', __( 'markup must be exactly one Gutenberg block (opening and closing block comments, inner blocks allowed), with no text or other block around it. Classic/freeform HTML is refused.', 'gn-mcp-bridge' ), array( 'status' => 422 ) );
		}

		return true;
	}
}
