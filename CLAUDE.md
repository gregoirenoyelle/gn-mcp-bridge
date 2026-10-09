# gn-mcp-bridge — Project Context for Claude

## ⚠️ FORBIDDEN on any site other than this one: editing this plugin's source

This plugin is copied as-is across every Valet site. **This repo — `gregoirenoyelle` — is the canonical/dev copy.** Check which one you're in before touching `src/` or `gn-mcp-bridge.php`: the Valet site's folder name / domain (e.g. `wp option get siteurl --path=<site>`, or just the `SitesValet/<folder>` path). If it isn't `gregoirenoyelle`, you are in a deployment copy — plugin source is off-limits there, full stop, no exception.

This has already almost happened once (a session on a deployment site confused "extend the plugin" with "extend the theme") — treat this as a hard rule, not a preference:

- **On this canonical repo**: real plugin maintenance — bug fixes, version bumps, a new ability genuinely reusable on any site — is normal work, via `gnmcp-add-ability` or a direct edit. This is where that work belongs.
- **On any other site's copy**: editing `src/` or `gn-mcp-bridge.php` to solve that one site's problem (a plugin integration like Polylang, a CPT, a capability tweak) breaks every other site's copy and defeats the point of the shared plugin. Anything site-specific → **always** that site's own theme, `inc/core/mcp-abilities.php`, via the five filters or a theme-registered ability (`register_theme_ability()` pattern). Never the plugin.
- A fix discovered while working on a deployment site that turns out to be genuinely generic gets back-ported here, to the canonical repo — not patched locally on the deployment site.
- If genuinely unsure which side something belongs on, ask before touching either — don't guess by editing the plugin "just this once."

## What this plugin does

Exposes WordPress content abilities as an MCP server (`gn-mcp`) consumable by Claude Code over HTTP, authenticated via WordPress Application Passwords. Copiable as-is across Valet sites — per-site behavior is adjusted from the consuming theme via filters, never by editing this plugin.

## File structure

```
gn-mcp-bridge/
├── gn-mcp-bridge.php               ← Bootstrap, hooks, constants
├── composer.json / phpunit.xml     ← Dev tooling only (PHPUnit + Brain Monkey) — runtime uses src/autoload.php, never vendor/
├── .github/workflows/test.yml      ← CI: php -l + PHPUnit on PHP 8.2 (push main/dev, PR to main)
├── tests/
│   ├── bootstrap.php               ← Loads vendor/autoload.php + Stubs/WP_Error.php
│   ├── Stubs/WP_Error.php          ← Minimal WP_Error (Brain Monkey mocks functions, not classes)
│   └── Unit/                       ← ConfigTest, BlockLocatorTest
└── src/
    ├── autoload.php                ← PSR-4: GN\McpBridge\ → src/
    ├── Config.php                  ← Filterable settings (exposed abilities, capabilities)
    ├── Server.php                  ← Declares the gn-mcp MCP server (mcp_adapter_init)
    ├── BlockLocator.php            ← Finds/splices blocks in raw post_content by byte offset (used by outline/get-block/update-block)
    └── Abilities/
        ├── SearchPostsAbility.php     ← gn-mcp/search-posts
        ├── GetPostAbility.php         ← gn-mcp/get-post
        ├── GetPostOutlineAbility.php  ← gn-mcp/get-post-outline
        ├── GetBlockAbility.php        ← gn-mcp/get-block
        ├── CreatePostAbility.php      ← gn-mcp/create-post
        ├── UpdatePostAbility.php      ← gn-mcp/update-post
        ├── UpdateBlockAbility.php     ← gn-mcp/update-block
        ├── DeletePostAbility.php      ← gn-mcp/delete-post
        ├── GetPostMetaAbility.php     ← gn-mcp/get-post-meta
        ├── UpdatePostMetaAbility.php  ← gn-mcp/update-post-meta
        └── ListCategoriesAbility.php  ← gn-mcp/list-categories
```

## Required plugins

