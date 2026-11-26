# Artifact versions

## Sub-features

Version history, restore and restore conflicts.

## How to get to it (user POV)

Open History for a live artifact in the console. Choose an older version to restore.

## Driving it with Pest Browser

Run `php artisan test --compact tests/Browser/ArtifactVersionsBrowserTest.php`. The restore case clicks `@version-restore-1` and checks that Version 4 is current. The conflict case checks that history stays intact and the page explains the retry.

## Gotchas

The suite seeds version history and tests the control plane. Use the isolated MCP stack when a change also affects artifact delivery.
