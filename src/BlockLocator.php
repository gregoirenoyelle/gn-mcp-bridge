<?php
/**
 * Locates blocks in raw post_content by byte offset, so one block can be read
 * or spliced without re-serializing the rest of the post.
 *
 * @package gn-mcp-bridge
 * @since   1.5.0
 */

namespace GN\McpBridge;

/**
 * Scans block delimiters with core's own tokenizer regex and resolves a target
 * block from a `path` or a `name` + `occurrence`.
 *
 * Never goes through `serialize_blocks()`: every read and write works on byte
 * ranges of the raw string, so bytes outside the target block stay identical.
 *
 * @since 1.5.0
 */
class BlockLocator {

	/**
	 * Block delimiter regex, copied from `WP_Block_Parser::next_token()` so both
	 * agree on what a block comment is.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const DELIMITER_REGEX = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Scan raw content into a flat, document-ordered list of blocks.
	 *
	 * Each node: `path` (child indexes from the root, freeform gaps not counted,
	 * e.g. "4/1/0"), `blockName`, `name` (`metadata.name` or null), `occurrence`
	 * (0-based index among blocks sharing that `name`, null when unnamed),
	 * `depth`, `start`/`end` (byte offsets, end exclusive), `bytes`, `children`
	 * (direct child count), `closed` (false when the opener never gets a closer
	 * and core's parser implicitly ends it at the end of the document).
	 *
	 * Mirrors core's parser on malformed markup: a closer with no open block
	 * ends parsing (the rest is freeform to core), a closer always closes the
	 * innermost open block whatever its name.
	 *
	 * @since 1.5.0
	 * @param string $content Raw post_content.
	 * @return array[] Nodes in document order.
	 */
	public static function scan( $content ) {
		$nodes       = array();
		$stack       = array(); // Indexes into $nodes of currently open blocks.
		$child_count = array( -1 => 0 ); // Children seen so far per parent index (-1 = root).
		$offset      = 0;
		$length      = strlen( $content );

		while ( $offset < $length && 1 === preg_match( self::DELIMITER_REGEX, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			list( $match, $started_at ) = $matches[0];

			$token_end = $started_at + strlen( $match );
			$is_closer = isset( $matches['closer'] ) && -1 !== $matches['closer'][1];
			$is_void   = isset( $matches['void'] ) && -1 !== $matches['void'][1];
			$offset    = $token_end;

			if ( $is_closer ) {
				if ( empty( $stack ) ) {
					break;
				}

				$index                     = array_pop( $stack );
				$nodes[ $index ]['end']    = $token_end;
				$nodes[ $index ]['closed'] = true;
				continue;
			}

			$namespace = ( isset( $matches['namespace'] ) && -1 !== $matches['namespace'][1] ) ? $matches['namespace'][0] : 'core/';
			$attrs     = ( isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1] ) ? json_decode( $matches['attrs'][0], true ) : array();
			$parent    = empty( $stack ) ? -1 : end( $stack );
			$position  = $child_count[ $parent ]++;
			$name      = is_array( $attrs ) && isset( $attrs['metadata']['name'] ) && is_string( $attrs['metadata']['name'] ) ? $attrs['metadata']['name'] : null;

			$nodes[] = array(
				'path'       => -1 === $parent ? (string) $position : $nodes[ $parent ]['path'] . '/' . $position,
				'blockName'  => $namespace . $matches['name'][0],
				'name'       => $name,
				'occurrence' => null,
				'depth'      => count( $stack ),
				'start'      => $started_at,
				'end'        => $is_void ? $token_end : $length,
				'closed'     => $is_void,
				'parent'     => $parent,
			);

			$index                 = count( $nodes ) - 1;
			$child_count[ $index ] = 0;

			if ( ! $is_void ) {
				$stack[] = $index;
			}
		}

		$seen = array();

		foreach ( $nodes as $index => $node ) {
			$nodes[ $index ]['bytes']    = $node['end'] - $node['start'];
			$nodes[ $index ]['children'] = $child_count[ $index ];

			if ( null !== $node['name'] ) {
				$seen[ $node['name'] ]         = isset( $seen[ $node['name'] ] ) ? $seen[ $node['name'] ] + 1 : 0;
				$nodes[ $index ]['occurrence'] = $seen[ $node['name'] ];
			}
		}

		return $nodes;
	}

