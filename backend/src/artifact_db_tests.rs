//! Host-runnable contract tests for spec 20. The async handlers need a live D1
//! and are exercised on the staging wildcard origins per the rollout plan;
//! everything a handler decides — guards, roles, validation, quota, SQL
//! bindings — is a pure function pinned here, one behavior per test.

#[cfg(test)]
mod tests {
    use crate::artifact_origin::{mint_access_token, mint_viewer_access_token};
    use super::super::*;
    use std::collections::BTreeMap;

    const SECRET: &str = "test-database-secret";
    const ARTIFACT_A: &str = "aaaaaaaaaaaaa";
    const ARTIFACT_B: &str = "bbbbbbbbbbbbb";
    const HOST_A: &str = "acme--aaaaaaaaaaaaa--prod.artfct.dev";
    const OWNER: &str = "owner1";

    fn now() -> chrono::DateTime<Utc> {
        Utc::now()
    }

    fn future() -> chrono::DateTime<Utc> {
        Utc::now() + chrono::Duration::hours(1)
    }

    fn past() -> chrono::DateTime<Utc> {
        Utc::now() - chrono::Duration::hours(1)
    }

    fn member_token(artifact: &str) -> String {
        mint_viewer_access_token(SECRET, artifact, future(), "user1", false)
    }

    fn member_viewer() -> TokenViewer {
        TokenViewer {
            viewer: Some("user1".to_string()),
            sees_private: false,
        }
    }

    fn classify(token: &str, artifact: &str) -> PresentedToken {
        classify_db_token(Some(SECRET), token, artifact, now())
    }

    fn db_fixture() -> DbManifest {
        let mut collections = BTreeMap::new();
        collections.insert(
            "cards".to_string(),
            validation::DbCollectionDecl {
                index: Some("column".to_string()),
            },
        );
        collections.insert(
            "notes".to_string(),
            validation::DbCollectionDecl { index: None },
        );
        DbManifest {
            collections,
            seed: vec![validation::DbSeedRow {
                collection: "cards".to_string(),
                doc_id: "c1".to_string(),
                body: serde_json::json!({"title": "Seed", "column": "todo"}),
            }],
        }
    }

    fn doc_row(doc_id: &str, version: i64) -> DbDocRow {
        DbDocRow {
            doc_id: doc_id.to_string(),
            body: serde_json::json!({"title": doc_id}).to_string(),
            version,
            updated_at: "2026-01-01T00:00:00Z".to_string(),
            updated_by: "user1".to_string(),
        }
    }

    /// The adversarial one: a script in artifact B causing a write to
    /// artifact A is blocked on every vector, even with a valid token.
    #[test]
    fn cross_artifact_write_is_blocked() {
        let token_a = member_token(ARTIFACT_A);
        // The token verifies on A's origin (control).
        assert!(matches!(
            classify(&token_a, ARTIFACT_A),
            PresentedToken::This(_)
        ));
        // On B's origin it classifies as another artifact's token...
        let bearer = classify(&token_a, ARTIFACT_B);
        assert_eq!(bearer, PresentedToken::OtherArtifact);
        // ...so both writes and reads are forbidden there.
        assert_eq!(
            authorize_db(
                Sharing::Team,
                EditAccess::Edit,
                Some(OWNER),
                Some(bearer.clone()),
                None,
                true
            ),
            Err(DbDeny::Forbidden)
        );
        assert_eq!(
            authorize_db(
                Sharing::Team,
                EditAccess::Edit,
                Some(OWNER),
                Some(bearer),
                None,
                false
            ),
            Err(DbDeny::Forbidden)
        );
        // And on A's own origin the same token writes (control).
        assert!(matches!(
            authorize_db(
                Sharing::Team,
                EditAccess::Edit,
                Some(OWNER),
                Some(classify(&token_a, ARTIFACT_A)),
                None,
                true
            ),
            Ok(auth) if auth.role == DbRole::Member
        ));
    }

    /// A cross-site form post carries `Sec-Fetch-Site: cross-site` (and a
    /// foreign `Origin`), so defense 1 rejects it before auth is consulted.
    #[test]
    fn cross_site_form_post_write_rejected() {
        assert!(!db_fetch_site_ok(
            Some("cross-site"),
            Some("https://evil.example/"),
            HOST_A
        ));
        assert!(!db_fetch_site_ok(None, Some("https://evil.example/"), HOST_A));
        assert!(!db_fetch_site_ok(None, None, HOST_A));
        // Control: the page's own same-origin post passes.
        assert!(db_fetch_site_ok(
            Some("same-origin"),
            Some("https://evil.example/"),
            HOST_A
        ));
        // Old clients without the header fall back to an exact Origin match.
        assert!(db_fetch_site_ok(None, Some(&format!("https://{HOST_A}")), HOST_A));
        assert!(!db_fetch_site_ok(None, Some("https://other.artfct.dev"), HOST_A));
    }

