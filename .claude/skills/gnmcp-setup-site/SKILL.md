---
name: gnmcp-setup-site
description: Interactive per-site configuration for gn-mcp-bridge — writes to the theme (inc/core/mcp-abilities.php filter file), not the plugin. Detects the active theme and manages the Application Password for the target WordPress account; abilities/capability/meta-key/post-type filters are added on explicit dev request, not asked interactively (devs, not end users, use this). Trigger keywords: configure gn-mcp-bridge, set up MCP for this site, who can use MCP, gn-mcp-bridge per-site settings.
user-invocable: true
---

# gnmcp-setup-site — Interactive per-site configuration

Bundled with the `gn-mcp-bridge` plugin — travels with it when the plugin is copied to another site, no dependency outside this plugin's own folder.

Never edit `gn-mcp-bridge` itself for a site-specific need. Everything site-specific lives in that site's own consuming theme, in `wp-content/themes/<theme>/inc/core/mcp-abilities.php`, wired via the plugin's five filters: `gn_mcp_bridge_exposed_abilities`, `gn_mcp_bridge_ability_capability`, `gn_mcp_bridge_transport_capability`, `gn_mcp_bridge_meta_key_allowed`, `gn_mcp_bridge_allowed_post_types`.

## Step 1 — Confirm the plugin is active

```bash
wp plugin list --path=<site> | grep -E "mcp-adapter|gn-mcp-bridge"
```

Both must be `active`, and `mcp-adapter` must be `0.7.0` or higher (`wp plugin get mcp-adapter --field=version --path=<site>`); a missing or older `mcp-adapter` is installed from its wordpress.org zip (`wp plugin install https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip --activate --path=<site>`), followed by `wp plugin auto-updates disable mcp-adapter --path=<site>`. If `gn-mcp-bridge` is missing or `mcp-adapter` inactive, stop here and point to the plugin's `README.md` "Setup on a new site" section instead of proceeding.

## Step 2 — Detect the active theme

```bash
wp theme list --status=active --path=<site>
```

Note the theme slug and its `wp-content/themes/<theme>/` path — everything from here on is written there, never in `gn-mcp-bridge/`.

## Step 3 — Check for an existing `inc/core/mcp-abilities.php`

- If it exists, read it fully before touching it — an existing site may already have custom capability logic; never blindly overwrite.
- If it doesn't exist, it will be created in Step 6, and must be `require`'d from the theme's `functions.php` (check whether `functions.php` already has an `inc/core/` inclusion pattern to follow, or add one).

## Step 4 — Ask who this Application Password is for

This plugin and its skills are used by devs, not end users — abilities/capability/meta-key/post-type exposure is something a dev reads and edits directly (via the five filters, or `gnmcp-add-ability` for a new ability), not something to be walked through interactively. The only thing that actually needs asking here:

1. **Who is this for?** An existing WordPress account, or does a new one need creating (and at what role)? This determines which Application Password gets generated/reused in Step 7.

If the dev separately wants a non-default exposure/capability/meta-key/post-type set, point them at the five filters documented in `CLAUDE.md` (`gn_mcp_bridge_exposed_abilities`, `gn_mcp_bridge_ability_capability`, `gn_mcp_bridge_transport_capability`, `gn_mcp_bridge_meta_key_allowed`, `gn_mcp_bridge_allowed_post_types`) — don't ask about them by default, and never guess a filter override on their behalf.

## Step 5 — If the dev asks for a non-default filter, cross-check `Config::DEFAULT_CAPABILITIES` first

Only relevant if the dev separately requests a non-default exposure/capability/meta-key/post-type — skip entirely when they're keeping defaults. Read `wp-content/plugins/gn-mcp-bridge/src/Config.php` to confirm the actual default capability per ability before proposing filter code — don't rely on memory of past sessions, the plugin may have gained abilities since.

## Step 6 — Write or update `inc/core/mcp-abilities.php` (only if Step 5 applies)

`wp-content/themes/gncom-v8/inc/core/mcp-abilities.php` is the intended *structural* reference for this plugin's test site (`gregoirenoyelle`) — as of 2026-09-17 the file doesn't exist yet, so there's nothing to copy from it. Once created, it should follow: namespace matching the theme's own `Core` sub-namespace, one function per gesture, hooks at the bottom, phpDoc per this project's PHP standards. Re-read the file at the time of use rather than trusting this note — a prior configuration pass may have added filters or abilities since.

Use this template for each gesture the dev asked for, one function per filter, following the same file conventions:

```php
/**
 * Drops abilities this site doesn't expose over MCP.
 *
 * @since 1.0.0
 * @param string[] $abilities Default ability names.
 * @return string[] Filtered ability names.
 */
function filter_exposed_abilities( array $abilities ) {
	return array_diff( $abilities, array( 'gn-mcp/create-post' ) );
}
add_filter( 'gn_mcp_bridge_exposed_abilities', __NAMESPACE__ . '\filter_exposed_abilities' );

/**
 * Raises the capability required for specific abilities on this site.
 *
 * @since 1.0.0
 * @param string $capability   Default capability for this ability.
 * @param string $ability_name Ability name.
 * @return string Filtered capability.
 */
function filter_ability_capability( $capability, $ability_name ) {
	switch ( $ability_name ) {
		case 'gn-mcp/get-post-meta':
		case 'gn-mcp/update-post-meta':
			return 'edit_others_posts';
		default:
			return $capability;
	}
}
add_filter( 'gn_mcp_bridge_ability_capability', __NAMESPACE__ . '\filter_ability_capability', 10, 2 );

/**
 * Adds this site's CPTs to the post types reachable over MCP.
 *
 * @since 1.0.0
 * @param string[] $post_types Default allowed post types.
 * @return string[] Filtered post types.
 */
function filter_allowed_post_types( array $post_types ) {
	return array_merge( $post_types, array( 'recipe' ) );
}
add_filter( 'gn_mcp_bridge_allowed_post_types', __NAMESPACE__ . '\filter_allowed_post_types' );
```

Only add the functions/filters the dev actually asked for — don't scaffold unused gestures. If the file already exists, merge new gestures into it rather than replacing it wholesale — preserve any existing site-specific logic found in Step 3 (including a theme-only ability registration like `register_theme_ability()`, which lives in the same file but is unrelated to these filters).

## Step 7 — Application Password

- If reusing an existing account: `wp user application-password list <login> --path=<site>` to check what's already there.
- If a new one is needed: `wp user application-password create <login> "<descriptive name — e.g. site + purpose>" --path=<site> --porcelain`.
- Never print the raw password back into a committed file or into `CLAUDE.md`/`README.md` — it's a live secret. It only belongs in the client-side config (`claude mcp add --header`, or `claude_desktop_config.json` for the `mcp-remote` workaround — see `README.md`).
- **If this site is synced locally via `wp-sync-db.sh` (see `gns-ssh-server`)**: a `pull` overwrites local `wp_usermeta`, wiping this Application Password and breaking the MCP connection with `401`. Check `.local-working/wp-sync.conf` for a `MCP_PRESERVE_USERS` entry covering `<login>` — add it if missing (`wp-sync-init.sh` leaves a commented template line for this in newly generated confs). See `README.md` "Known gotchas".

## Step 8 — Verify

```bash
wp eval 'echo \GN\McpBridge\Config::ability_capability( "gn-mcp/your-ability" );' --path=<site>
wp eval 'var_dump( \GN\McpBridge\Config::exposed_abilities() );' --path=<site>
```

Confirm the values match defaults (or, if Step 5/6 ran, the dev's requested overrides). For end-to-end role verification, create a second test account at the target role and run through `gnmcp-check`'s curl flow with its own Application Password — a denied write must come back as `{"isError":true}` in the JSON-RPC body (HTTP 200), never an HTTP 500.

## Step 9 — Register with Claude Code (if this session's client is Claude Code)

Ask the user two things — that's all that's needed here; once given, run the registration immediately, no separate "want me to?" confirmation:

1. **Name** for this MCP server entry.
2. **Scope**: `local` (default — tied to the exact project directory this command runs in; only visible in sessions started there) or `user` (visible from any project/session on this machine). If the site's work happens from a specific plugin/theme folder (as with this plugin), `local` is usually right; if it should be reachable from anywhere, use `user`.

```bash
claude mcp add --transport http <name> "https://<site>.test/wp-json/gn-mcp/mcp" \
  --header "Authorization: Basic $(printf '%s:%s' '<login>' '<application-password>' | base64)" \
  --scope <local|user>
```

A server added mid-session needs a session restart (or `/mcp`) before its tools appear — see `gnmcp-check` step 5. If it was added with `local` scope and doesn't show up in a later session, check whether that session started in the same project directory — see `README.md` / `CLAUDE.md`'s note on launching from this plugin's folder.

## Related

- Adding a brand-new ability before configuring its exposure: `gnmcp-add-ability`
- Diagnosing a connection once configured: `gnmcp-check`
- Claude Desktop / non-technical client connection setup: `README.md`, "Client setup: Claude Desktop"
