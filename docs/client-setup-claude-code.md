# Client setup: Claude Code on the client's computer

🇫🇷 [Version française](fr/client-setup-claude-code.md)

How to connect a client's own Claude Code to their WordPress site through `gn-mcp-bridge`. Two ways to do it:

- **[Operator at the machine (terminal)](#on-the-clients-machine)**: the operator (the site maintainer) sets everything up on the client's machine with the `claude` CLI. The client then starts Claude Code from one dedicated folder.
- **[Client on their own (ready-made kit)](#client-on-their-own-ready-made-kit)**: the operator builds a ready-made folder with the `gnmcp-client-kit` skill and sends it. The client installs Claude Code, puts the folder in their home folder and runs `claude` inside it, with no MCP configuration to type.

Both end up with the same layout: one dedicated content folder, an MCP server only active in that folder, and a `CLAUDE.md` about content.

**No SSH anywhere on the client's side.** The client's Claude Code only talks HTTPS to `/wp-json/gn-mcp/mcp`, authenticated by the Application Password. That's the setup the security model is built for: the client never gets shell access to the server. The operator's side doesn't use it either: you never know what the client's hosting allows, so the Application Password is created, checked and revoked from the **WordPress admin** only. That works on any host.

For clients who would rather not use a terminal at all, see the Claude Desktop setup in the [README](../README.md#client-setup-claude-desktop-for-non-technical-users).

## The one rule: never from the plugin folder

The client's Claude Code **never** runs from `wp-content/plugins/gn-mcp-bridge/`, and the client's machine never holds a copy of this plugin or of the site's codebase.

That folder is the dev side. Starting Claude Code there auto-loads:

- `CLAUDE.md`: plugin maintenance instructions (editing `src/`, versioning, releases).
- `.claude/skills/gnmcp-*` and `/gnmcp-start`: operator tools that write PHP, change capabilities, run WP-CLI and manage Application Passwords.

A client session with that context gets pushed toward changing the plugin instead of the content. It also breaks the security model: the capability filters only hold when the caller has the MCP transport and **nothing else** (see "Security model & limits" in the [README](../README.md#security-model--limits)).

The client gets:

- an Application Password for their own WordPress account,
- a dedicated work folder (`~/Documents/claude-<short-site-name>/`) with its own `CLAUDE.md` about content, not code,
- an MCP server entry scoped to that folder.

## Before you go: server side (operator's machine)

Do these on the production site before you sit down at the client's machine.

1. **Plugins up to date on prod.** `mcp-adapter` at the pinned version, `gn-mcp-bridge` active: check in *Plugins* in the WordPress admin.
2. **Choose the client's WordPress account and role.** Use the client's personal account (not a shared or admin one), with the lowest role that covers their work: usually `author` (own posts) or `editor` (all content). Never `administrator`.
3. **Restrict the abilities for that site** from the theme (`inc/core/mcp-abilities.php`, via `gnmcp-setup-site` on your side). Typical client restrictions:
   - remove `gn-mcp/delete-post` and `gn-mcp/update-term` from `gn_mcp_bridge_exposed_abilities`,
   - raise `gn_mcp_bridge_ability_capability` for any write ability the client shouldn't have.

   The server-side filters are the real control. The client-side permissions set further down only add a confirmation step, which the client can turn off.
4. **Deploy the theme change to prod** and check that the filter file is loaded.

## On the client's machine

### 1. Install Claude Code

macOS / Linux:

```bash
curl -fsSL https://claude.ai/install.sh | bash
```

Windows (PowerShell):

```powershell
irm https://claude.ai/install.ps1 | iex
```

Check with `claude --version`. The native install updates itself, so there's nothing to maintain.

### 2. Log in with the client's own Claude account

```bash
claude
```

The browser opens: the **client** logs in with **their** Claude subscription (Pro, Max, Team or Enterprise), never yours. Quit with `/exit` once logged in.

### 3. Create the Application Password

One password per client **and per machine**, so a lost laptop can be revoked without touching anything else. Always from the WordPress admin:

1. Log in to the WordPress admin with the client's account (*Users → Profile*), or as an administrator (*Users → All Users → edit the client's user*).
2. Scroll down to **Application Passwords**.
3. Name it `Claude Code - <machine name>`, click **Add New Application Password**.
4. Copy the password shown: it is displayed only once.

Application Passwords require HTTPS on production. If the profile section is missing, check the site's SSL and any security plugin that disables Application Passwords.

### 4. Encode the credentials without leaving them in the shell history

In the client's terminal (zsh, the macOS default):

```zsh
read -r "WP_USER?WordPress login: "
read -rs "WP_PASS?Application Password: "; echo
AUTH=$(printf '%s:%s' "$WP_USER" "$WP_PASS" | base64)
```

Paste the Application Password exactly as WordPress shows it (spaces included, they are ignored). Keep the terminal open: `$AUTH` is used in the next two steps.

### 5. Check the handshake with `curl`

```bash
curl -si -X POST "https://<domain>/wp-json/gn-mcp/mcp" \
  -A 'claude-code/2' \
  -H "Authorization: Basic $AUTH" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl-test","version":"1.0"}}}'
```

Expected: `HTTP/2 200`, `serverInfo` in the body, an `Mcp-Session-Id` header.

| Result | Meaning |
|---|---|
| `401` JSON | Wrong login or password: recreate the password |
| `403` plain HTML | A security plugin is blocking the user agent or the IP (common on client hosting); allowlist the client's IP or the endpoint |
| `403` JSON | The account lacks the transport capability (`gn_mcp_bridge_transport_capability`) |
| `500` | Server-side error: check the PHP logs, run `gnmcp-check` on your side |

Never go further until this returns `200`.

### 6. Create the work folder

```bash
mkdir -p ~/Documents/claude-<short-site-name>
cd ~/Documents/claude-<short-site-name>
```

Every Claude Code session for this site starts from here. The folder contains no code, only instructions and, if needed, the client's working files (drafts, notes, images to describe).

### 7. Register the MCP server, scoped to that folder

From inside the work folder:

```bash
claude mcp add --transport http --scope local wp-mcp-<short-site-name> \
  "https://<domain>/wp-json/gn-mcp/mcp" \
  --header "Authorization: Basic $AUTH"
unset WP_USER WP_PASS AUTH
```

Why `--scope local`: the server, and its credential, only exist in sessions started from this folder. A session started anywhere else on the machine (another project, the home folder) has no access to the production site.

Don't use `--scope project`: it writes the credential into a `.mcp.json` file inside the folder, in plain text, where it can get synced, shared or committed.

The credential ends up in `~/.claude.json` (Windows: `%USERPROFILE%\.claude.json`), in plain text, readable by the client's user account only. It is the same trust model as an SSH key on a laptop.

Naming: `wp-mcp-<short-site-name>`, same convention as in the [README](../README.md#naming-convention).

### 8. Add the folder's `CLAUDE.md`

Create `~/Documents/claude-<short-site-name>/CLAUDE.md`. It gives the client's sessions the editorial context. Start from this template, in the client's working language:

```markdown
# <Site name>: content work

This folder is for editing the content of https://<domain> through the
`wp-mcp-<short-site-name>` MCP server. There is no code here and none should
be written: no PHP, no theme or plugin files, no WP-CLI.

## How to work

- Read before writing: find the post with search-posts, read it with get-post
  (or get-post-outline + get-block for long pages) before changing it.
- New posts are created as drafts; publishing is done by a human in the
  WordPress admin.
- For a long page, change one block with update-block rather than rewriting
  the whole content with update-post.
- Keep the existing block structure; change the text, not the layout.
- Before any update, show what will change and wait for confirmation.

## Editorial rules

- Language: <language>. Tone: <tone>.
- Categories in use: <list>, never create new ones without asking.
- <Any site-specific rules: SEO title length, excerpt, links, images...>

## If something fails

Don't try to fix the server, the plugin or the site configuration. Note the
error message and contact <operator name / email>.
```

### 9. Add client-side permissions (optional, recommended)

`~/Documents/claude-<short-site-name>/.claude/settings.json`:

```json
{
  "permissions": {
    "allow": [
      "mcp__wp-mcp-<short-site-name>__gn-mcp-search-posts",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-get-post",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-get-post-outline",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-get-block",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-get-post-meta",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-list-categories"
    ],
    "ask": [
      "mcp__wp-mcp-<short-site-name>__gn-mcp-create-post",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-update-post",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-update-block",
      "mcp__wp-mcp-<short-site-name>__gn-mcp-update-post-meta"
    ],
    "deny": [
      "mcp__wp-mcp-<short-site-name>__gn-mcp-delete-post"
    ]
  }
}
```

Reads run without a prompt; every write asks for confirmation first. Adjust the list to the abilities actually exposed on that site (step 3 of the server side). This is a convenience layer: the server-side filters stay the actual limit.

### 10. Verify from the client's side

```bash
cd ~/Documents/claude-<short-site-name>
claude mcp list
```

`wp-mcp-<short-site-name>` must show `✓ Connected`. Then start `claude`, run `/mcp` to see the tool list, and ask a harmless read-only question, e.g. "List the categories on the site" or "Find the last three posts about <topic>".

Finally, from another folder (e.g. `cd ~ && claude mcp list`), check that the server does **not** appear. That confirms the local scope.

## Client on their own (ready-made kit)

For a client who installs everything alone. The operator builds a ready-made folder (the kit) with the bundled `gnmcp-client-kit` skill and sends it. The client installs Claude Code, puts the folder in their home folder and runs `claude` inside it: no `claude mcp add`, no base64, no config file to edit.

The kit:

```text
claude-<short-site-name>/
├── CLAUDE.md               ← content-only instructions, in the client's language
├── START-HERE.md           ← (LISEZ-MOI.md in French) client start-up steps
├── .mcp.json               ← the gn-mcp HTTP server, with the credential
└── .claude/settings.json   ← reads allowed, writes ask, delete denied
```

Trade-offs compared to the operator-at-the-machine path:

- The credential sits in plain text in `.mcp.json` **inside the folder** (instead of `~/.claude.json`), so the folder itself is sensitive: home folder only, never a synced one.
- The operator handles the raw Application Password to build the kit, so it travels to the client over an encrypted channel.
- Claude Code asks the client to approve the `.mcp.json` server once, at first launch.

### Operator side: build the kit

1. **Server side**: same as [Before you go](#before-you-go-server-side-operators-machine) (account, role, filters, deploy).
2. **Run `/gnmcp-client-kit`** from your dev copy of the plugin. It asks for the short site name, the production domain, the client's language and your contact details, then writes the kit into the plugin's `.local-working/client-kits/` (gitignored, never released). It never asks for the password.
3. **Create the Application Password** for the client's account, named `Claude Code - <client's machine name>` from the WordPress admin, as in [step 3](#3-create-the-application-password).
4. **In a separate terminal**, run the script the skill prints (`.claude/skills/gnmcp-client-kit/scripts/fill-credential.sh <kit folder>`). It asks for the login and the password (hidden), checks the handshake and writes nothing unless it gets HTTP 200. It then prints the tools this account sees, injects the credential into `.mcp.json` and zips the kit.
5. **Compare the printed tool list** with the kit's `.claude/settings.json`.
6. **Send the zip over an encrypted, expiring share** (e.g. a 1Password item share), never plain email, Slack or a public transfer link. Paste the content of `START-HERE.md` into the message so the client can read it before unzipping. Then delete the local kit and zip.

### Client side

The kit's `START-HERE.md` / `LISEZ-MOI.md` walks the client through it:

1. Open the Terminal (Mac) or PowerShell (Windows), paste the Claude Code install line.
2. Unzip the kit and move the folder into their **home folder** (not Documents, Desktop, Dropbox, iCloud Drive or OneDrive).
3. `cd ~/claude-<short-site-name>` then `claude`.
4. First launch only: sign in with **their own** Claude account, trust the folder, approve the `wp-mcp-<short-site-name>` server.
5. Test: "List the categories on my site".

Every later session: `cd ~/claude-<short-site-name>` then `claude`, always from that folder.

The Code tab of the Claude desktop app also reads the kit (choose the folder as the project folder). Test it once yourself before relying on it: its approval prompt and permission behavior can differ slightly from the CLI.

### When it doesn't work (client side)

The client sends the operator a screenshot. Usual causes:

- **No answer from the site, server pending or disabled**: the approval was skipped at first launch. Type `/mcp` in Claude Code and enable `wp-mcp-<short-site-name>`.
- **Tools missing**: Claude Code was started from another folder (`cd ~/claude-<short-site-name>` first).
- **The hidden files were lost** during the move (some unzip or sync tools drop them): resend the zip and have the client move the whole folder in one go.
- **An authentication error**: the Application Password was revoked. Run the skill again and send a new kit.

## Handover to the client

Things to leave the client, written down:

- **How to start**: open the Terminal, `cd ~/Documents/claude-<short-site-name>`, `claude`. Always from that folder.
- **What to ask**: content work in plain language ("rewrite the intro of the About page", "draft a post about X in the News category"). Claude reads first, proposes, and asks before writing.
- **What not to do**: no plugin or theme installation, no copying site files to the machine, no starting Claude Code from any other folder for this site.
- **Who to call** when a tool fails, instead of letting Claude try to "fix" things.

Run a first real task together before leaving.

## Maintenance and offboarding

- **Password rotation or a lost/replaced machine**: revoke the old password (WordPress admin, the client's user → *Application Passwords* → **Revoke** next to that machine's name), create a new one, then on the client's machine, from the work folder: `claude mcp remove wp-mcp-<short-site-name> -s local` and repeat steps 4, 5 and 7. With the kit path, build and send a new kit; the client replaces the whole folder.
- **Changing what the client can do**: change the theme's filters on prod. Nothing to touch on the client's machine, except the `settings.json` lists if an ability was added or removed.
- **New plugin abilities**: they show up automatically after a restart of Claude Code, unless filtered out. Review the theme filters before deploying a plugin update that adds a write ability.
- **End of the engagement**: revoke the Application Password in the WordPress admin first (that alone cuts access), then remove the MCP entry and the work folder from the client's machine if you still have access to it.

## Known gotchas

- **A server added mid-session doesn't show up**: quit and restart `claude` (or run `/mcp`).
- **Tools missing in a session**: Claude Code was started from another folder. Local scope is tied to the exact folder path.
- **`401` after it used to work**: the Application Password was revoked, or the WordPress account was deleted/recreated. Check the client's user → *Application Passwords* in the WordPress admin: the list shows each password's *Last used* date.
- **Client hosting with a WAF** (Cloudflare, Wordfence, host firewall): it can start blocking the endpoint after a burst of requests. Allowlist `/wp-json/gn-mcp/mcp` or the client's IP rather than loosening the rule globally.
