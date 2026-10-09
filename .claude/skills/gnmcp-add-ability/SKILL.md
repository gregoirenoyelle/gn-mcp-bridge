---
name: gnmcp-add-ability
description: Scaffold a new ability in the gn-mcp-bridge WordPress plugin (src/Abilities/) — registers it via the Abilities API and auto-exposes it on the gn-mcp MCP server. Trigger keywords: add MCP ability, new gn-mcp ability, scaffold ability, gn-mcp-bridge ability.
user-invocable: true
---

# gnmcp-add-ability — Scaffold a new ability

Bundled with the `gn-mcp-bridge` plugin — travels with it when the plugin is copied to another site, no dependency outside this plugin's own folder.

**Operator tool, not a client-facing one.** This skill writes new server-side PHP with its own `permission_callback` — never run it on behalf of a client, or in a Claude Code session a client has access to. See `README.md`'s "Security model & limits": this plugin's capability restrictions only hold for a caller with MCP-only access; anyone with codebase access (which this skill needs to do its job) already bypasses them.

**Plugin-generic abilities only.** This skill scaffolds into the shared plugin (`src/Abilities/`), copied as-is across every site — only use it for an ability reusable on any site. A site-specific integration (a CPT, a third-party plugin like Polylang, an SEO plugin's protected meta) belongs in that site's theme (`inc/core/mcp-abilities.php`, `register_theme_ability()` pattern), never here — see `CLAUDE.md`'s "FORBIDDEN" section.

## Steps

1. Confirm the plugin is present: `wp-content/plugins/gn-mcp-bridge/src/Abilities/`.
2. Create `src/Abilities/YourAbility.php`, mirroring an existing one (`SearchPostsAbility.php` is the simplest reference):
   - `register()` — static, calls `wp_register_ability( 'gn-mcp/your-ability', [...] )` with `execute_callback` (never `callback`), `permission_callback` pointing at `check_permission()`, `input_schema`, `output_schema`, `'category' => 'gn-mcp'`, `'meta' => ['show_in_rest' => true]`
   - `check_permission()` — two shapes exist, pick based on whether the ability's authorization depends on the request's own arguments:
     - Plain (no argument-dependent logic — most abilities, e.g. `GetPostMetaAbility`): `public static function check_permission() { return current_user_can( \GN\McpBridge\Config::ability_capability( 'gn-mcp/your-ability' ) ); }`
     - `$args`-aware (used by `CreatePostAbility` and `UpdatePostAbility` — e.g. requiring `publish_posts` only when the request asks for `status: 'publish'`, or requiring a post-type-specific capability like `edit_pages` when the target post is a `page`): `public static function check_permission( $args = array() ) { ... }`, reading `$args` before falling back to `Config::ability_capability()`
   - `execute()` — sanitize every input (`sanitize_text_field`, `absint`, `wp_kses_post`, ...), return an array matching `output_schema` or a `WP_Error`
   - If the ability operates on a post (read or write), filter the target post type(s) through `Config::allowed_post_types()` (`gn_mcp_bridge_allowed_post_types`, default `post` + `page`) rather than hardcoding which post types it accepts — mirrors how `SearchPostsAbility`, `GetPostAbility`, `CreatePostAbility`, and `UpdatePostAbility` already do it.
3. Add its default capability to `Config::DEFAULT_CAPABILITIES` in `src/Config.php`.
4. Call `YourAbility::register()` from `register_abilities()` in `gn-mcp-bridge.php`.
5. It is auto-exposed on the `gn-mcp` server (`Server.php` reads `Config::exposed_abilities()`) unless a consuming theme filters it out via `gn_mcp_bridge_exposed_abilities` (see `gnmcp-setup-site`).
6. Verify: `wp eval '$a = wp_get_ability("gn-mcp/your-ability"); var_dump($a->execute([...]));' --path=<site>` before testing over MCP.
7. Flush nothing — abilities register on every request via `wp_abilities_api_init`, no caching involved.
8. Update `CLAUDE.md`'s file structure list, and add a full entry (input/output schema, default capability, notes) in **both** `docs/mcp-abilities.md` and `docs/fr/mcp-abilities.md` — the two files must never drift apart (see `CLAUDE.md`'s "French documentation" rule). `README.md`/`docs/fr/README.md`'s "What it exposes" only needs its one-line ability-name summary updated, not a full table.

## Related

- Per-site exposure/capability filters and Application Password setup: `gnmcp-setup-site`
- Diagnosing a connection once the ability exists: `gnmcp-check`
