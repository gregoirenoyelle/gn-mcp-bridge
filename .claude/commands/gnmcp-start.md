---
description: Read-only health check for gn-mcp-bridge — mcp-adapter version, claude mcp list, curl initialize handshake
argument-hint: (none)
---

Bundled with the `gn-mcp-bridge` plugin — travels with it when the plugin is
copied to another site, no dependency outside this plugin's own folder.
References no file outside `gn-mcp-bridge/`.

Read-only session-opening health check. **It fixes nothing itself** — on any
failure, report it and hand off to the `gnmcp-check` skill for the actual
diagnosis/fix work.

## Steps

1. **Confirm the plugins are active and check the adapter version.**

   ```bash
   wp plugin list | grep -E "mcp-adapter|gn-mcp-bridge"
   wp eval 'echo defined("WP_MCP_VERSION") ? WP_MCP_VERSION : "MISSING";'
   wp eval 'echo GN\McpBridge\GN_MCP_BRIDGE_MIN_ADAPTER_VERSION;' 2>/dev/null \
     || grep -n "GN_MCP_BRIDGE_MIN_ADAPTER_VERSION" gn-mcp-bridge.php
   ```

   Compare the two versions (`version_compare`). Both plugins must show
   `active`; the adapter version must be `>=` the pinned floor.

2. **Check the Claude Code connection state.**

   ```bash
   claude mcp list
   ```

   Every `wp-mcp-*` server entry relevant to this site should read
   `✔ Connected`. Note any that don't — a server added mid-session won't
   show as connected until the session restarts (or `/mcp` reloads); that's
   a session-lifecycle quirk, not a finding to hand off.

3. **curl the `initialize` handshake.**

   Get the site URL and a working Application Password first (don't invent
   one — ask if none is at hand, or reuse the one already configured for the
   Claude Code MCP server being checked):

   ```bash
   wp option get siteurl
   ```

   ```bash
   USER=<login>
   PASS="<application-password>"
   AUTH=$(printf "%s:%s" "$USER" "$PASS" | base64)
   curl -si -X POST "<siteurl>/wp-json/gn-mcp/mcp" \
     -H "Authorization: Basic $AUTH" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json, text/event-stream" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"gnmcp-start","version":"1.0"}}}'
   ```

   Expect `HTTP/2 200` (or `HTTP/1.1 200`) with a JSON body carrying
   `serverInfo`, plus an `Mcp-Session-Id` response header. A `401` here means
   bad credentials; anything else (`500`, connection refused, missing route)
   is a real failure.

   Then confirm the follow-up call works — on `2025-11-25` it needs both the
   session header and `MCP-Protocol-Version`:

   ```bash
   curl -s -X POST "<siteurl>/wp-json/gn-mcp/mcp" \
     -H "Authorization: Basic $AUTH" -H "Content-Type: application/json" \
     -H "Accept: application/json, text/event-stream" \
     -H "Mcp-Session-Id: <value from the initialize response>" \
     -H "MCP-Protocol-Version: 2025-11-25" \
     -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
   ```

   Expect the `gn-mcp-*` tools back.

4. **Report a pass/fail summary.** One line per check:

   - mcp-adapter: active, version X (floor: Y) — pass/fail
   - gn-mcp-bridge: active — pass/fail
   - `claude mcp list`: connected servers — pass/fail (list any not connected)
   - `initialize` handshake + `tools/list`: HTTP status, tool count — pass/fail

   **On any failure**, say so plainly and hand off to the `gnmcp-check` skill
   — do not attempt a fix from here.