    /// A `no-cors` `text/plain` fetch can attach the cookie but can neither
    /// set the custom header nor use JSON, so defense 2 rejects it.
    #[test]
    fn no_cors_text_plain_write_rejected() {
        assert!(!db_request_shape_ok(&Method::Put, Some("text/plain"), None));
        assert!(!db_request_shape_ok(&Method::Put, Some("text/plain"), Some("1")));
        assert!(!db_request_shape_ok(
            &Method::Put,
            Some("application/json"),
            None
        ));
        // Control: the page's JSON request with the header passes, including
        // with a charset parameter and surrounding whitespace.
        assert!(db_request_shape_ok(
            &Method::Put,
            Some("application/json"),
            Some("1")
        ));
        assert!(db_request_shape_ok(
            &Method::Post,
            Some("application/json; charset=utf-8"),
            Some(" 1 ")
        ));
    }

    /// Defense 3: writes need a bearer token minted for this artifact. The
    /// cookie alone — even a valid member cookie — answers 401.
    #[test]
    fn cookie_alone_cannot_write() {
        let cookie = Some(member_viewer());
        assert_eq!(
            authorize_db(
                Sharing::Team,
                EditAccess::Edit,
                Some(OWNER),
                None,
                cookie.clone(),
                true
            ),
            Err(DbDeny::Unauthorized)
        );
        // Control: the same cookie reads, and a bearer writes.
        assert!(authorize_db(
            Sharing::Team,
            EditAccess::Edit,
            Some(OWNER),
            None,
            cookie,
            false
        )
        .is_ok());
        assert!(matches!(
            authorize_db(
                Sharing::Team,
                EditAccess::Edit,
                Some(OWNER),
                Some(classify(&member_token(ARTIFACT_A), ARTIFACT_A)),
                None,
                true
            ),
            Ok(auth) if auth.role == DbRole::Member
        ));
    }

    /// `same-site` is a different origin that shares the site (spec 20
    /// Threat): never accepted, with or without a valid cookie. `none`
    /// (top-level navigation) is never accepted either.
    #[test]
    fn same_site_fetch_metadata_rejected() {
        assert!(!db_fetch_site_ok(Some("same-site"), None, HOST_A));
        assert!(!db_fetch_site_ok(Some("none"), None, HOST_A));
        assert!(!db_fetch_site_ok(Some("cross-site"), None, HOST_A));
        assert!(db_fetch_site_ok(Some("same-origin"), None, HOST_A));
    }

    /// The preflight answer carries no CORS allowance, and neither does any
    /// other response on the prefix — which is what makes defense 2 a
    /// defense rather than a header check.
    #[test]
    fn options_returns_no_cors_headers() {
        assert!(db_options_headers().is_empty());
        for (name, _) in db_response_headers() {
            assert!(
                !name.to_ascii_lowercase().starts_with("access-control-"),
                "db responses must share no CORS allowance: {name}"
            );
        }
    }

    /// A token minted for artifact A, presented on B, is 403 (wrong place,
    /// valid credential) — distinct from garbage, which is 401.
    #[test]
    fn token_for_other_artifact_rejected() {
        let token_a = member_token(ARTIFACT_A);
        assert_eq!(classify(&token_a, ARTIFACT_B), PresentedToken::OtherArtifact);
        assert_eq!(classify("not-a-token", ARTIFACT_B), PresentedToken::Garbage);
        assert_eq!(classify("", ARTIFACT_B), PresentedToken::Garbage);
        // An expired token for the right artifact is garbage, not cross-artifact.
        let stale = mint_viewer_access_token(SECRET, ARTIFACT_B, past(), "user1", false);
        assert_eq!(classify(&stale, ARTIFACT_B), PresentedToken::Garbage);
        // A token with a forged signature verifies nowhere.
        let mut forged = member_token(ARTIFACT_A);
        forged.pop();
        forged.push('0');
        assert_eq!(classify(&forged, ARTIFACT_A), PresentedToken::Garbage);
        assert_eq!(classify(&forged, ARTIFACT_B), PresentedToken::Garbage);
    }