| Plugin | Purpose |
|--------|---------|
| `mcp-adapter` | Exposes WordPress abilities as MCP tools. Minimum `0.7.0`. Install the wordpress.org zip (`https://downloads.wordpress.org/plugin/mcp-adapter.<version>.zip`, ships `vendor/`) into `wp-content/plugins/mcp-adapter/`, then `wp plugin auto-updates disable mcp-adapter` — the version is pinned deliberately. Never `composer require` it (bundled-library use is deprecated since 0.7.0) nor use a raw GitHub tag (no `vendor/`). |

## Critical technical facts

### Abilities API

- Registration hook: `wp_abilities_api_init`
- Category hook: `wp_abilities_api_categories_init` — `wp_register_ability_category()` requires a `description` field
- Use `execute_callback` (NOT `callback`)
- Each ability's `permission_callback` reads its required capability from `Config::ability_capability()` — never a hardcoded `current_user_can()`

### mcp-adapter server declaration

- Hook: `mcp_adapter_init`, receives the `McpAdapter` instance
- `create_server()` takes 12 positional args (see `src/Server.php`); the list of exposed abilities is `Config::exposed_abilities()`, not a hardcoded array
- Endpoint: `/wp-json/gn-mcp/mcp`

### Default server disabled

mcp-adapter auto-creates its own `mcp-adapter-default-server` (HTTP at `/wp-json/mcp/mcp-adapter-default-server`, plus a WP-CLI STDIO transport) exposing any ability marked `meta.public`. `Server.php` disables it (`add_filter( 'mcp_adapter_create_default_server', '__return_false' )`) — abilities on this site are reachable only through the `gn-mcp` server, with its own filterable capability guards. None of this plugin's abilities use `meta.public` (they use `show_in_rest` instead), so this has no effect on them either way; it only prevents a second, unmanaged surface for any other plugin's public abilities.

### HTTP transport gotcha (mcp-adapter ≥ 0.7, two protocol revisions)

**`2025-11-25` (session-based, what Claude Code negotiates):** the `initialize` response includes an `Mcp-Session-Id` header. Every subsequent call (`tools/list`, `tools/call`, ...) must repeat it **and** send `MCP-Protocol-Version: 2025-11-25`, or the server replies `400 -32600` (`Missing Mcp-Session-Id header` / `MCP-Protocol-Version header is required for a 2025-11-25 session`). Not documented in mcp-adapter's own README examples — verify with `curl` against `initialize` first, capture the session header, then reuse both.

**`2026-07-28` (sessionless):** no `initialize`, no session. Each request carries `params._meta` with `io.modelcontextprotocol/protocolVersion` (exactly `2026-07-28`) and `io.modelcontextprotocol/clientCapabilities` (an object, `{}` is fine), plus the `MCP-Protocol-Version`, `Mcp-Method` and (for `tools/call`) `Mcp-Name` headers; `server/discover` replaces `initialize`. An `initialize` that proposes `2026-07-28` is negotiated down to `2025-11-25`.

## The five filters (per-site settings, applied from a theme)

Never edit this plugin to change behavior on a given site — use these filters from the consuming theme instead (e.g. `wp-content/themes/<theme>/inc/core/mcp-abilities.php`).

### `gn_mcp_bridge_exposed_abilities`

Which abilities are exposed as MCP tools on the `gn-mcp` server.

```php
add_filter( 'gn_mcp_bridge_exposed_abilities', function( array $abilities ) {
	return array_diff( $abilities, array( 'gn-mcp/create-post' ) ); // read-only on this site
} );
```

### `gn_mcp_bridge_ability_capability`

Capability required to run one specific ability.

```php
add_filter( 'gn_mcp_bridge_ability_capability', function( $capability, $ability_name ) {
	return 'gn-mcp/create-post' === $ability_name ? 'publish_posts' : $capability;
}, 10, 2 );
```

### `gn_mcp_bridge_transport_capability`

