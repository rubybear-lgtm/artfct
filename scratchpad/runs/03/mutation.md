# Spec 03 mutation evidence

Both required negative guards were mutation-tested against their production
helpers:

* `ephemeral_manifest_is_invalid`: inverted the manifest presence check; command
  `cargo test -p artfct-backend ephemeral_mode_rejects_manifest_field` failed at
  `assert!(ephemeral_manifest_is_invalid(&payload))`. The mode/manifest guard was
  restored, and the same command passed.
* `authorization_matches`: changed the missing configured-token branch to return
  `true`; command `cargo test -p artfct-backend permanent_mode_requires_auth`
  failed at the missing-expected-token assertion. The constant-time,
  configured-token guard was restored, and the same command passed.