    /// Tenancy binds from the verified token and the D1 row. Forged
    /// `artifact_id`/`org_id` keys in the path or body have nowhere to bind:
    /// the batch op struct has no tenant field, and authorization takes its
    /// target from its arguments.
    #[test]
    fn path_and_body_cannot_override_artifact_id() {
        let ops = parse_db_batch_ops(&serde_json::json!({
            "artifact_id": ARTIFACT_A,
            "org_id": "evil",
            "ops": [{
                "op": "put",
                "collection": "cards",
                "doc_id": "c1",
                "body": {"title": "x"},
                "artifact_id": ARTIFACT_A,
                "org_id": "evil",
            }]
        }))
        .expect("extra keys are ignored, not bound");
        assert_eq!(ops.len(), 1);
        assert_eq!(ops[0].collection, "cards");
        assert_eq!(ops[0].doc_id, "c1");
        // The path binds collection and doc id only.
        assert_eq!(
            parse_db_path("/__artfct/db/cards/c1"),
            Some(DbPath::Doc {
                collection: "cards",
                doc_id: "c1"
            })
        );
        // The authorized target is the verified token's artifact, whatever the
        // body claimed.
        let auth = authorize_db(
            Sharing::Team,
            EditAccess::Edit,
            Some(OWNER),
            Some(classify(&member_token(ARTIFACT_B), ARTIFACT_B)),
            None,
            true,
        )
        .expect("member writes on their own artifact");
        assert_eq!(auth.role, DbRole::Member);
    }

    /// A team member without edit access reads but gets 403 on write.
    #[test]
    fn viewer_role_can_read_cannot_write() {
        let viewer = member_viewer();
        assert_eq!(
            db_role_for(Sharing::Team, EditAccess::View, Some(OWNER), Some(&viewer)),
            Some(DbRole::Viewer)
        );
        assert!(!DbRole::Viewer.can_write());
        assert!(authorize_db(
            Sharing::Team,
            EditAccess::View,
            Some(OWNER),
            Some(PresentedToken::This(viewer.clone())),
            None,
            false
        )
        .is_ok());
        assert_eq!(
            authorize_db(
                Sharing::Team,
                EditAccess::View,
                Some(OWNER),
                Some(PresentedToken::This(viewer)),
                None,
                true
            ),
            Err(DbDeny::Forbidden)
        );
    }

    /// Every write path calls the one role function; anything it does not
    /// recognise is no access (fail closed), matching the sharing rule.
    #[test]
    fn unknown_role_fails_closed() {
        // Unknown tiers fail closed to private; unknown edit access to view.
        assert_eq!(Sharing::from_tier("superpublic"), Sharing::Private);
        assert_eq!(EditAccess::from_stored("write-freely"), EditAccess::View);
        let member = member_viewer();
        assert_eq!(
            db_role_for(Sharing::from_tier("bogus"), EditAccess::from_stored("bogus"), Some(OWNER), Some(&member)),
            None
        );
        assert_eq!(
            db_role_for(Sharing::Private, EditAccess::View, None, None),
            None
        );
        // A member on a team artifact with unrecognised edit access reads but
        // cannot write.
        assert_eq!(
            db_role_for(
                Sharing::Team,
                EditAccess::from_stored("bogus"),
                Some(OWNER),
                Some(&member)
            ),
            Some(DbRole::Viewer)
        );
    }

    /// Declared collections serve; anything else is a 422, never a 404 (the
    /// endpoint exists, the collection does not).
    #[test]
    fn undeclared_collection_rejected() {
        let manifest = db_fixture();
        assert!(db_collection_declared(&manifest, "cards"));
        assert!(db_collection_declared(&manifest, "notes"));
        assert!(!db_collection_declared(&manifest, "nope"));
    }

    /// An artifact whose manifest declares no database has no endpoint: the
    /// prefix answers 404 and the artifact is otherwise unchanged.
    #[test]
    fn artifact_without_db_manifest_has_no_endpoint() {
        assert!(!db_endpoint_active(None));
        assert!(!db_endpoint_active(Some(&DbManifest::default())));
        assert!(!db_endpoint_active(Some(&DbManifest {
            collections: BTreeMap::new(),
            seed: vec![validation::DbSeedRow {
                collection: "cards".to_string(),
                doc_id: "c1".to_string(),
                body: serde_json::json!({}),
            }],
        })));
        assert!(db_endpoint_active(Some(&db_fixture())));
    }