	/**
	 * Resolve exactly one block from the targeting arguments.
	 *
	 * Accepts either `path` or `name` (+ optional `occurrence`), never both.
	 * A `name` shared by several blocks without an `occurrence` is refused with
	 * the candidates' paths — never "first match".
	 *
	 * @since 1.5.0
	 * @param array[] $nodes Nodes from self::scan().
	 * @param array   $args  Targeting arguments: path, or name + occurrence.
	 * @return array|\WP_Error The matching node, or an error.
	 */
	public static function resolve( array $nodes, array $args ) {
		$has_path = isset( $args['path'] ) && '' !== $args['path'];
		$has_name = isset( $args['name'] ) && '' !== $args['name'];

		if ( $has_path === $has_name ) {
			return new \WP_Error( 'gn_mcp_block_target_invalid', __( 'Target a block with either "path" or "name" (plus optional "occurrence"), not both.', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
		}

		if ( $has_path ) {
			$path = trim( (string) $args['path'], '/' );

			if ( ! preg_match( '#^\d+(/\d+)*$#', $path ) ) {
				return new \WP_Error( 'gn_mcp_block_target_invalid', __( 'Invalid "path": expected child indexes separated by slashes, e.g. "4/1/0".', 'gn-mcp-bridge' ), array( 'status' => 400 ) );
			}

			$path = implode( '/', array_map( 'intval', explode( '/', $path ) ) );

			foreach ( $nodes as $node ) {
				if ( $node['path'] === $path ) {
					return $node;
				}
			}

			/* translators: %s: block path */
			return new \WP_Error( 'gn_mcp_block_not_found', sprintf( __( 'No block at path "%s".', 'gn-mcp-bridge' ), $path ), array( 'status' => 404 ) );
		}

		$name       = (string) $args['name'];
		$candidates = array_values( array_filter( $nodes, function( $node ) use ( $name ) {
			return $node['name'] === $name;
		} ) );

		if ( empty( $candidates ) ) {
			/* translators: %s: block metadata name */
			return new \WP_Error( 'gn_mcp_block_not_found', sprintf( __( 'No block named "%s".', 'gn-mcp-bridge' ), $name ), array( 'status' => 404 ) );
		}

		if ( isset( $args['occurrence'] ) && null !== $args['occurrence'] ) {
			$occurrence = (int) $args['occurrence'];

			if ( isset( $candidates[ $occurrence ] ) && $occurrence >= 0 ) {
				return $candidates[ $occurrence ];
			}

			return new \WP_Error(
				'gn_mcp_block_not_found',
				/* translators: 1: block metadata name, 2: occurrence, 3: number of blocks with that name */
				sprintf( __( 'No occurrence %2$d of "%1$s" (%3$d blocks share that name, occurrence is 0-based).', 'gn-mcp-bridge' ), $name, $occurrence, count( $candidates ) ),
				array( 'status' => 404, 'candidates' => self::candidates( $candidates ) )
			);
		}

		if ( count( $candidates ) > 1 ) {
			return new \WP_Error(
				'gn_mcp_block_ambiguous',
				/* translators: 1: number of blocks with that name, 2: block metadata name, 3: candidate list */
				sprintf( __( '%1$d blocks are named "%2$s": pass "occurrence" or "path". Candidates: %3$s', 'gn-mcp-bridge' ), count( $candidates ), $name, wp_json_encode( self::candidates( $candidates ) ) ),
				array( 'status' => 409, 'candidates' => self::candidates( $candidates ) )
			);
		}

		return $candidates[0];
	}

	/**
	 * Load a post the current user may edit, restricted to the allowed post types.
	 *
	 * Shared by the block abilities: reading a block's raw markup exposes the
	 * same data as editing the post, so the per-post `edit_post` check applies
	 * to reads too.
	 *
	 * @since 1.5.0
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error The post, or an error.
	 */
	public static function load_post( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( get_post_type( $post ), Config::allowed_post_types(), true ) ) {
			return new \WP_Error( 'gn_mcp_post_not_found', __( 'Post not found.', 'gn-mcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'gn_mcp_forbidden', __( 'You are not allowed to edit this post.', 'gn-mcp-bridge' ), array( 'status' => 403 ) );
		}

		return $post;
	}

	/**
	 * Raw markup of one resolved block.
	 *
	 * @since 1.5.0
	 * @param string $content Raw post_content.
	 * @param array  $node    Node from self::scan()/self::resolve().
	 * @return string Block markup, delimiters included.
	 */
	public static function markup( $content, array $node ) {
		return substr( $content, $node['start'], $node['bytes'] );
	}

	/**
	 * Hash identifying one exact version of a post's raw content, used as the
	 * stale-read guard between a read and a write.
	 *
	 * @since 1.5.0
	 * @param string $content Raw post_content.
	 * @return string md5 hash.
	 */
	public static function content_hash( $content ) {
		return md5( $content );
	}

	/**
	 * Public, compact description of a node (internal offsets dropped, `name`/
	 * `occurrence` only present on named blocks).
	 *
	 * @since 1.5.0
	 * @param array $node Node from self::scan().
	 * @return array Node summary.
	 */
	public static function summary( array $node ) {
		$summary = array(
			'path'      => $node['path'],
			'blockName' => $node['blockName'],
		);

		// Unnamed blocks are the majority on most posts: leaving out their null keys keeps long outlines under the MCP output limit.
		if ( null !== $node['name'] ) {
			$summary['name']       = $node['name'];
			$summary['occurrence'] = $node['occurrence'];
		}

		$summary['depth']    = $node['depth'];
		$summary['bytes']    = $node['bytes'];
		$summary['children'] = $node['children'];

		return $summary;
	}

	/**
	 * Candidate list for ambiguity/not-found errors.
	 *
	 * @since 1.5.0
	 * @param array[] $nodes Candidate nodes.
	 * @return array[] Occurrence, path, and blockName per candidate.
	 */
	protected static function candidates( array $nodes ) {
		return array_map( function( $node ) {
			return array(
				'occurrence' => $node['occurrence'],
				'path'       => $node['path'],
				'blockName'  => $node['blockName'],
			);
		}, $nodes );
	}
}
