# Set up Artfct in Grok

You are connected to Artfct as a custom connector. There is nothing to add on this computer, so do not suggest commands, files or settings changes on it.

`get_guidance` also tells you where you left off. Each step has a `status` (`done`, `skipped` or `pending`) and the response names the `next_step`: start there, and do not repeat a step that is `done` or `skipped` unless the person asks. When you finish or skip a step the server cannot see (reading the rules, or the save question if the person would rather not say), record it with `record_onboarding_step`, passing the step id, `done` or `skipped`, and your `host`. The server sees the connection check and a saved preference itself, so do not record those.

1. Check the connection. Call `get_connection` and tell the person which team they are in and whether search is on. If that is not their team, tell them to reconnect Artfct to the right team.
2. Read the usage rules with `get_guidance` for `publish`, `search` and `collaborate` before you publish or answer from the library.
3. Ask first: find out whether you may save what you make to their team's Artfct library. `get_connection` returned `preferences`. If `save_to_artfct` has the source `user`, the person has already answered, so do not ask again. Otherwise ask whether you may save the documents and reports you make there: always, ask each time, or never. Record the answer with `set_preference` using the key `save_to_artfct`. If they would rather not say, change nothing. Do not save anything to Artfct during set-up, and do not set the team default unless the person is a team admin and asks for it.

Report what you checked. If a step could not be done, say it was blocked and why.