    /// A bundle path under the reserved `__artfct/` prefix is rejected at
    /// deploy, so a page can never shadow the database API.
    #[test]
    fn reserved_path_prefix_rejected_at_deploy() {
        fn raw_with(paths: &[&str]) -> Value {
            Value::Object(
                [(
                    "manifest".to_string(),
                    serde_json::json!({
                        "entrypoint": "index.html",
                        "external_origins": [],
                        "files": paths.iter().map(|path| serde_json::json!({
                            "path": path,
                            "size_bytes": 1,
                            "sha256": "a".repeat(64),
                            "content_type": "text/html",
                        })).collect::<Vec<_>>(),
                    }),
                )]
                .into_iter()
                .collect(),
            )
        }
        assert_eq!(
            validation::validate_permanent_manifest(&raw_with(&["index.html", "__artfct/db/session"])),
            Err(ErrorCode::InvalidPath)
        );
        assert_eq!(
            validation::validate_permanent_manifest(&raw_with(&["__artfct"])),
            Err(ErrorCode::InvalidPath)
        );
        // Control: ordinary nested paths still deploy.
        assert!(validation::validate_permanent_manifest(&raw_with(&["index.html", "a/b.css"])).is_ok());
    }

    /// Rows are keyed by the stable id, never a version: no document query
    /// filters on versions, and a republish that omits `db` inherits the
    /// declaration, so rows stay addressable across versions.
    #[test]
    fn rows_survive_republish() {
        for sql in [
            DB_GET_SQL,
            DB_LIST_BASE_SQL,
            DB_UPSERT_SQL,
            DB_COUNT_SQL,
            DB_SEED_INSERT_SQL,
            DB_DELETE_ARTIFACT_SQL,
        ] {
            assert!(sql.contains("artifact_id"), "{sql}");
            assert!(
                !sql.contains("artifact_versions") && !sql.contains("current_version"),
                "doc rows must not be version-scoped: {sql}"
            );
        }
        let previous = serde_json::json!({
            "entrypoint": "index.html",
            "db": {"collections": {"cards": {"index": "column"}}, "seed": []},
        });
        let raw = serde_json::json!({"entrypoint": "index.html"});
        let carried = validation::apply_db_carry_forward(&raw, &previous);
        assert_eq!(carried.get("db"), previous.get("db"));
    }

    /// Seed rows are derived from the manifest on first deploy only: the
    /// declaration carries forward across republishes, but the publish path
    /// has no seed call site.
    #[test]
    fn seed_written_once_not_on_republish() {
        let manifest = db_fixture();
        let inserts = db_seed_inserts(&manifest);
        assert_eq!(inserts.len(), 1);
        assert_eq!(inserts[0].collection, "cards");
        assert_eq!(inserts[0].doc_id, "c1");
        assert_eq!(inserts[0].index_value.as_deref(), Some("todo"));
        // The seed statement binds the stable id at version 1.
        assert!(DB_SEED_INSERT_SQL.contains("artifact_id"));
        assert!(DB_SEED_INSERT_SQL.contains(", 1, "));
        // Carry-forward preserves the seed verbatim for the next version's
        // declaration without rewriting any row.
        let previous = serde_json::json!({
            "entrypoint": "index.html",
            "db": validation::validate_db_manifest(&serde_json::json!({
                "db": {"collections": {"cards": {"index": "column"}}, "seed": [
                    {"collection": "cards", "doc_id": "c1", "body": {"column": "todo"}}
                ]}
            }))
            .expect("fixture is valid")
            .map(|manifest| serde_json::to_value(&manifest).expect("serializable")),
        });
        let carried = validation::apply_db_carry_forward(
            &serde_json::json!({"entrypoint": "index.html"}),
            &previous,
        );
        assert_eq!(carried.get("db"), previous.get("db"));
    }

    /// A stale `if_version` fails with 409 and the current document; a
    /// missing document with a guard fails with 404.
    #[test]
    fn stale_if_version_conflicts() {
        assert_eq!(db_guard_version(Some(3), Some(2)), Err(DbWriteConflict::Stale));
        assert_eq!(db_guard_version(Some(3), Some(3)), Ok(()));
        assert_eq!(db_guard_version(None, Some(1)), Err(DbWriteConflict::Missing));
        assert_eq!(db_guard_version(Some(3), None), Ok(()));
        assert_eq!(DB_STALE_STATUS, 409);
        let current = doc_row("c1", 3);
        let details = db_conflict_details("cards", &current);
        assert_eq!(details["current"]["doc_id"], "c1");
        assert_eq!(details["current"]["version"], 3);
        assert_eq!(details["current"]["collection"], "cards");
    }