Capability required to reach the `/gn-mcp/mcp` endpoint at all (checked before any ability-level check). Wired internally to mcp-adapter's own `mcp_adapter_default_transport_permission_user_capability` filter — set this one, not that one.

```php
add_filter( 'gn_mcp_bridge_transport_capability', function() {
	return 'edit_others_posts'; // only editors and up may reach the endpoint
} );
```

### `gn_mcp_bridge_meta_key_allowed`

Whether one post meta key may be read/written by `gn-mcp/get-post-meta` and `gn-mcp/update-post-meta`. Defaults to excluding WordPress-"protected" keys (those starting with `_`, e.g. `_thumbnail_id`, `_edit_lock`).

```php
add_filter( 'gn_mcp_bridge_meta_key_allowed', function( $allowed, $key ) {
	return '_thumbnail_id' === $key ? true : $allowed; // also expose the featured image meta
}, 10, 2 );
```

### `gn_mcp_bridge_allowed_post_types`

Which post types `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, and `update-post-meta` may operate on. Defaults to `post`, `page`, and `wp_block` — abilities stay generic across every site, a given site opts a CPT in explicitly.

```php
add_filter( 'gn_mcp_bridge_allowed_post_types', function( array $post_types ) {
	return array_merge( $post_types, array( 'recipe' ) ); // also expose the recipe CPT
} );
```

A CPT-specific ability (e.g. hardcoding `recipe`-only fields not covered by the generic schema) still belongs in the consuming theme, not here — see the "Bundled skills" note below.

## Adding a new ability

1. Create `src/Abilities/YourAbility.php`, mirroring an existing one: `register()` (static, calls `wp_register_ability()`), `check_permission()` (reads from `Config`), `execute()`.
2. Add its default capability to `Config::DEFAULT_CAPABILITIES`.
3. Call `YourAbility::register()` from `register_abilities()` in `gn-mcp-bridge.php`.
4. `wp-content/plugins/gn-mcp-bridge/src/Abilities/YourAbility.php` is auto-exposed on the `gn-mcp` server unless filtered out via `gn_mcp_bridge_exposed_abilities`.

## Unit tests

`composer test` — pure unit tests, no WordPress install. `config.platform.php` is pinned to `8.2` (= the CI PHP version) so `composer.lock` resolves identically locally and on CI; never raise it above the CI version, and set it before any `composer update`.

- Test pure logic (`Config`, `BlockLocator`) directly. Don't reference the `Server` class from a test: `src/Server.php` registers hooks at file scope (`add_filter`/`add_action` outside any function), so autoloading it needs the isolated `setUpBeforeClass()` Monkey cycle (see the `gns-wordpress-cicd` skill's `file-scope-hooks-detection.md`).
- Never load `gn-mcp-bridge.php` from tests — it exits on the `ABSPATH` guard and also registers hooks at file scope.
- A new ability's `execute()` logic that can be unit-tested should get a `tests/Unit/<Name>Test.php` alongside it.

## French documentation

`docs/fr/README.md` is a French translation of `README.md`, maintained for French-speaking interns. Update it in the same commit whenever `README.md` changes — never let the two drift apart.

## Working Files

`TODO.md` (gitignored) tracks ongoing work — update it as work progresses, not only at session end.

## Bundled skills (`.claude/skills/`)

Self-contained, `gnmcp-` prefixed skills that travel with this plugin when it's copied to another site (no dependency outside this plugin's own folder, unlike the personal `gns-` skill system in `~/.claude/skills/`):

| Skill | Purpose |
|---|---|
| `gnmcp-add-ability` | Scaffold a new ability in `src/Abilities/` |
| `gnmcp-setup-site` | Interactive per-site setup — writes to the theme (`inc/core/mcp-abilities.php`), not the plugin: detects the active theme, asks which abilities/capabilities/role, manages the Application Password |
| `gnmcp-check` | Diagnose a failing MCP connection (curl handshake, `Mcp-Session-Id`, Claude Desktop/`mcp-remote` checks); read-only except when explicitly updating `mcp-adapter` from its wordpress.org zip |

Auto-discovered by Claude Code when the working directory is inside this plugin. Keep this table in sync when a skill is added, renamed, or removed.

## Related personal skill (referenced, not bundled)

`gns-wordpress-blocks-wpcli` (`~/.claude/skills/gns-wordpress-blocks-wpcli/`) covers editing page/block `post_content` directly via WP-CLI, with a local → verify → prod workflow that uses this plugin's `gn-mcp/get-post`/`gn-mcp/update-post` abilities for the prod step (including the ID-is-not-portable-across-environments gotcha — local and prod `wp_block` IDs, and sometimes even slugs, never match for "the same" content; see that skill for the slug/page-ref resolution method).

Deliberately **not** bundled here: it's a content-editing workflow, not plugin-setup tooling, and it isn't gn-mcp-bridge-specific — it works with plain WP-CLI alone. Whoever works on a client's site with this plugin needs it installed separately in their own `~/.claude/skills/`, same as any other personal skill — that's accepted, manual, per-developer setup, not something this plugin ships or automates.

### `/gnmcp-start` (`.claude/commands/gnmcp-start.md`)

Session-opening health check, not a skill — plain slash command, no auto-discovery/auto-trigger. Read-only: checks `mcp-adapter` is active and meets `GN_MCP_BRIDGE_MIN_ADAPTER_VERSION`, `claude mcp list` connection state, and an `initialize` curl handshake, then reports pass/fail. Fixes nothing itself — hands off to `gnmcp-check` on any failure.

**Launch Claude Code from inside this plugin's folder for MCP-bridge work.** Directory-scoped skill discovery means the `gnmcp-*` skills are only discovered when the session starts here — there is no global equivalent to fall back on, so starting elsewhere means they're simply unavailable. Trade-off: some of that work (a site-specific ability like `recipe`, filters in `inc/core/mcp-abilities.php`) belongs in the consuming theme, not here — editing those files by absolute path still works from this cwd, but the theme's own `CLAUDE.md`/skills (if it has any) won't auto-load. If a task is mostly about the theme side, start there instead.

## Diagnosing a failed MCP connection

1. `curl` the `initialize` method directly with `Authorization: Basic <base64(user:app-password)>` and `Accept: application/json, text/event-stream` — confirms auth and the endpoint independently of any client.
2. Capture the `Mcp-Session-Id` response header and repeat it, with `MCP-Protocol-Version: 2025-11-25`, on `tools/list` (see gotcha above).
3. An unauthenticated call must return `401`, not a PHP error — if it 500s, a `permission_callback` is throwing rather than returning `false`/`WP_Error`.
4. `claude mcp list` shows connection state; a server added mid-session needs a session restart (or `/mcp`) before its tools appear.
5. A `403 Forbidden` (plain HTML, not JSON) on every URL, home page included, is a security plugin blocking `curl`'s user agent (e.g. BBQ Pro, active on sites pulled from prod), not an auth failure — retry with `-A 'claude-code/2'`. A missing/invalid Application Password gives `401`.

## Local DB pull wipes Application Passwords

A prod → local DB pull (`wp-sync-db.sh pull`) replaces the local `wp_usermeta` table, which is where Application Passwords live (`_application_passwords`). Any local-only password — the one in `~/.claude.json` for a local `wp-mcp-*` server — is replaced by prod's value (usually none), and that MCP server starts returning `401`. Creating the password on prod first is not the fix: it would mean sharing one credential across environments.

The fix is to list **every** login used by a local MCP server in `MCP_PRESERVE_USERS` in the site's `.local-working/wp-sync.conf` (space-separated). The script saves those users' passwords before the import and restores them after. A login left out of that list loses its password on every pull.

```bash
MCP_PRESERVE_USERS="<admin-login> <author-login>"
```

When adding a new local MCP server for another login (e.g. an Author-role test account), add that login to `MCP_PRESERVE_USERS` in the same step. A password already lost can't be recovered (only its hash was stored) — create a new one and update `~/.claude.json`.
