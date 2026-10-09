---
name: gnmcp-check
description: Diagnose a failing gn-mcp-bridge MCP connection — curl initialize handshake, Mcp-Session-Id / MCP-Protocol-Version gotcha, claude mcp list, unauthenticated-request checks, SSL on .test domains. Read-only diagnosis; writes nothing in the plugin or theme except when explicitly updating mcp-adapter itself from its wordpress.org zip into wp-content/plugins/mcp-adapter. Trigger keywords: MCP connection failing, gn-mcp-bridge diagnose, Mcp-Session-Id, MCP 401, MCP 500, update mcp-adapter, install mcp-adapter.
user-invocable: true
---

# gnmcp-check — Diagnose a failing MCP connection

Bundled with the `gn-mcp-bridge` plugin — travels with it when the plugin is copied to another site, no dependency outside this plugin's own folder.

Work through these in order — each isolates one layer.

## 1. Confirm the plugin and route exist

```bash
wp plugin list --path=<site> | grep -E "mcp-adapter|gn-mcp-bridge"
wp eval '$routes = rest_get_server()->get_routes(); foreach ($routes as $r => $v) { if (strpos($r, "gn-mcp") !== false) echo $r . "\n"; }' --path=<site>
```

Expect `mcp-adapter` and `gn-mcp-bridge` both `active`, and `/gn-mcp` + `/gn-mcp/mcp` in the route list. If the route is missing, check for a PHP fatal on `wp_abilities_api_init` or `mcp_adapter_init` — `wp eval 'echo "ok";' --path=<site>` will surface it.

If `mcp-adapter` itself is inactive or missing, `gn-mcp-bridge.php`'s own `check_mcp_adapter_dependency()` check should already be showing an admin notice — confirm it's visible in `/wp-admin/plugins.php`.

**Gotcha: `mcp-adapter` deactivated via WP-CLI or a hosting panel, after `gn-mcp-bridge` was already active.** The Plugins-page UI blocks this (`Requires Plugins`), but a WP-CLI `wp plugin deactivate mcp-adapter` doesn't — `gn-mcp-bridge` stays "active" while `mcp_adapter_init` silently never fires again, so `Server.php` never re-registers, and the route above goes missing with no error, just a plain `rest_no_route` 404 to any client. The `wp plugin list` check above catches it: `mcp-adapter` will show `inactive` while `gn-mcp-bridge` still shows `active`.

## 2. Application Password sanity check

```bash
wp user application-password list <login> --path=<site>
```

WordPress strips spaces from the displayed password automatically when authenticating — don't worry about reproducing them exactly in the `curl` header below.

## 2b. Site-level bot-blocking (User-Agent gotcha)

Some sites 403 any request lacking a browser-like `User-Agent` header — confirmed on at least one site, where bare `curl` got a `403` even on the homepage, and adding a UA fixed it. This is unrelated to `gn-mcp-bridge`/`mcp-adapter` config. If steps 3-4 below 403 instead of 401/200, check this first:

```bash
curl -si "https://<site>.test/" | head -1              # bare — may 403
curl -si -A "Mozilla/5.0" "https://<site>.test/" | head -1   # with UA — should 200
```

If the bare request 403s and the UA'd one doesn't, the MCP client itself must send a UA — check its HTTP client config before touching the plugin.

## 3. curl the handshake directly — before touching any client config

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

Expect `HTTP/2 200` and a JSON body with `serverInfo`. A `401` here means the Application Password or username is wrong — fix this before looking anywhere else.

**Gotcha (mcp-adapter ≥ 0.7, protocol revision `2025-11-25` — the one Claude Code negotiates):** the response also carries an `Mcp-Session-Id` header. Every subsequent call (`tools/list`, `tools/call`) on the same logical connection must repeat that exact header value **and** send `MCP-Protocol-Version: 2025-11-25`, or the server replies `400` with `-32600` (`Missing Mcp-Session-Id header` or `MCP-Protocol-Version header is required for a 2025-11-25 session`). The newer `2026-07-28` revision is sessionless (no `initialize`; `params._meta` metadata on each request) — see `CLAUDE.md`. This is not mentioned in mcp-adapter's own README — capture the header from `initialize` and reuse it:

```bash
SESSION="<value from the Mcp-Session-Id response header above>"
curl -s -X POST "https://<site>.test/wp-json/gn-mcp/mcp" \
  -H "Authorization: Basic $AUTH" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" -H "Mcp-Session-Id: $SESSION" \
  -H "MCP-Protocol-Version: 2025-11-25" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

Expect the exposed abilities back as tools, prefixed `gn-mcp-*` (hyphen, not slash — MCP tool names can't contain `/`).

## 4. Unauthenticated calls must 401, not 500

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST "https://<site>.test/wp-json/gn-mcp/mcp" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/list","params":{}}'
```

A `500` here means `mcp_adapter_default_transport_permission_user_capability` (or the `gn_mcp_bridge_transport_capability` filter behind it) is throwing rather than returning cleanly.

## 5. Claude Code side

```bash
claude mcp list
```

Expect `<server-name>: ✔ Connected`. If a server was added mid-session, its tools do not appear until the session restarts (or `/mcp` reloads) — this is a Claude Code session-lifecycle limitation, not a server-side problem; don't debug the WordPress side for this symptom.

## 6. Claude Desktop side (mcp-remote setups)

If the client connects via the `mcp-remote` workaround (see `README.md`, "Client setup: Claude Desktop"), check:

- `node -v` / `npx` actually resolves on that machine
- the `--header` value in `claude_desktop_config.json` has no stray line breaks or unescaped quotes
- **Windows only**: a known bug mangles `--header` values containing spaces in some `mcp-remote`/Desktop versions — if steps 1-4 above pass with `curl` but Desktop still fails, suspect this before the Application Password

## 7. SSL on `.test` domains

Valet's local CA is usually trusted system-wide already (`curl` above works without `-k`). If a *Node-based* MCP client (not `curl`, not Claude Code's own HTTP transport) rejects the cert, check `NODE_EXTRA_CA_CERTS` — may need setting on a machine where it isn't already configured.

## Updating mcp-adapter (wordpress.org zip, pinned)

`mcp-adapter` is not a Composer dependency — the version is pinned deliberately (cf. this plugin's `CLAUDE.md`). Install a specific release from its wordpress.org zip, which ships `vendor/` (a raw GitHub tag does not) — never an unpinned "latest", and keep auto-updates off.

```bash
cd wp-content/plugins
mv mcp-adapter ../../mcp-adapter.bak   # rollback copy, outside the plugins dir — delete once the update is confirmed working
curl -sSfO https://downloads.wordpress.org/plugin/mcp-adapter.<version>.zip
unzip -q mcp-adapter.<version>.zip && rm mcp-adapter.<version>.zip
wp plugin get mcp-adapter --field=version
wp plugin auto-updates disable mcp-adapter
```

**After every update — re-run steps 1-4 above** (route exists, Application Password, `initialize` handshake + `Mcp-Session-Id` / `MCP-Protocol-Version` behavior, unauthenticated calls still 401), then check `wp-content/debug.log` for schema-rejection warnings mentioning `gn-mcp/*`. If the transport gotcha's exact behavior changes, update the comment in `Server.php` and `CLAUDE.md`. Bump `GN_MCP_BRIDGE_MIN_ADAPTER_VERSION` in `gn-mcp-bridge.php` only if the new version should become the enforced floor.

## Related

- Per-site exposure/capability filters and Application Password setup: `gnmcp-setup-site`
- Adding a new ability: `gnmcp-add-ability`