    /// One failing write applies none: the batch validator runs over every
    /// operation before any statement is built, and guard statements abort a
    /// racing batch so no part of it lands.
    #[test]
    fn batch_is_atomic() {
        let ops = parse_db_batch_ops(&serde_json::json!({"ops": [
            {"op": "put", "collection": "cards", "doc_id": "c1", "body": {}},
            {"op": "put", "collection": "cards", "doc_id": "c2", "body": {}, "if_version": 5},
        ]}))
        .expect("valid batch");
        let mut existing = HashMap::new();
        existing.insert(("cards".to_string(), "c2".to_string()), doc_row("c2", 4));
        let refusal = validate_db_batch(&ops, &existing, &db_fixture()).expect_err("stale write fails");
        assert!(matches!(refusal, DbBatchRefusal::Stale { index: 1, .. }));
        // The guard statements turn a concurrent move into a batch abort.
        assert!(DB_GUARD_UPDATE_SQL.contains("NOT EXISTS"));
        assert!(DB_GUARD_UPDATE_SQL.contains("NULL"));
        assert!(DB_GUARD_DELETE_SQL.contains("EXISTS"));
        // Duplicate documents within one batch are refused up front.
        assert!(parse_db_batch_ops(&serde_json::json!({"ops": [
            {"op": "put", "collection": "cards", "doc_id": "c1", "body": {}},
            {"op": "delete", "collection": "cards", "doc_id": "c1"},
        ]}))
        .is_err());
    }

    /// Over quota fails writes with 429 and the limit named; reads carry no
    /// quota predicate and are never blocked.
    #[test]
    fn quota_blocks_write_not_read() {
        let limits = DbQuotaLimits {
            max_docs: 2,
            max_bytes: 100,
        };
        assert_eq!(db_quota_check(limits, 2, 10, 1, 0), Err(DbQuotaDeny::Docs));
        assert_eq!(db_quota_check(limits, 1, 100, 0, 1), Err(DbQuotaDeny::Bytes));
        assert!(db_quota_check(limits, 1, 10, 1, 10).is_ok());
        // Deletes and shrinks free quota rather than consuming it.
        assert!(db_quota_check(limits, 2, 100, -1, -50).is_ok());
        assert_eq!(DbQuotaDeny::Docs.limit_name(), "max_db_docs_per_artifact");
        assert_eq!(DbQuotaDeny::Bytes.limit_name(), "max_db_bytes_per_org");
        assert_eq!(DB_QUOTA_STATUS, 429);
        for sql in [DB_GET_SQL, DB_LIST_BASE_SQL] {
            assert!(!sql.contains("org_limits"), "reads are never blocked by quota: {sql}");
        }
    }

    /// Deleting an artifact removes its rows in the same operation, bound to
    /// the same org and stable id as every other delete statement.
    #[test]
    fn artifact_delete_removes_rows() {
        assert!(DB_DELETE_ARTIFACT_SQL.contains("DELETE FROM artifact_docs"));
        assert!(DB_DELETE_ARTIFACT_SQL.contains("org_id = (SELECT id FROM orgs WHERE slug = ?)"));
        assert!(DB_DELETE_ARTIFACT_SQL.contains("artifact_id = ?"));
    }

    // Supporting behaviors the nineteen tests above rest on.

    #[test]
    fn db_paths_route_to_the_declared_shapes() {
        assert_eq!(parse_db_path("/__artfct/db/session"), Some(DbPath::Session));
        assert_eq!(parse_db_path("/__artfct/db/batch"), Some(DbPath::Batch));
        assert_eq!(
            parse_db_path("/__artfct/db/cards"),
            Some(DbPath::Collection { collection: "cards" })
        );
        assert_eq!(parse_db_path("/__artfct/db/"), None);
        assert_eq!(parse_db_path("/__artfct/db/a/b/c"), None);
        assert_eq!(parse_db_path("/__artfct/other"), None);
        assert_eq!(parse_db_path("/v1/artifacts"), None);
    }

