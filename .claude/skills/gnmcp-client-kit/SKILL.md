---
name: gnmcp-client-kit
description: "Build a ready-made Claude Code folder (kit) for a client's own computer — CLAUDE.md about content, .mcp.json pointing at the site's gn-mcp endpoint, .claude/settings.json permissions, and client start-up instructions — then hand the operator a script that injects the Application Password, checks the handshake and zips the kit. Runs on the operator's machine only; the client just unzips the folder and runs claude inside it. Trigger keywords: client kit, client Claude Code setup, prepare MCP folder for a client, send MCP config to client, gnmcp client kit."
user-invocable: true
---

# gnmcp-client-kit — Ready-made Claude Code folder for a client

Bundled with the `gn-mcp-bridge` plugin — travels with it when the plugin is copied to another site, no dependency outside this plugin's own folder.

Operator tool. It runs on the operator's (site maintainer's) machine, from a dev copy of this plugin, and produces a folder that the **client** uses on **their** computer. The client's machine never receives this plugin, this skill, or any site code — see `docs/client-setup-claude-code.md`, "The one rule".

The kit it builds:

```text
claude-<short-site-name>/
├── CLAUDE.md               ← content-only instructions, in the client's language
├── START-HERE.md           ← (or LISEZ-MOI.md in French) client start-up steps
├── .mcp.json               ← gn-mcp HTTP server, credential injected by the script
└── .claude/settings.json   ← reads allowed, writes ask, delete denied
```

## Hard rules

- **Never ask for, accept, or write the Application Password in this conversation.** It is a live production secret: it only ever goes through `scripts/fill-credential.sh`, run by the operator in a separate terminal. If the operator pastes it here anyway, don't use it: tell them to revoke that password and create a new one.
- Write the kit only under this plugin's `.local-working/client-kits/` (gitignored, excluded from releases). Never inside a theme, a repo, or a synced folder.
- No SSH, no WP-CLI, anywhere in this flow: the client's hosting is unknown. The kit only talks HTTPS to the endpoint, and the Application Password is created, checked and revoked from the WordPress admin only. Never propose a `wp user application-password` command here.
- Don't touch the site's server side (theme filters, roles) from here — that's `gnmcp-setup-site`. This skill only checks it was done.

## Step 1 — Gather the inputs

Ask in one go, with defaults where they exist:

1. **Short site name** (`<short-site-name>`): the server is named `wp-mcp-<short-site-name>`, the folder `claude-<short-site-name>` (naming convention in `README.md`).
2. **Production domain** (no `https://`, no path). The endpoint is `https://<domain>/wp-json/gn-mcp/mcp`. Refuse a `.test` or `http://` domain: a client kit always targets production.
3. **Site name** as the client calls it (for the docs' titles).
4. **Client's language**: `fr` or `en` (picks the templates).
5. **Operator contact** shown to the client on errors (name + email/phone).
6. **Editorial context** (optional): tone, extra rules (SEO title length, excerpt, links, images...). Unknown → leave a clearly marked placeholder line for the operator to fill.

## Step 2 — Check the server side was done

Ask the operator to confirm, don't do it yourself:

- The client has their own WordPress account, role `author` or `editor`, never `administrator`.
- The site's theme filters (`inc/core/mcp-abilities.php`) restrict what the client may do — typically `gn-mcp/delete-post` and `gn-mcp/update-term` removed — and that's deployed to prod.

If not done, stop and point to `gnmcp-setup-site` and `docs/client-setup-claude-code.md`, "Before you go".

Then ask which abilities this client's account actually has, to build the permission lists. Start from the plugin's defaults (`Config::DEFAULT_CAPABILITIES` in `src/Config.php`) minus what the theme filters out, plus any theme-registered abilities the operator names. `fill-credential.sh` prints the real tool list at the end, so a mismatch gets caught there.

## Step 3 — Categories (optional, read-only)

If an MCP server for this same production site is connected in this session (`mcp__wp-mcp-<site>...__gn-mcp-list-categories`), call `gn-mcp/list-categories` and use the names for `{{CATEGORIES}}`. Read-only, nothing else. Otherwise ask the operator, or leave `<to fill>`.

## Step 4 — Write the kit

Kit path: `<this plugin>/.local-working/client-kits/claude-<short-site-name>/`. If it already exists, list its contents and ask before overwriting (it may hold a live credential: an existing `.mcp.json` without the `__BASIC_AUTH__` placeholder means a filled kit was never deleted — say so).

1. **`CLAUDE.md`** from `templates/CLAUDE.<lang>.md`, replacing `{{SITE_NAME}}`, `{{DOMAIN}}`, `{{SERVER}}`, `{{LANGUAGE}}`, `{{TONE}}`, `{{CATEGORIES}}`, `{{OPERATOR_CONTACT}}`. `{{EDITORIAL_RULES}}` becomes one `- ` bullet per extra rule, or is removed with its line when there are none. Drop any instruction that refers to an ability the client doesn't have (e.g. the update-block line if `update-block` is filtered out).
2. **`START-HERE.md`** (en, from `templates/START-HERE.en.md`) or **`LISEZ-MOI.md`** (fr, from `templates/LISEZ-MOI.fr.md`), replacing `{{SITE_NAME}}`, `{{DOMAIN}}`, `{{SERVER}}`, `{{KIT_NAME}}` (= `claude-<short-site-name>`), `{{OPERATOR_CONTACT}}`.
3. **`.mcp.json`**, with the literal placeholder — never a real value:

   ```json
   {
     "mcpServers": {
       "wp-mcp-<short-site-name>": {
         "type": "http",
         "url": "https://<domain>/wp-json/gn-mcp/mcp",
         "headers": {
           "Authorization": "Basic __BASIC_AUTH__"
         }
       }
     }
   }
   ```

4. **`.claude/settings.json`**, every rule prefixed `mcp__wp-mcp-<short-site-name>__`, tool names with `/` turned into `-` (`gn-mcp/get-post` → `gn-mcp-get-post`):
   - `allow`: the read abilities the client has (`search-posts`, `get-post`, `get-post-outline`, `get-block`, `get-post-meta`, `list-categories`, plus theme read abilities).
   - `ask`: the write abilities the client has (`create-post`, `update-post`, `update-block`, `update-post-meta`, plus theme write abilities).
   - `deny`: `gn-mcp-delete-post` and `gn-mcp-update-term`, always, even if filtered out server-side (defense in depth).

   No `enabledMcpjsonServers`/`enableAllProjectMcpServers`: the client approves the server once at first launch, which is what the start-up instructions describe.

Validate both JSON files (`python3 -m json.tool <file> > /dev/null`).

## Step 5 — Hand over to the operator

Print, in this order:

1. **Create the Application Password** for the client's account, named `Claude Code - <client's machine name>` (one per machine), from the WordPress admin: *Users → All Users → edit the client's user* (or the client logs in and opens *Users → Profile*) → **Application Passwords** → name → **Add New Application Password**, then copy it (shown only once).
2. **In a separate terminal window** (not through Claude Code, not with `!`), run, with absolute paths:

   ```bash
   bash <this plugin>/.claude/skills/gnmcp-client-kit/scripts/fill-credential.sh \
     <this plugin>/.local-working/client-kits/claude-<short-site-name>
   ```

   It asks for the login and the password (hidden), checks the `initialize` handshake (writes nothing unless HTTP 200), prints the tools this account sees, injects the credential into `.mcp.json`, and creates `claude-<short-site-name>.zip` next to the folder.
3. **Compare the printed tool list** with `.claude/settings.json`; if they differ, come back here and fix the lists (then re-zip: `cd <client-kits> && zip -rq claude-<short-site-name>.zip claude-<short-site-name> -x '*.DS_Store'`).
4. **Send the zip** through an encrypted, expiring share (password manager item share, e.g. 1Password), never plain email, Slack, or a public transfer link. Paste the start-up file's content into the message too, so the client can read it before unzipping.
5. **Delete the local kit** once sent: offer `rm -rf <kit folder> <kit zip>`, run it only on the operator's confirmation.

## Rotation and offboarding

- **New password / new machine**: revoke the old Application Password (WordPress admin, the client's user → *Application Passwords* → **Revoke**), run this skill again for the same site (Step 4 overwrites the kit with a fresh placeholder), fill and send the new kit. The client replaces the whole folder.
- **End of the engagement**: revoking the Application Password in the WordPress admin is enough to cut access; the kit becomes useless.

## Related

- Server-side setup (theme filters, account, Application Password for the operator's own use): `gnmcp-setup-site`
- Connection failing: `gnmcp-check`
- Full client guide (operator-at-the-machine path, kit path, gotchas): `docs/client-setup-claude-code.md`
