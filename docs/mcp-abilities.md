# Exposed MCP abilities

🇫🇷 [Version française](fr/mcp-abilities.md)

Full reference for every ability this plugin registers and exposes on the `gn-mcp` MCP server (`/wp-json/gn-mcp/mcp`). Generated from the current state of `src/Abilities/*.php` and `src/Config.php` — keep it in sync whenever an ability is added, removed, or changed (see `gnmcp-add-ability`'s step 8).

Only tools are exposed — no MCP resources or prompts are declared (`Server.php` passes only `Config::exposed_abilities()` to `create_server()`).

## `gn-mcp/search-posts`

Find existing posts by keyword, category, or tag.

- **Default capability**: `read`
- **Source**: `src/Abilities/SearchPostsAbility.php`

**Input**

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `keyword` | string | no | — | Keyword to search for in post title and content |
| `category` | string | no | — | Category slug to filter posts |
| `tag` | string | no | — | Tag slug to filter posts |
| `limit` | integer | no | `5` | Maximum number of posts to return |
| `post_type` | string | no | `post` | Post type to search. Must be one of the post types allowed on this site |

**Output**: array of objects — `id`, `title`, `excerpt`, `url`, `date`, `categories` (string[]), `tags` (string[])

**Notes**: only `post_status = publish` is searched. Category/tag filters combine with `relation: AND` when both are given. `post_type` other than the site's allowlist (`gn_mcp_bridge_allowed_post_types`, default `post`, `page`, and `wp_block`) fails with `gn_mcp_post_type_not_allowed` (HTTP 400).

## `gn-mcp/get-post`

Retrieve full content of a single post by ID or slug.

- **Default capability**: `edit_others_posts`
- **Source**: `src/Abilities/GetPostAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | no* | Post ID |
| `slug` | string | no* | Post slug |
| `post_type` | string | no | Post type for `slug` lookup only (default `post`). Ignored when looking up by `id` |

\* At least one of `id`/`slug` should be given; `id` takes priority if both are present. Neither is schema-required, so an empty call resolves to "not found" rather than a validation error.

**Output**: object — `id`, `title`, `content`, `excerpt`, `url`, `date`, `categories` (string[]), `tags` (string[]), `status`, `comment_status`, `ping_status`

**Notes**: `slug` lookup includes `publish`, `draft`, and `private` statuses (not just published posts) — this ability can read drafts if the caller's capability allows it. There's no per-post ownership check (unlike `update-post`), so the default capability is deliberately raised to `edit_others_posts` (Editor tier): at a lower default, any authenticated caller could read any author's draft or private post by ID or slug, not just their own. Whatever post is resolved (by `id` or `slug`), its actual post type must be in the site's allowlist (`gn_mcp_bridge_allowed_post_types`, default `post`, `page`, and `wp_block`) or the call resolves to "not found" — this also stops an `id` lookup from reaching a post type outside the allowlist.

## `gn-mcp/get-post-outline`

Summarize a post's block tree (path, block name, metadata name, occurrence, size) without its content, plus a `content_hash` to pass to `update-block`. Use before `get-block`/`update-block` on a long post instead of `get-post`.

- **Default capability**: `edit_others_posts`
- **Source**: `src/Abilities/GetPostOutlineAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Post ID |
| `max_depth` | integer | no | List every block down to this depth (`0` = top level only). Omitted: top-level blocks, every block with a metadata name (the editor's "Rename" field), and their direct children |

**Output**: object — `id`, `title`, `content_hash`, `bytes`, `block_count`, `listed_count`, `blocks` (array of `path`, `blockName`, `name`, `occurrence`, `depth`, `bytes`, `children` — `name`/`occurrence` only present on named blocks)

**Notes**: uses `BlockLocator::scan()` — a regex-based scan of the raw `post_content` reusing core's own block-delimiter pattern (`WP_Block_Parser::next_token()`), never `parse_blocks()`/`serialize_blocks()`. `path` counts real blocks only (freeform gaps between blocks aren't indexed — this differs from `parse_blocks()`'s own top-level indexes, which do count freeform). Same `load_post()` gate as `get-block`/`update-block`: post type must be in `gn_mcp_bridge_allowed_post_types`, and the caller needs `current_user_can( 'edit_post', $id )` — reading a block's markup exposes the same data as editing the post, so this blanket `edit_others_posts` default (same tier as `get-post`) plus the per-post check both apply, unlike `get-post-meta`/`update-post-meta` which only need the per-post check. Default listing depth keeps output well under the MCP output limit even on long posts (a 48 KB / 194-block real-world page listed 114 blocks in ~10.6 KB of JSON); pass `max_depth` to widen or narrow it.

## `gn-mcp/get-block`

Retrieve the raw markup of one block of a post (inner blocks included), located by `path` or by metadata `name` + `occurrence` as listed by `get-post-outline`.

- **Default capability**: `edit_others_posts`
- **Source**: `src/Abilities/GetBlockAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Post ID |
| `path` | string | no* | Block path from `get-post-outline`, e.g. `"4/1/0"` (child indexes, freeform gaps not counted) |
| `name` | string | no* | Block metadata name. Refused as ambiguous when several blocks share it and no `occurrence` is given |
| `occurrence` | integer | no | 0-based index among blocks sharing the same `name`, in document order. Only with `name` |

\* Exactly one of `path`/`name` is required — passing both, or neither, is refused (`gn_mcp_block_target_invalid`, HTTP 400).

**Output**: object — `id`, `content_hash`, `block` (summary: `path`, `blockName`, `name`, `occurrence`, `depth`, `bytes`, `children`), `markup` (raw block markup, delimiters included)

**Notes**: `content_hash` matches `get-post-outline`'s — pass it as `expected_hash` on a following `update-block` call so the write is refused if the post changed in between. An ambiguous `name` without `occurrence` fails with `gn_mcp_block_ambiguous` (HTTP 409) and lists every candidate's `occurrence`/`path`/`blockName` — never "first match". A `path`/`name` that doesn't resolve fails with `gn_mcp_block_not_found` (HTTP 404). Same `load_post()` gate as `get-post-outline` (post type allowlist + per-post `edit_post`).

## `gn-mcp/create-post`

Create a new post.

- **Default capability**: `edit_posts`
- **Source**: `src/Abilities/CreatePostAbility.php`

**Input**

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | yes | — | Post title |
| `content` | string | yes | — | Post body as Gutenberg block markup (`<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->`); plain HTML opens as a Classic block |
| `excerpt` | string | no | — | Post excerpt |
| `categories` | string[] | no | — | Category names or slugs (created if they don't exist) |
| `tags` | string[] | no | — | Tag names |
| `status` | string | no | `draft` | Post status: `draft`, `publish`, `pending`, `private` — falls back to `draft` if any other value is given |
| `post_type` | string | no | `post` | Post type to create. Must be one of the post types allowed on this site |
| `comment_status` | string | no | site's `default_comment_status` | `open` or `closed`. Any other value is ignored (left to the site default) |
| `ping_status` | string | no | site's `default_ping_status` | `open` or `closed`. Any other value is ignored (left to the site default) |

**Output**: object — `id`, `url`, `edit_url`, `status`, `comment_status`, `ping_status`

**Notes**: `content` follows WordPress core's own rule: it is stored as-is for a caller with the `unfiltered_html` capability (Administrator/Editor on single-site, Super Admin on multisite, never when `DISALLOW_UNFILTERED_HTML` is set), and otherwise sanitized with core's block-aware `filter_block_content()` (kses on each block attribute value, re-serialized with HTML escaped as unicode escapes) followed by `wp_kses_post()` (the rest of the markup). Plain `wp_kses_post()` alone broke Gutenberg block comments whose JSON attributes contain raw HTML (e.g. a synced pattern's pattern-override content with a link): core's `wp_pre_kses_less_than()` runs before its own block-attribute handling, sees the raw `<`, and HTML-encodes the whole `<!-- wp:... -->` comment, which then renders as plain text. Content is also slashed before `wp_insert_post()`, which unslashes its input — otherwise backslash escapes in block JSON (`\u003c`, `\"`) would be stripped. Categories given by name or slug are matched to existing terms first, created only if no match is found. `post_type` other than the site's allowlist (`gn_mcp_bridge_allowed_post_types`, default `post`, `page`, and `wp_block`) fails with `gn_mcp_post_type_not_allowed` (HTTP 400). A request that asks for `status: publish` directly also requires that post type's own publish capability, on top of the ability's own default capability — resolved from the target post type's registration (`publish_posts` for `post`, `publish_pages` for `page`, `publish_blocks` for `wp_block`), not hardcoded to `publish_posts`, since a caller can hold the generic capability without holding a given post type's own one. Without this, a Contributor-tier default (`edit_posts`) would let a Contributor publish straight away, bypassing WordPress's own contributor-can't-publish rule. That extra check is refused with the ability's standard permission error (HTTP 403), same as failing the default capability.

## `gn-mcp/update-post`

Update an existing post (title, content, excerpt, status, categories, tags).

- **Default capability**: `edit_posts`
- **Source**: `src/Abilities/UpdatePostAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the post to update |
| `title` | string | no | Post title |
| `content` | string | no | Post body as Gutenberg block markup (`<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->`); plain HTML opens as a Classic block |
| `excerpt` | string | no | Post excerpt |
| `categories` | string[] | no | Category names or slugs (created if they don't exist). Replaces the post's current categories |
| `tags` | string[] | no | Tag names. Replaces the post's current tags |
| `status` | string | no | Post status: `draft`, `publish`, `pending`, `private` — any other value is ignored (status left unchanged) |
| `comment_status` | string | no | `open` or `closed`. Any other value is ignored (status left unchanged) |
| `ping_status` | string | no | `open` or `closed`. Any other value is ignored (status left unchanged) |

**Output**: object — `id`, `url`, `edit_url`, `status`, `comment_status`, `ping_status`

**Notes**: `content` gets the same `unfiltered_html`-aware sanitization and slashing as `create-post`. Only the fields actually present in the input are changed — omitting a field leaves it untouched (e.g. omitting `status` doesn't reset it to draft). `categories`/`tags`, when given, fully replace the existing set rather than merging with it. Returns a `gn_mcp_post_not_found` `WP_Error` (HTTP 404) if `id` doesn't match an existing post, or if it exists but its post type isn't in the site's allowlist (`gn_mcp_bridge_allowed_post_types`). Unlike the other abilities, `check_permission()`'s generic `edit_posts` gate is not sufficient on its own — `execute()` also runs a per-post `current_user_can( 'edit_post', $post_id )` check (and `publish_post` when the requested `status` is `publish`/`private`), returning `gn_mcp_forbidden` (HTTP 403) otherwise. Without it, a caller with a bare `edit_posts` capability (e.g. Contributor) could edit or publish any post, not just their own. `check_permission()` itself is also post-type-aware, and generically so (not just for `page`): WordPress's default roles don't necessarily bundle the generic `edit_posts`/`publish_posts` with a given post type's own mapped capabilities, so when the target `id` resolves to any post type other than the built-in `post`, the real capability names are resolved from that post type's registration (`get_post_type_object()->cap`) — `edit_pages`/`publish_pages` for a `page`, `edit_blocks`/`publish_blocks` for a `wp_block` — before the call is even allowed to reach `execute()`.

## `gn-mcp/update-block`

Replace, insert before/after, or delete one block of a post, located by `path` or metadata `name` + `occurrence` (see `get-post-outline`). Only the target byte range of the raw content changes; a normal revision is created.

- **Default capability**: `edit_posts`
- **Source**: `src/Abilities/UpdateBlockAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Post ID |
| `operation` | string | yes | `replace`, `insert_before`, `insert_after`, `delete` |
| `path` / `name` / `occurrence` | — | no* | Same targeting as `get-block` |
| `markup` | string | yes except for `delete` | Exactly one block as Gutenberg block markup (inner blocks allowed), e.g. `<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->`. Send raw `<`/`>`/`"` in block comment JSON, never `\uXXXX` escapes |
| `expected_hash` | string | no | `content_hash` from `get-post-outline`/`get-block`. Refused (`gn_mcp_stale_content`, HTTP 409) if the post content no longer matches. Strongly recommended — required in practice when targeting by `path`, since a prior insert/delete shifts every path after it |

\* Exactly one of `path`/`name` required, same rule as `get-block`.

**Output**: object — `id`, `operation`, `block` (summary of the new/target block, `null` for `delete`), `markup` (`null` for `delete`), `valid` (the saved result reparses as one real, non-freeform block), `saved_as_sent` (`true` if the saved content byte-matches what was spliced — `false` flags a core save-time change, e.g. kses altering something), `new_hash`, `bytes_before`, `bytes_after`

**Notes**: splices the raw `post_content` by byte offset (`substr_replace()`) — never re-serializes the block tree with `serialize_blocks()` — so bytes outside the target range are untouched by this ability. `insert_after`'s returned `block.path` is the target's path with its last segment incremented (the new sibling's actual position); `delete` also removes one adjacent whitespace separator (trailing, else leading) so a following insert/delete round-trips byte-identically. `markup` is validated as exactly one closed block (both the locator's own scan and core's `parse_blocks()` must agree: one top-level, non-`core/freeform` block, nothing else around it) before and after sanitization — classic/freeform HTML, several sibling blocks, or text around the block all fail with `gn_mcp_invalid_fragment` (HTTP 422). Sanitization is the same `unfiltered_html`-aware rule as `update-post`/`create-post` (`filter_block_content()` + `wp_kses_post()`) but applied **to the fragment only**, never the whole post. Core's own `content_save_pre` kses filters still run on the *whole* content at save time for callers without `unfiltered_html` — a fragment-only sanitization can't prevent that, so the write is first simulated through `wp_filter_post_kses()` and refused with `gn_mcp_kses_would_alter` (HTTP 422) if it would change anything, rather than silently corrupting an unrelated block (typically one whose JSON attributes hold raw HTML, e.g. a synced-pattern override saved earlier by an `unfiltered_html` user). Targeting a block with no closing comment (`closed: false` in `get-post-outline`/`get-block`, i.e. core's parser ran it to the end of the document) is refused with `gn_mcp_block_unclosed` (HTTP 422) — fix the markup in the editor first. `check_permission()` is post-type-aware like `update-post`'s (`edit_pages`/`edit_blocks`/... resolved from the target post type's registration when it isn't the built-in `post`), plus the same per-post `current_user_can( 'edit_post', $post_id )` check in `execute()` as `update-post`.

## `gn-mcp/delete-post`

Move an existing post to the trash.

- **Default capability**: `delete_posts`
- **Source**: `src/Abilities/DeletePostAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the post to trash |

**Output**: object — `id`, `status`

**Notes**: trashes via `wp_trash_post()` (reversible) rather than a permanent delete — no permanent-delete option is exposed. Returns a `gn_mcp_post_not_found` `WP_Error` (HTTP 404) if `id` doesn't match an existing post, or if it exists but its post type isn't in the site's allowlist (`gn_mcp_bridge_allowed_post_types`). Same pattern as `update-post`: the ability-level `delete_posts` gate is not sufficient on its own — `execute()` also runs a per-post `current_user_can( 'delete_post', $post_id )` check, returning `gn_mcp_forbidden` (HTTP 403) otherwise, so ownership and post-type-specific capabilities (e.g. `delete_page`, `delete_others_posts`) are enforced through WordPress's own `map_meta_cap()` rather than the generic capability alone.

## `gn-mcp/get-post-meta`

Read custom fields (post meta) of an existing post.

- **Default capability**: `edit_posts`
- **Source**: `src/Abilities/GetPostMetaAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the post to read meta from |
| `keys` | string[] | no | Meta keys to return. Omit to return every allowed (non-protected) key |

**Output**: object — `id`, `meta` (object, key → value; a key with multiple stored values returns an array)

**Notes**: same per-post `current_user_can( 'edit_post', $post_id )` check as `update-post` (returns `gn_mcp_forbidden`, HTTP 403, otherwise), and the same post-type allowlist check (`gn_mcp_post_not_found`, HTTP 404, if the post's type isn't allowed). Unlike `update-post`, `check_permission()` here is not post-type-aware — WordPress's `edit_post`/`map_meta_cap()` machinery in the per-post check already resolves to `edit_page` for a `page` target, so the ability-level `edit_posts` default doesn't create the same gap for a read-only ability. "Protected" meta keys (starting with `_`, e.g. `_thumbnail_id`, `_edit_lock`) are excluded by default — see `gn_mcp_bridge_meta_key_allowed` in `CLAUDE.md`.

## `gn-mcp/update-post-meta`

Set, update, or delete custom fields (post meta) of an existing post.

- **Default capability**: `edit_posts`
- **Source**: `src/Abilities/UpdatePostMetaAbility.php`

**Input**

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the post to update meta on |
| `meta` | object | no | Map of meta key → value to set or update |
| `delete` | string[] | no | Meta keys to delete |

**Output**: object — `id`, `meta` (the post's full allowed meta after the change)

**Notes**: same per-post `current_user_can( 'edit_post', $post_id )` check as `update-post`, and the same post-type allowlist check (`gn_mcp_post_not_found`, HTTP 404, if the post's type isn't allowed). Any key in `meta` or `delete` that isn't allowed by `gn_mcp_bridge_meta_key_allowed` (protected by default) makes the whole call fail with `gn_mcp_meta_key_forbidden` (HTTP 403) — nothing is partially applied. String values are sanitized with `sanitize_text_field()`; non-string values (numbers, booleans, arrays) are passed through as-is.

## `gn-mcp/list-categories`

List existing post categories with their post counts.

- **Default capability**: `read`
- **Source**: `src/Abilities/ListCategoriesAbility.php`

**Input**: none

**Output**: array of objects — `name`, `slug`, `count`

**Notes**: includes empty categories (`hide_empty: false`). Stays at the `read` default deliberately — term post counts are computed only from `publish`-status posts, so this carries no draft/private-content leak that would justify raising it alongside `get-post`.

## Capability layers, at a glance

1. **Transport** — `gn_mcp_bridge_transport_capability` (default `read`): required just to reach `/wp-json/gn-mcp/mcp` at all, checked before any ability runs.
2. **Exposure** — `gn_mcp_bridge_exposed_abilities`: which of the abilities above are even listed on this site's `gn-mcp` server. An ability filtered out here is invisible to MCP clients, regardless of capability.
3. **Per-ability capability** — `gn_mcp_bridge_ability_capability( $capability, $ability_name )`: the specific WordPress capability a user needs to run one given ability (defaults from `Config::DEFAULT_CAPABILITIES`, shown above).
4. **Meta key allowlist** — `gn_mcp_bridge_meta_key_allowed( $allowed, $key )`: for `get-post-meta`/`update-post-meta` only, which individual meta keys may be read/written regardless of the ability-level capability above (defaults to excluding WordPress-protected keys).
5. **Post type allowlist** — `gn_mcp_bridge_allowed_post_types`: which post types `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, and `update-post-meta` may operate on (defaults to `post`, `page`, and `wp_block`).

All five are set from the consuming theme (see `README.md`'s "Per-site settings" section and `CLAUDE.md` for filter examples) — never by editing this plugin.