    #[test]
    fn db_list_params_default_clamp_and_reject() {
        let params = parse_db_list_params(&HashMap::new()).expect("defaults");
        assert_eq!(params.limit, DB_LIST_DEFAULT_LIMIT);
        assert_eq!(params.cursor, None);
        let mut over = HashMap::new();
        over.insert("limit".to_string(), "1000".to_string());
        assert_eq!(
            parse_db_list_params(&over).expect("clamps").limit,
            DB_LIST_MAX_LIMIT
        );
        for bad in ["0", "-1", "many", ""] {
            let mut query = HashMap::new();
            query.insert("limit".to_string(), bad.to_string());
            assert!(parse_db_list_params(&query).is_err(), "limit {bad}");
        }
        let mut cursor = HashMap::new();
        cursor.insert("cursor".to_string(), "c9".to_string());
        cursor.insert("index_eq".to_string(), "todo".to_string());
        let params = parse_db_list_params(&cursor).expect("valid");
        assert_eq!(params.cursor.as_deref(), Some("c9"));
        assert_eq!(params.index_eq.as_deref(), Some("todo"));
    }

    #[test]
    fn db_manifest_validation_pins_the_declaration_shape() {
        // Absent or null declares nothing.
        assert_eq!(
            validation::validate_db_manifest(&serde_json::json!({"entrypoint": "index.html"})),
            Ok(None)
        );
        assert_eq!(
            validation::validate_db_manifest(&serde_json::json!({"db": null})),
            Ok(None)
        );
        // Declared collections validate; an empty object is a collection with
        // no secondary index.
        let manifest = validation::validate_db_manifest(&serde_json::json!({"db": {
            "collections": {"cards": {"index": "column"}, "notes": {}},
        }}))
        .expect("valid")
        .expect("declares a database");
        assert_eq!(
            manifest.collections["cards"].index.as_deref(),
            Some("column")
        );
        assert_eq!(manifest.collections["notes"].index, None);
        assert!(manifest.seed.is_empty());
        // Unknown keys fail closed at every level.
        for raw in [
            serde_json::json!({"db": {"tables": {}}}),
            serde_json::json!({"db": {"collections": {"cards": {"index": "column", "shard": 1}}}}),
            serde_json::json!({"db": {"collections": {"cards": {"index": 1}}}}),
            serde_json::json!({"db": {"collections": {"bad name!": {}}}}),
            serde_json::json!({"db": {"collections": {"cards": {}}, "seed": [
                {"collection": "missing", "doc_id": "c1", "body": {}}
            ]}}),
            serde_json::json!({"db": {"collections": {"cards": {}}, "seed": [
                {"collection": "cards", "doc_id": "c1", "body": []}
            ]}}),
            serde_json::json!({"db": {"collections": {"cards": {}}, "seed": [
                {"collection": "cards", "doc_id": "c1", "body": {}},
                {"collection": "cards", "doc_id": "c1", "body": {}},
            ]}}),
        ] {
            assert!(validation::validate_db_manifest(&raw).is_err(), "{raw}");
        }
    }

    #[test]
    fn db_seed_cap_is_256_kib() {
        let big = "x".repeat(validation::MAX_DB_SEED_BYTES + 1);
        let raw = serde_json::json!({"db": {"collections": {"cards": {}}, "seed": [
            {"collection": "cards", "doc_id": "c1", "body": {"pad": big}}
        ]}});
        assert_eq!(
            validation::validate_db_manifest(&raw),
            Err(ErrorCode::BundleTooLarge)
        );
    }

    #[test]
    fn db_names_follow_the_doc_id_set() {
        assert!(validation::is_valid_db_name("cards-1_x.y", validation::MAX_DB_COLLECTION_CHARS));
        assert!(!validation::is_valid_db_name("", validation::MAX_DB_COLLECTION_CHARS));
        assert!(!validation::is_valid_db_name("has space", validation::MAX_DB_COLLECTION_CHARS));
        assert!(!validation::is_valid_db_name("slash/x", validation::MAX_DB_COLLECTION_CHARS));
        assert!(!validation::is_valid_db_name(
            &"c".repeat(validation::MAX_DB_COLLECTION_CHARS + 1),
            validation::MAX_DB_COLLECTION_CHARS
        ));
        assert!(validation::is_valid_db_name(
            &"d".repeat(validation::MAX_DB_DOC_ID_CHARS),
            validation::MAX_DB_DOC_ID_CHARS
        ));
    }

    #[test]
    fn db_index_value_extracts_scalars_only() {
        let body = serde_json::json!({"s": "todo", "n": 3, "b": true, "o": {}, "z": null});
        assert_eq!(db_index_value(&body, "s").as_deref(), Some("todo"));
        assert_eq!(db_index_value(&body, "n").as_deref(), Some("3"));
        assert_eq!(db_index_value(&body, "b").as_deref(), Some("true"));
        assert_eq!(db_index_value(&body, "o"), None);
        assert_eq!(db_index_value(&body, "z"), None);
        assert_eq!(db_index_value(&body, "missing"), None);
    }

