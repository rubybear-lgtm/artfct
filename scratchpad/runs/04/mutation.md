# Spec 04 mutation evidence

Executed 2026-09-03 against `backend/src/lib.rs`. Each production guard was
removed independently with `apply_patch`, the exact named test was run, then the
guard was restored and the same test was rerun. No mutation was left in the tree.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Entrypoint must appear in `files[]` | `cargo test -p artfct-backend manifest_missing_entrypoint_rejected` | failed, exit 101: validator returned `Ok` instead of `EntrypointMissing` | passed, exit 0 |
| Parent traversal segment rejection | `cargo test -p artfct-backend manifest_path_traversal_rejected` | failed, exit 101: `../etc/passwd` validated | passed, exit 0 |
| Leading-slash absolute path rejection | `cargo test -p artfct-backend manifest_absolute_path_rejected` | failed, exit 101: `/index.html` validated | passed, exit 0 |
| Duplicate manifest-path rejection | `cargo test -p artfct-backend manifest_duplicate_paths_rejected` | failed, exit 101: both duplicate entries validated | passed, exit 0 |
| Uploaded-byte SHA-256 verification | `cargo test -p artfct-backend file_hash_mismatch_rejected` | failed, exit 101: mismatched bytes returned no error | passed, exit 0 |
| 50 MiB aggregate bundle ceiling | `cargo test -p artfct-backend bundle_over_limit_rejected` | failed, exit 101: three 20 MiB files (60 MiB total) validated | passed, exit 0 |
| 500-file ceiling | `cargo test -p artfct-backend file_count_over_limit_rejected` | failed, exit 101: 501 files validated | passed, exit 0 |

The traversal, absolute-path, and duplicate-path mutations exercise distinct
validation branches. The size mutation uses files individually below 25 MiB so
only removal of the aggregate limit can make the test pass validation.
