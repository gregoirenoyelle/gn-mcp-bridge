# GN MCP Bridge

🇫🇷 [Version française](docs/fr/README.md)

Exposes WordPress content abilities as an MCP server, consumable by Claude Code over HTTP, authenticated via WordPress Application Passwords.

Designed to be copied identically across WordPress sites — nothing site-specific is ever edited in this plugin. Per-site behavior (which abilities are exposed, which capability each requires) is set from the consuming theme via three filters.

## Why this exists

Claude Code can already inspect and change a WordPress site directly via WP-CLI over a shell (`wp eval`, `wp post create`, ...) whenever it has local or SSH access to the machine the site lives on. That access is unrestricted (bypasses WordPress's own permission checks entirely), tied to one operator's machine, and unauthenticated — there's no WordPress account behind it.

This plugin is a different, narrower channel:

| | Direct WP-CLI over a shell | This plugin (MCP) |
|---|---|---|
| Access | Unrestricted — direct PHP execution, bypasses WordPress permissions | Limited to the abilities explicitly registered here |
| Auth | None — shell access is de facto admin | WordPress Application Password tied to one specific account |
| Per-role limits | None | Filterable per ability, per site (`gn_mcp_bridge_ability_capability`) |
| Reach | Only sites reachable by shell/SSH from the operator's machine | Any site exposing the endpoint, including ones with no shell access |
| Who can use it | The one operator with that shell access | Any MCP client, each with its own WordPress account and its own Application Password |

Use this plugin when the caller shouldn't have (or can't have) shell access to the site — a teammate, another AI client, or a remote site with no SSH — and only needs a specific, auditable set of actions.

## When it's actually the right tool (not just "also possible")

A task Claude Code can already do via WP-CLI over a shell isn't a good MCP example — it doesn't show what MCP adds. It's the right tool specifically when:

1. **No shell access to the site at all** — a client-managed host, shared hosting, anything without SSH. MCP is then the *only* way in, not an alternative to the shell.
2. **A non-technical person needs to act on the site** — a client or teammate drafting content via Claude Desktop/mobile, with their own Application Password and deliberately narrow rights (drafts only, no publish, no server access at all).
3. **An automated pipeline needs write access with a scoped identity** — e.g. an n8n workflow turning a transcript into a draft post. Give it `create-post` with a "drafts only" capability, not an SSH key.
4. **You, from a device other than your dev machine** — phone, another computer — searching or drafting without your terminal.
5. **Access that must be individually revocable** — a contractor's Application Password can be deleted on its own, without touching your own admin/SSH access.

### Example prompts

Read-only:

- "Search this site for posts mentioning [topic]"
- "List the categories on this site with their post counts"
- "Get the full content of the post with slug [slug]"

Write, still safe (drafts only):

- "Draft a post titled [title] summarizing what we discussed"
- "Check if a post about [topic] already exists before creating a new one"

Combining both (the kind of task worth routing through MCP — done by a scoped account, not an admin shell):

- "Search all posts in category [category], summarize them, and draft a new post that synthesizes them" — realistic when a content assistant with a drafts-only account does it, not when the same operator already has full shell access
- "As the contributor account, try to publish a post directly and confirm it's refused" — a permission-boundary check, meaningful specifically because the account is capability-restricted

## Security model & limits

Everything above assumes the caller has **only** the MCP transport — an Application Password reaching `/wp-json/gn-mcp/mcp` over HTTPS, nothing else. That's the boundary the whole capability-restriction model (per-role, per-ability, revocable) is built on.

**That boundary breaks down if the same caller also has Claude Code (or any agent) connected directly to the site's codebase** — SSH, a local checkout, a CI runner with repo write access, etc. Someone with that kind of access already has the "Direct WP-CLI over a shell" row from the table above (unrestricted, bypasses WordPress permissions) — restricting their *MCP* account buys nothing, since they can just act outside MCP entirely.

