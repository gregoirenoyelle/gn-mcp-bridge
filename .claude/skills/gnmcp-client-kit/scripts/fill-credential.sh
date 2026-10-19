#!/usr/bin/env bash
#
# fill-credential.sh — injects a client's Application Password into a
# gnmcp-client-kit folder, checks the MCP handshake, then zips the kit.
#
# Usage (in a real terminal, never through Claude Code: the password is read
# from the keyboard and must not land in a chat transcript):
#
#   bash fill-credential.sh <path/to/claude-<short-site-name>>
#
# The kit's .mcp.json must still hold the __BASIC_AUTH__ placeholder.
# Nothing is written unless the endpoint answers the initialize call with 200.

set -euo pipefail

KIT_DIR="${1:-}"
PLACEHOLDER="__BASIC_AUTH__"

if [[ -z "$KIT_DIR" || ! -d "$KIT_DIR" ]]; then
	echo "Usage: bash $0 <kit folder>" >&2
	exit 1
fi

KIT_DIR="$(cd "$KIT_DIR" && pwd)"
MCP_JSON="$KIT_DIR/.mcp.json"

if [[ ! -f "$MCP_JSON" ]]; then
	echo "Error: $MCP_JSON not found." >&2
	exit 1
fi

if ! grep -q "$PLACEHOLDER" "$MCP_JSON"; then
	echo "Error: no $PLACEHOLDER placeholder in $MCP_JSON." >&2
	echo "This kit already holds a credential. Regenerate it with gnmcp-client-kit to rotate." >&2
	exit 1
fi

URL="$(grep -o '"url": *"[^"]*"' "$MCP_JSON" | head -1 | sed 's/.*"\(https[^"]*\)"/\1/')"
if [[ "$URL" != https://* ]]; then
	echo "Error: no https URL found in $MCP_JSON (got: '$URL')." >&2
	exit 1
fi

echo "Endpoint: $URL"
read -r -p "Client's WordPress login: " WP_USER
read -r -s -p "Application Password (hidden): " WP_PASS
echo

AUTH="$(printf '%s:%s' "$WP_USER" "$WP_PASS" | base64 | tr -d '\n')"
unset WP_PASS

HEADERS="$(mktemp)"
BODY="$(mktemp)"
trap 'rm -f "$HEADERS" "$BODY"' EXIT

HTTP_CODE="$(curl -s -o "$BODY" -D "$HEADERS" -w '%{http_code}' -X POST "$URL" \
	-A 'claude-code/2' \
	-H "Authorization: Basic $AUTH" \
	-H "Content-Type: application/json" \
	-H "Accept: application/json, text/event-stream" \
	-d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"gnmcp-client-kit","version":"1.0"}}}' || true)"

if [[ "$HTTP_CODE" != "200" ]]; then
	echo "Handshake failed: HTTP $HTTP_CODE. Nothing was written." >&2
	head -c 500 "$BODY" >&2 || true
	echo >&2
	echo "401 = wrong login/password, 403 HTML = security plugin/WAF, 403 JSON = transport capability, 500 = server error." >&2
	exit 1
fi
echo "Handshake OK (HTTP 200)."

# Best effort: list the tools this account actually sees, to check the kit's
# .claude/settings.json lists against them.
SESSION_ID="$(grep -i '^mcp-session-id:' "$HEADERS" | head -1 | cut -d' ' -f2 | tr -d '\r')"
if [[ -n "$SESSION_ID" ]]; then
	curl -s -o /dev/null -X POST "$URL" -A 'claude-code/2' \
		-H "Authorization: Basic $AUTH" \
		-H "Content-Type: application/json" \
		-H "Accept: application/json, text/event-stream" \
		-H "Mcp-Session-Id: $SESSION_ID" \
		-H "MCP-Protocol-Version: 2025-11-25" \
		-d '{"jsonrpc":"2.0","method":"notifications/initialized"}' || true
	TOOLS="$(curl -s -X POST "$URL" -A 'claude-code/2' \
		-H "Authorization: Basic $AUTH" \
		-H "Content-Type: application/json" \
		-H "Accept: application/json, text/event-stream" \
		-H "Mcp-Session-Id: $SESSION_ID" \
		-H "MCP-Protocol-Version: 2025-11-25" \
		-d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' || true)"
	echo "Tools visible to this account:"
	printf '%s' "$TOOLS" | grep -o '"name": *"[^"]*"' | sed 's/.*"\([^"]*\)"$/  - \1/' | sort -u || echo "  (could not list them)"
fi

# Base64 only uses A-Z a-z 0-9 + / =, so "|" is a safe sed delimiter.
TMP_JSON="$(mktemp)"
sed "s|$PLACEHOLDER|$AUTH|" "$MCP_JSON" > "$TMP_JSON"
mv "$TMP_JSON" "$MCP_JSON"
chmod 600 "$MCP_JSON"
unset AUTH

PARENT="$(dirname "$KIT_DIR")"
NAME="$(basename "$KIT_DIR")"
ZIP="$PARENT/$NAME.zip"
rm -f "$ZIP"
(cd "$PARENT" && zip -rq "$NAME.zip" "$NAME" -x '*.DS_Store')

echo
echo "Kit ready: $ZIP"
echo "Next:"
echo "  1. Compare the tool list above with $NAME/.claude/settings.json."
echo "  2. Send the zip through an encrypted, expiring share (password manager), never plain email or chat."
echo "  3. Once sent, delete the local kit: rm -rf \"$KIT_DIR\" \"$ZIP\""
