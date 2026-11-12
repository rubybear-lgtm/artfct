# Set up Artfct in a coding agent

You are a coding agent, such as Codex, VS Code with Copilot, OpenCode, Windsurf, Zed or Antigravity. Do these steps in order. Never ask the person to run a command, and ask first before any step marked "ask".

1. Check the connection. Call `get_connection` and tell the person which team they are in and whether search is on. If you are not signed in, ask them to approve the sign-in page in their browser. Never ask for a password or a token.
2. Read the usage rules with `get_guidance` for `publish`, `search` and `collaborate` before you publish or answer from the library.
3. Ask first: offer to teach this project about Artfct. If the `artfct-init` skill is already available, run it with `--host` set to your own app (`codex`, `vscode`, `opencode`, `windsurf`, `zed` or `antigravity`). Otherwise, show the person this line and, if they agree, add it to the project instructions file your app reads (`AGENTS.md` if you are not sure), unless the file already has it:

    > Before research, a report or an analysis, search Artfct with `search_artifacts` for related work; publish anything worth keeping with `deploy_artifact` and share its link.

4. Tell the person that your app asks before it first uses each Artfct tool, and that they can approve them there. Do not edit permission settings yourself.

Report what you did and what you skipped. If a step could not be done, say it was blocked and why.
