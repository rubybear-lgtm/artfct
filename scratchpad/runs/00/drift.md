# Spec 00 contract drift demonstration

The contract unit temporarily renamed the required
`body_ciphertext_b64` property in `EphemeralArtifactRequest` to
`body_ciphertext_b64_drift_probe` without changing the CLI serializer.

The focused command was run while that mutation was present:

```text
$ cargo test -p artfct cli_create_request_validates_against_contract -- --nocapture
running 1 test
CLI create request matches EphemeralArtifactRequest:
EphemeralArtifactRequest.body_ciphertext_b64_drift_probe is required
test api::tests::cli_create_request_validates_against_contract ... FAILED
test result: FAILED. 0 passed; 1 failed; 31 filtered out
error: test failed, to rerun pass `-p artfct --bin artfct`
```

The command exited 101. The contract mutation was then reverted exactly and the
same command was rerun:

```text
running 1 test
test api::tests::cli_create_request_validates_against_contract ... ok
test result: ok. 1 passed; 0 failed; 31 filtered out
```

The restored command exited 0. The final working tree contains
`body_ciphertext_b64`; the probe name is absent.

This demonstrates that the blocking `contract_drift` CI job detects a field-name
mismatch between the OpenAPI contract and the CLI request.
