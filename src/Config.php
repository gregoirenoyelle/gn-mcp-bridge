<?php
/**
 * Filterable settings for the gn-mcp-bridge plugin: exposed abilities and
 * required capabilities, so a consuming theme can adjust them in code.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge;

/**
 * Reads plugin settings through filters rather than hardcoded values.
 *
 * @since 1.0.0
 */
class Config {

	/**
	 * Abilities registered by this plugin, with their default required capability.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	const DEFAULT_CAPABILITIES = array(
		'gn-mcp/search-posts'     => 'read',
		'gn-mcp/get-post'         => 'edit_others_posts',
		'gn-mcp/get-post-outline' => 'edit_others_posts',
		'gn-mcp/get-block'        => 'edit_others_posts',
		'gn-mcp/create-post'      => 'edit_posts',
		'gn-mcp/update-post'      => 'edit_posts',
		'gn-mcp/update-block'     => 'edit_posts',
		'gn-mcp/delete-post'      => 'delete_posts',
		'gn-mcp/get-post-meta'    => 'edit_posts',
		'gn-mcp/update-post-meta' => 'edit_posts',
		'gn-mcp/list-categories'  => 'read',
	);

	/**
	 * Abilities exposed as MCP tools on the gn-mcp server.
	 *
	 * Filterable so a theme can drop an ability from the list without
	 * touching this plugin, e.g. to disable write access on a given site.
	 *
	 * @since 1.0.0
	 * @return string[] Ability names.
	 */
	public static function exposed_abilities() {
		/**
		 * Filters the list of ability names exposed on the gn-mcp MCP server.
		 *
		 * Example (in a theme's functions.php or an included file):
		 *
		 *     add_filter( 'gn_mcp_bridge_exposed_abilities', function( array $abilities ) {
		 *         return array_diff( $abilities, array( 'gn-mcp/create-post' ) );
		 *     } );
		 *
		 * @since 1.0.0
		 * @param string[] $abilities Default ability names (see self::DEFAULT_CAPABILITIES keys).
		 */
		return apply_filters( 'gn_mcp_bridge_exposed_abilities', array_keys( self::DEFAULT_CAPABILITIES ) );
	}

	/**
	 * Capability required to run a given ability.
	 *
	 * Filterable so a theme can harden or relax the capability per ability
	 * without touching this plugin's permission callbacks.
	 *
	 * @since 1.0.0
	 * @param string $ability_name Ability name, e.g. 'gn-mcp/create-post'.
	 * @return string WordPress capability.
	 */
	public static function ability_capability( $ability_name ) {
		$default = self::DEFAULT_CAPABILITIES[ $ability_name ] ?? 'read';

		/**
		 * Filters the capability required to run one gn-mcp-bridge ability.
		 *
		 * Example (require a stronger capability for writes on this site):
		 *
		 *     add_filter( 'gn_mcp_bridge_ability_capability', function( $capability, $ability_name ) {
		 *         return 'gn-mcp/create-post' === $ability_name ? 'publish_posts' : $capability;
		 *     }, 10, 2 );
		 *
		 * @since 1.0.0
		 * @param string $capability   Default capability for this ability.
		 * @param string $ability_name Ability name.
		 */
		return apply_filters( 'gn_mcp_bridge_ability_capability', $default, $ability_name );
	}

	/**
	 * Capability required to reach the gn-mcp MCP endpoint at all.
	 *
	 * Wired to mcp-adapter's own transport-level permission filter in
	 * Server.php. Filterable so a theme can raise the endpoint-wide bar
	 * (e.g. require 'edit_posts' instead of the default 'read').
	 *
	 * @since 1.0.0
	 * @return string WordPress capability.
	 */
	public static function transport_capability() {
		/**
		 * Filters the capability required to reach the gn-mcp MCP endpoint.
		 *
		 * Example (only editors and up may even open the endpoint):
		 *
		 *     add_filter( 'gn_mcp_bridge_transport_capability', function() {
		 *         return 'edit_others_posts';
		 *     } );
		 *
		 * @since 1.0.0
		 * @param string $capability Default transport-level capability ('read').
		 */
		return apply_filters( 'gn_mcp_bridge_transport_capability', 'read' );
	}

	/**
	 * Whether a given post meta key may be read/written through the
	 * gn-mcp/get-post-meta and gn-mcp/update-post-meta abilities.
	 *
	 * Defaults to excluding "protected" meta (keys starting with an
	 * underscore, WordPress's own convention for internal/hidden custom
	 * fields, e.g. `_thumbnail_id`, `_edit_lock`) — filterable so a theme
	 * can allow specific protected keys, or narrow this to an explicit
	 * allowlist, on a given site.
	 *
	 * @since 1.0.0
	 * @param string $key Meta key.
	 * @return bool True if this meta key may be exposed over MCP.
	 */
	public static function meta_key_allowed( $key ) {
		$default = ! is_protected_meta( $key, 'post' );

		/**
		 * Filters whether one post meta key is exposed over the gn-mcp
		 * get-post-meta / update-post-meta abilities.
		 *
		 * Example (also allow the featured image meta key on this site):
		 *
		 *     add_filter( 'gn_mcp_bridge_meta_key_allowed', function( $allowed, $key ) {
		 *         return '_thumbnail_id' === $key ? true : $allowed;
		 *     }, 10, 2 );
		 *
		 * @since 1.0.0
		 * @param bool   $allowed Default allowed state (true unless the key is WordPress-protected).
		 * @param string $key     Meta key being checked.
		 */
		return (bool) apply_filters( 'gn_mcp_bridge_meta_key_allowed', $default, $key );
	}

	/**
	 * Post types the search-posts, get-post, create-post, update-post,
	 * delete-post, get-post-meta, and update-post-meta abilities may operate on.
	 *
	 * Defaults to `post`, `page`, and `wp_block` (Gutenberg reusable blocks —
	 * on by default since FSE/block-theme work routinely needs to manage
	 * these over MCP; `wp_block` has its own capability mapping — `edit_blocks`,
	 * `publish_blocks`, etc., see the per-type capability handling in
	 * `CreatePostAbility`/`UpdatePostAbility` — not the generic `edit_posts`).
	 * Filterable so a theme can expose one or more CPTs over MCP without any
	 * change to this plugin — the abilities stay generic, the theme decides
	 * which post types are actually reachable.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Added `wp_block` to the default.
	 * @return string[] Allowed post type slugs.
	 */
	public static function allowed_post_types() {
		/**
		 * Filters the post types reachable through gn-mcp-bridge's CRUD abilities.
		 *
		 * Example (also expose the "recipe" CPT on this site):
		 *
		 *     add_filter( 'gn_mcp_bridge_allowed_post_types', function( array $post_types ) {
		 *         return array_merge( $post_types, array( 'recipe' ) );
		 *     } );
		 *
		 * @since 1.2.0
		 * @param string[] $post_types Default allowed post types (`array( 'post', 'page', 'wp_block' )`).
		 */
		return apply_filters( 'gn_mcp_bridge_allowed_post_types', array( 'post', 'page', 'wp_block' ) );
	}
}
