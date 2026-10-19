# Claude Code for {{SITE_NAME}}: getting started

This folder connects Claude Code to your site, https://{{DOMAIN}}. It contains
your site access: keep it private, don't share, copy or upload it.

## Once: installation

1. **Open the Terminal.**
   - Mac: press `Cmd + Space`, type `Terminal`, press `Enter`.
   - Windows: open the Start menu, type `PowerShell`, press `Enter`.
2. **Install Claude Code.** Copy this line, paste it into the Terminal and
   press `Enter`:
   - Mac: `curl -fsSL https://claude.ai/install.sh | bash`
   - Windows: `irm https://claude.ai/install.ps1 | iex`
3. **Put this folder in your home folder**, not in Documents, on the Desktop,
   or in Dropbox, iCloud Drive or OneDrive (those are often synced online).
   - Mac: in the Finder, menu *Go → Home*, then move the `{{KIT_NAME}}` folder there.
   - Windows: move it to `C:\Users\<your name>\`.

## Every time: start a session

In the Terminal, type these two lines, pressing `Enter` after each:

```text
cd ~/{{KIT_NAME}}
claude
```

The first time only:

- Claude Code opens your browser: sign in with **your own** Claude account.
- It asks whether you trust this folder: accept.
- It says it found the `{{SERVER}}` MCP server: choose the option that
  uses this server.

Then type a first test: `List the categories on my site`. You should see your
site's categories.

Always start from this folder (`cd ~/{{KIT_NAME}}`). Started anywhere else,
Claude Code has no access to your site.

## Working

Ask in plain language: "rewrite the introduction of the About page", "draft a
post about X in the News category". Claude reads first, shows what it will
change, and asks before writing. Answer its questions; nothing is published
without you.

To quit: type `/exit`.

## If something goes wrong

Don't try to fix it, and don't let Claude try to repair the connection. Take a
screenshot and contact {{OPERATOR_CONTACT}}.