Worse, it can make things actively easier for them: this plugin's bundled skills (`.claude/skills/gnmcp-*`, see `CLAUDE.md`) travel with it to every site it's copied to, including a client's. `gnmcp-add-ability` in particular exists to scaffold new server-side abilities — new PHP, with its own `permission_callback` — and to do that job well, it needs exactly the kind of codebase access described above. Handed to someone with codebase access, it's a documented, ready-made path to registering a new capability on the shared plugin itself (and, if they're not careful, weakening a `permission_callback` in the process) — not something a client should run themselves.

**Where this plugin's model actually holds**: the caller has *no* shell/SSH/file access to the site at all — the first scenario listed in "When it's actually the right tool" above (client-managed host, shared hosting, or simply a client/teammate who was never given server access). In that case there's no codebase for them to reach, bundled skills included, and the capability filters are the only door.

**In short**: don't hand a client or teammate an Application Password *and* codebase/Claude-Code access to the same site — pick one. The bundled `gnmcp-*` skills are operator tools (site maintainer only, e.g. Grégoire) — never run them on a client's behalf or expose them to a client's own Claude Code session.

## Requirements

- WordPress with the Abilities API (core, WP ≥ 7.0)
- [`mcp-adapter`](https://wordpress.org/plugins/mcp-adapter/) ≥ 0.7.0, active
- If you're syncing content (e.g. reusable blocks) between environments using `get-post`/`update-post`: read [this gotcha writeup](https://gist.github.com/gregoirenoyelle/36033c44cf42ad5b35f377de5a163e59) first — a post's ID, and sometimes even its slug, is not portable between a local install and production

The Abilities API (core) only lets you *register* capabilities — it has no idea how to speak the MCP protocol. `mcp-adapter` is what turns registered abilities into an actual MCP server (HTTP transport, `initialize`/`tools/list`/`tools/call`, session handling). Without it, abilities exist but no MCP client can reach them — that's why it's a hard dependency (`Requires Plugins: mcp-adapter`), not an optional add-on.

**What each layer actually does:**

- **Abilities API** — a generic, transport-agnostic capability registry. `wp_register_ability()` declares a name, `input_schema`/`output_schema` (JSON Schema), an `execute_callback`, and a `permission_callback`. Conceptually similar to `register_rest_route()`: it describes *what can be done* and *who's allowed*, without defining any wire format.
- **MCP protocol** — a fixed JSON-RPC 2.0 contract, unrelated to WordPress: `initialize` (capability negotiation), `tools/list` (enumerate tools), `tools/call` (execute one), plus a session mechanic (`Mcp-Session-Id` returned by `initialize`, required on every following call along with `MCP-Protocol-Version: 2025-11-25`; the newer `2026-07-28` revision is sessionless).
- **`mcp-adapter`** — the bridge: `McpAdapter::create_server()` turns the exposed abilities into MCP tool definitions (`tools/list` reflects each ability's schemas), routes `tools/call` to the right `execute_callback`, wraps each ability's `permission_callback` into the MCP authorization flow, and handles the session/transport mechanics itself.

### Why this plugin isn't redundant with `mcp-adapter`

`mcp-adapter` is pure protocol plumbing. It doesn't register a single ability, define any content, or make any per-site policy decision — hand it zero abilities and it exposes zero tools. This plugin is the layer that actually gives it something to serve, plus the site-level policy `mcp-adapter` itself has no concept of:

1. **Registers the real abilities** — `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, `update-post-meta`, `list-categories` (see [`docs/mcp-abilities.md`](docs/mcp-abilities.md)): the actual WordPress logic, sanitization, and schemas.
2. **Declares a dedicated `gn-mcp` server** instead of relying on `mcp-adapter`'s own auto-created default server — `Server.php` explicitly disables that default (`mcp_adapter_create_default_server` filter) to avoid a second, unmanaged surface.
3. **Provides the five per-site filters** (`gn_mcp_bridge_exposed_abilities`, `gn_mcp_bridge_ability_capability`, `gn_mcp_bridge_transport_capability`, `gn_mcp_bridge_meta_key_allowed`, `gn_mcp_bridge_allowed_post_types`, below) — `mcp-adapter` only enforces whatever `permission_callback` it's handed; it has no filter system of its own for adjusting exposure or capabilities per site.

In short: without `mcp-adapter`, this plugin can't expose anything (hard dependency, `Requires Plugins: mcp-adapter`). Without this plugin, `mcp-adapter` has nothing to expose.

**Gotcha: `mcp-adapter` deactivated after the fact.** `Requires Plugins: mcp-adapter` only blocks *activating* this plugin without `mcp-adapter` already active — it doesn't stop `mcp-adapter` from being deactivated afterward via WP-CLI or a hosting panel (the Plugins-page UI does block this, but not every deactivation path goes through it). If that happens, `gn-mcp-bridge` stays "active" but inert: `mcp_adapter_init` never fires, so `Server.php` never registers the `gn-mcp` server, and `/wp-json/gn-mcp/mcp` simply doesn't exist. `check_mcp_adapter_dependency()` shows an admin notice in `wp-admin`, but an MCP client hitting the dead endpoint just gets WordPress's generic `rest_no_route` 404 — nothing that points back at the cause. `gnmcp-check`'s step 1 checks for this route explicitly for that reason.

## What it exposes

Eleven abilities, exposed as MCP tools on the `gn-mcp` server: `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, `update-post-meta`, `list-categories`.

`get-post-outline` + `get-block` + `update-block` are a targeted read/write path for long posts: an outline (block tree summary, well under the MCP output limit) instead of the full `get-post` content, then one block's raw markup, then a `replace`/`insert_before`/`insert_after`/`delete` write that splices only that block's byte range — `update-post`'s whole-`post_content` replace stays the right tool for short posts or a full rewrite.

Full reference (input/output schemas, default capabilities, notes): [`docs/mcp-abilities.md`](docs/mcp-abilities.md).

Endpoint: `/wp-json/gn-mcp/mcp`.

## Setup on a new site

1. Install `mcp-adapter` ≥ 0.7.0 from its wordpress.org zip (it ships `vendor/`): `wp plugin install https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip --activate`, then `wp plugin auto-updates disable mcp-adapter` (the version is pinned deliberately).
2. Copy this plugin (`gn-mcp-bridge/`) as-is, `wp plugin activate gn-mcp-bridge`.
3. Create `inc/core/mcp-abilities.php` in the consuming theme (see "Per-site settings" below), include it from `functions.php`.
4. Generate an Application Password for the WordPress account that will use Claude Code:
   ```bash
   wp user application-password create <login> "Claude Code MCP" --porcelain
   ```
5. Verify the handshake with `curl` before touching any client config:
   ```bash
   USER=<login>
   PASS="<application-password>"
   AUTH=$(printf "%s:%s" "$USER" "$PASS" | base64)
   curl -si -X POST "https://<site>.test/wp-json/gn-mcp/mcp" \
     -H "Authorization: Basic $AUTH" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json, text/event-stream" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl-test","version":"1.0"}}}'
   ```
   Expect `HTTP/2 200` with `serverInfo` in the body and an `Mcp-Session-Id` response header. Every follow-up call (`tools/list`, `tools/call`) must repeat that header and add `MCP-Protocol-Version: 2025-11-25`.
6. Connect Claude Code, on the machine that will use it:
   ```bash
   claude mcp add --transport http --scope user wp-mcp-<short-site-name> \
     "https://<site>.test/wp-json/gn-mcp/mcp" \
     --header "Authorization: Basic <base64(login:application-password)>"
   ```
7. Restart the Claude Code session (or `/mcp`) — a server added mid-session doesn't expose its tools until reload.

### Naming convention

`wp-mcp-[short-site-name]`, e.g. `wp-mcp-gregoirenoyelle` for `gregoirenoyelle.test`. Pick a slug that's actually distinctive from the start — a name like `wp-mcp-mod` looks fine until a second `mod`-something site shows up. A short project date/ID tends to age better than a folder-name fragment.

Multiple Claude Code MCP entries can point at the **same site** with **different WordPress accounts** — useful for verifying that a per-ability capability restriction (`gn_mcp_bridge_ability_capability`) actually holds for a given role, rather than trusting it in theory. Extend the slug with the account/role: `wp-mcp-[short-site-name]-[role-or-account]`, e.g. `wp-mcp-gregoirenoyelle-author` (site `gregoirenoyelle.test`, a separate account with the `author` role) next to the admin's own `wp-mcp-gregoirenoyelle`. Each entry needs its own Application Password (see "Setup on a new site" above, repeated per account) — there is no conflict as long as the full names differ, since Claude Code namespaces every tool by server name (see below).

**Why there's never a naming conflict**: each MCP server gets a unique key in `~/.claude.json` (scope `user`), a separate Application Password (independently revocable), and its own isolated ability set — even if two servers expose abilities with the identical name (`gn-mcp/create-post`) on two different sites or accounts, they don't collide, because each is scoped to its own server.

**Disambiguating which server actually gets used**: Claude Code exposes every MCP tool prefixed by its server name — `mcp__<server-name>__<ability>`. With both `wp-mcp-gregoirenoyelle` and `wp-mcp-gregoirenoyelle-author` connected at once, you'd see `mcp__wp-mcp-gregoirenoyelle__gn-mcp-create-post` and `mcp__wp-mcp-gregoirenoyelle-author__gn-mcp-create-post` side by side. Two ways to be sure the intended one runs:

- Run `/mcp` to see the connected servers and confirm the tool list under the one you mean.
- Name the server explicitly in the prompt (e.g. "use `wp-mcp-gregoirenoyelle-author` to create a post") — when multiple connected servers expose an equivalent tool, an unqualified request lets the model pick either one, which defeats the point of a role-restricted test.

## Client setup: Claude Desktop for non-technical users

Claude Code's `--header` flag (step 6 above) has no equivalent in Claude Desktop's own UI: Desktop's **Settings → Connectors** only accepts remote MCP servers over OAuth, and it refuses any remote (HTTP/SSE) server declared directly in `claude_desktop_config.json`. There is no field for a raw `Authorization: Basic ...` header.

The workaround is [`mcp-remote`](https://github.com/geelen/mcp-remote), a small local proxy: it runs as an ordinary **local (stdio)** command — which `claude_desktop_config.json` *does* accept — and forwards every request to the WordPress endpoint with the header injected. From the client's side, this is invisible: they never see a terminal, they just open Claude Desktop and the tools are there.

This is a one-time technical setup **you (Grégoire) do on the client's machine**, not something the client configures themselves.

### Steps (done once, on the client's computer)

1. **Check Node.js is installed** (`mcp-remote` needs `npx`):
   ```bash
   node -v
   ```
   If missing, install it first (e.g. from [nodejs.org](https://nodejs.org) — LTS version).

2. **Generate a dedicated Application Password** for this specific client, on the WordPress account they'll act as (ideally with a "drafts only" capability via `gn_mcp_bridge_ability_capability` — see below):
   ```bash
   wp user application-password create <client-login> "Claude Desktop - <client name>" --porcelain
   ```

3. **Verify the handshake with `curl`** first (same command as step 5 of "Setup on a new site" above) — confirm `HTTP/2 200` before touching Desktop's config.

4. **Locate `claude_desktop_config.json`** on the client's machine:
   - macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - Windows: `%APPDATA%\Claude\claude_desktop_config.json`

5. **Add the server entry**, with the Basic Auth string inlined directly (no env-var indirection — keeps it simple and avoids a known bug, see note below):
   ```json
   {
     "mcpServers": {
       "wp-mcp-<short-site-name>": {
         "command": "npx",
         "args": [
           "mcp-remote",
           "https://<site>.test/wp-json/gn-mcp/mcp",
           "--header",
           "Authorization: Basic <base64(login:application-password)>"
         ]
       }
     }
   }
   ```
   If the file already has other `mcpServers` entries, add this one alongside them rather than replacing the file.

6. **Restart Claude Desktop** completely (quit, not just close the window).

7. **Verify from the client's side**: open a new conversation, check the tool/plug icon shows `wp-mcp-<short-site-name>` connected, and run a harmless read-only prompt (e.g. "List the categories on this site").

Naming convention: same as Claude Code, `wp-mcp-[short-site-name]`.

### Known gotchas

- **Windows**: a documented bug in some `mcp-remote`/Desktop versions mangles `--header` values containing spaces on Windows. If the header is silently dropped or requests come back `401`, test alternate quoting or check for an `mcp-remote` release note about it before assuming the Application Password is wrong.
- **The Application Password sits in plaintext** in `claude_desktop_config.json` on the client's machine — same trust model as an SSH key on a laptop. If the client's machine is shared, lost, or the engagement ends, revoke that specific Application Password from WordPress (`wp user application-password delete <client-login> <uuid>` or via their user profile) rather than rotating a shared credential.
- **This bypasses the official Connectors OAuth flow entirely.** It's a pragmatic bridge, not the sanctioned Anthropic path — if Claude Desktop later supports custom headers natively in Connectors, prefer that instead.
- Scope the Application Password's account tightly (`gn_mcp_bridge_ability_capability`, drafts-only) — this channel is more likely to end up on a non-technical person's machine than your own dev setup, so the blast radius of a leaked credential should be small by design.
- **A local DB pull from prod (`wp-sync-db.sh pull`) wipes local Application Passwords.** They live in `wp_usermeta` (`_application_passwords`), and a full DB pull overwrites that table with the remote's — so any local MCP server registered in `~/.claude.json` starts failing with `401` right after, since its stored credential no longer matches anything in the local DB. Fix: set `MCP_PRESERVE_USERS="<login> <login2>"` in that project's `.local-working/wp-sync.conf` — `wp-sync-db.sh` then backs up and restores those logins' `_application_passwords` value around the pull, so the existing credential keeps working with no regeneration needed.

## Per-site settings (five filters)

Set these from the consuming theme, never by editing this plugin:

| Filter | Purpose |
|---|---|
| `gn_mcp_bridge_exposed_abilities` | Which abilities are exposed as MCP tools on this site |
| `gn_mcp_bridge_ability_capability( $capability, $ability_name )` | WordPress capability required to run one ability |
| `gn_mcp_bridge_transport_capability` | Capability required to reach the endpoint at all |
| `gn_mcp_bridge_meta_key_allowed( $allowed, $key )` | Which post meta keys `get-post-meta`/`update-post-meta` may read/write |
| `gn_mcp_bridge_allowed_post_types` | Which post types the CRUD abilities may operate on (default `post`, `page`, and `wp_block`) |

Full reference with examples for each: [`CLAUDE.md`](./CLAUDE.md).

## Adding a new ability

See the "Adding a new ability" section in [`CLAUDE.md`](./CLAUDE.md). Start Claude Code from inside this plugin's own folder for that work — see `CLAUDE.md`'s "Bundled skills" note on why.

## Development

```bash
composer install
composer test
```

Unit tests (`tests/Unit/`) run against Brain Monkey WordPress function mocks — no database or `wp-load.php` required. GitHub Actions runs a PHP syntax check and the PHPUnit suite on PHP 8.2 for every push to `main`/`dev` and every pull request to `main`.

Composer is dev tooling only: the plugin loads its classes through its own `src/autoload.php`, so `vendor/` is never needed at runtime and is not shipped with the plugin.

## License

GPL-2.0-or-later — see [`LICENSE`](./LICENSE).
