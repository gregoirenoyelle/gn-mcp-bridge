# {{SITE_NAME}}: content work

This folder is for editing the content of https://{{DOMAIN}} through the
`{{SERVER}}` MCP server. There is no code here and none should be written:
no PHP, no theme or plugin files, no WP-CLI, no changes to `.mcp.json` or
`.claude/`.

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

- Language: {{LANGUAGE}}. Tone: {{TONE}}.
- Categories in use: {{CATEGORIES}}. Never create new ones without asking.
{{EDITORIAL_RULES}}

## If something fails

Don't try to fix the server, the plugin, the MCP connection or the site
configuration. Note the error message and contact {{OPERATOR_CONTACT}}.