    #[test]
    fn db_roles_cover_the_sharing_table() {
        let owner = TokenViewer {
            viewer: Some(OWNER.to_string()),
            sees_private: false,
        };
        let admin = TokenViewer {
            viewer: Some("admin1".to_string()),
            sees_private: true,
        };
        let member = member_viewer();
        // Owner and admin read and write everywhere in the team.
        for sharing in [Sharing::Public, Sharing::Team, Sharing::Private] {
            for viewer in [&owner, &admin] {
                let role = db_role_for(sharing, EditAccess::View, Some(OWNER), Some(viewer))
                    .expect("owner and admin always have a role");
                assert!(role.can_write(), "{sharing:?} {role:?}");
            }
        }
        // Members write only when edit access allows.
        assert_eq!(
            db_role_for(Sharing::Team, EditAccess::Edit, Some(OWNER), Some(&member)),
            Some(DbRole::Member)
        );
        assert!(DbRole::Member.can_write());
        // Anonymous link holders read public artifacts and nothing else.
        assert_eq!(
            db_role_for(Sharing::Public, EditAccess::Edit, Some(OWNER), None),
            Some(DbRole::Link)
        );
        assert!(!DbRole::Link.can_write());
        assert_eq!(db_role_for(Sharing::Team, EditAccess::Edit, Some(OWNER), None), None);
        // A legacy token naming nobody is anonymous-equivalent, never a member.
        let nobody = TokenViewer {
            viewer: None,
            sees_private: false,
        };
        assert_eq!(
            db_role_for(Sharing::Team, EditAccess::Edit, Some(OWNER), Some(&nobody)),
            None
        );
    }

    #[test]
    fn db_host_and_row_must_agree() {
        let row = DbArtifactRow {
            id: ARTIFACT_A.to_string(),
            org: "acme".to_string(),
            tier: "secure".to_string(),
            user_id: Some(OWNER.to_string()),
            edit_access: "edit".to_string(),
            manifest: "{}".to_string(),
        };
        assert!(db_host_matches_row("acme", ARTIFACT_A, &row));
        assert!(!db_host_matches_row("other", ARTIFACT_A, &row));
        assert!(!db_host_matches_row("acme", ARTIFACT_B, &row));
    }

    #[test]
    fn db_queries_bind_org_and_artifact() {
        for sql in [
            DB_GET_SQL,
            DB_LIST_BASE_SQL,
            DB_UPSERT_SQL,
            DB_UPDATE_SQL,
            DB_UPDATE_GUARDED_SQL,
            DB_DELETE_SQL,
            DB_DELETE_GUARDED_SQL,
            DB_COUNT_SQL,
            DB_SEED_INSERT_SQL,
        ] {
            // Tenancy travels in the statement, never from the path or body:
            // every statement names both columns (the point lookups bind
            // both in the WHERE clause; the writes carry both per row).
            assert!(sql.contains("org_id"), "{sql}");
            assert!(sql.contains("artifact_id"), "{sql}");
        }
        for sql in [
            DB_GET_SQL,
            DB_LIST_BASE_SQL,
            DB_UPDATE_SQL,
            DB_UPDATE_GUARDED_SQL,
            DB_DELETE_SQL,
            DB_DELETE_GUARDED_SQL,
            DB_COUNT_SQL,
        ] {
            assert!(sql.contains("org_id = ?"), "{sql}");
            assert!(sql.contains("artifact_id = ?"), "{sql}");
        }
        // The batch lookup binds both once, then one pair per document.
        assert_eq!(
            db_existing_lookup_sql(2).matches('?').count(),
            2 + 2 * 2
        );
        // The collection list orders by doc id with a bound limit.
        let sql = db_list_sql(true, true);
        assert!(sql.contains("index_value = ?") && sql.contains("doc_id > ?"));
        assert!(sql.ends_with("ORDER BY doc_id ASC LIMIT ?"));
    }

