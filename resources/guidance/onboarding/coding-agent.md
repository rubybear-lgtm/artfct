# Set up Artfct in a coding agent

You are a coding agent, such as Codex, VS Code with Copilot, OpenCode, Windsurf, Zed or Antigravity. Do these steps in order. Never ask the person to run a command, and ask first before any step marked "ask".

`get_guidance` also tells you where you left off. Each step has a `status` (`done`, `skipped` or `pending`) and the response names the `next_step`: start there, and do not repeat a step that is `done` or `skipped` unless the person asks. When you finish or skip a step the server cannot see (reading the rules, or the save question if the person would rather not say), record it with `record_onboarding_step`, passing the step id, `done` or `skipped`, and your `host`. The server sees the connection check and a saved preference itself, so do not record those.

1. Check the connection. Call `get_connection` and tell the person which team they are in and whether search is on. If you are not signed in, ask them to approve the sign-in page in their browser. Never ask for a password or a token.
2. Read the usage rules with `get_guidance` for `publish`, `search` and `collaborate` before you publish or answer from the library.
3. Ask first: find out whether you may save what you make to their team's Artfct library. `get_connection` returned `preferences`. If `save_to_artfct` has the source `user`, the person has already answered, so do not ask again. Otherwise ask whether you may save the documents and reports you make there: always, ask each time, or never. Record the answer with `set_preference` using the key `save_to_artfct`. If they would rather not say, change nothing. Do not save anything to Artfct during set-up, and do not set the team default unless the person is a team admin and asks for it.
4. Ask first: offer to teach this project about Artfct. If the `artfct-init` skill is already available, run it with `--host` set to your own app (`codex`, `vscode`, `opencode`, `windsurf`, `zed` or `antigravity`). Otherwise, show the person the block below and, if they agree, put it in the project instructions file your app reads (`AGENTS.md` if you are not sure). If the file already has an `<!-- artfct:start -->` marker, replace everything from that marker through `<!-- artfct:end -->` with the block, so running this again never adds a second copy, and leave every other line as it is. If it has no marker, add the block at the end of the file:

    <!-- artfct:start -->

    > Before research, a report or an analysis, search Artfct with `search_artifacts` for related work; publish anything worth keeping with `deploy_artifact` and share its link.

    <!-- artfct:end -->

5. Tell the person that your app asks before it first uses each Artfct tool, and that they can approve them there. Do not edit permission settings yourself.

Report what you did and what you skipped. If a step could not be done, say it was blocked and why.