    #[test]
    fn db_carry_forward_keeps_null_and_replacement() {
        let previous = serde_json::json!({"db": {"collections": {"cards": {}}, "seed": []}});
        // An explicit null turns the endpoint off.
        let raw = serde_json::json!({"db": null});
        assert_eq!(validation::apply_db_carry_forward(&raw, &previous), raw);
        // A present object replaces, and is returned untouched.
        let raw = serde_json::json!({"db": {"collections": {"other": {}}}});
        assert_eq!(validation::apply_db_carry_forward(&raw, &previous), raw);
        // A non-object manifest passes through unchanged.
        let raw = serde_json::json!("just-a-string");
        assert_eq!(validation::apply_db_carry_forward(&raw, &previous), raw);
    }

    #[test]
    fn db_rate_limit_keys_scope_per_artifact_per_writer() {
        assert_eq!(
            db_rate_limit_key(ARTIFACT_A, "user1"),
            format!("dbratelimit:{ARTIFACT_A}:user1")
        );
        assert_ne!(
            db_rate_limit_key(ARTIFACT_A, "user1"),
            db_rate_limit_key(ARTIFACT_B, "user1")
        );
    }

    #[test]
    fn db_patch_merges_shallowly() {
        let merged = db_merge_patch(
            r#"{"a":1,"b":2}"#,
            &serde_json::json!({"b": 3, "c": null}),
        );
        let merged: Value = serde_json::from_str(&merged).expect("object");
        assert_eq!(merged, serde_json::json!({"a": 1, "b": 3, "c": null}));
        // A non-object stored body is replaced wholesale.
        let merged = db_merge_patch("[]", &serde_json::json!({"a": 1}));
        assert_eq!(
            serde_json::from_str::<Value>(&merged).expect("object"),
            serde_json::json!({"a": 1})
        );
    }

    #[test]
    fn db_bearer_tokens_outrank_cookies_for_reads() {
        // A garbage bearer fails even with a valid cookie (fail closed).
        assert_eq!(
            authorize_db(
                Sharing::Public,
                EditAccess::Edit,
                Some(OWNER),
                Some(PresentedToken::Garbage),
                Some(member_viewer()),
                false
            ),
            Err(DbDeny::Unauthorized)
        );
        // An other-artifact bearer fails on public artifacts too.
        let token_a = member_token(ARTIFACT_A);
        assert_eq!(
            authorize_db(
                Sharing::Public,
                EditAccess::Edit,
                Some(OWNER),
                Some(classify(&token_a, ARTIFACT_B)),
                Some(member_viewer()),
                false
            ),
            Err(DbDeny::Forbidden)
        );
        // A valid bearer wins over the cookie: the session mint below would
        // carry the bearer's identity, and anonymous reads need no token.
        assert!(authorize_db(Sharing::Public, EditAccess::Edit, Some(OWNER), None, None, false)
            .is_ok());
        // Anonymous on a non-public artifact is asked to authenticate.
        assert_eq!(
            authorize_db(Sharing::Team, EditAccess::Edit, Some(OWNER), None, None, false),
            Err(DbDeny::Unauthorized)
        );
        // A presented-but-insufficient credential is forbidden, not anonymous.
        assert_eq!(
            authorize_db(
                Sharing::Private,
                EditAccess::Edit,
                Some(OWNER),
                Some(classify(&member_token(ARTIFACT_B), ARTIFACT_B)),
                None,
                false
            ),
            Err(DbDeny::Forbidden)
        );
    }

    #[test]
    fn db_session_tokens_keep_the_caller_scope() {
        // Admins keep private scope so their session token still opens the
        // artifact; owners and members mint plain member tokens.
        assert!(matches!(DbRole::Admin, DbRole::Admin));
        let admin = TokenViewer {
            viewer: Some("admin1".to_string()),
            sees_private: true,
        };
        let auth = authorize_db(
            Sharing::Private,
            EditAccess::Edit,
            Some(OWNER),
            Some(PresentedToken::This(admin)),
            None,
            false,
        )
        .expect("admin reads private");
        assert_eq!(auth.role, DbRole::Admin);
        assert_eq!(auth.viewer.as_deref(), Some("admin1"));
    }

    #[test]
    fn db_legacy_access_tokens_still_verify() {
        // Three-part tokens (no viewer) verify and read public artifacts as
        // links — the page's existing `?token=` links keep working.
        let token = mint_access_token(SECRET, ARTIFACT_A, future());
        let viewer = artifact_origin::verified_token_viewer(Some(SECRET), &token, ARTIFACT_A, now())
            .expect("verifies");
        assert_eq!(viewer.viewer, None);
        assert_eq!(
            db_role_for(Sharing::Public, EditAccess::Edit, Some(OWNER), Some(&viewer)),
            Some(DbRole::Link)
        );
    }
}
