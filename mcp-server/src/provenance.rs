use std::{path::Path, process::Command};

use serde::Serialize;

/// Records where a provenance value came from.
#[derive(Clone, Copy, Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum ProvenanceSource {
    Config,
    ClientInfo,
    Process,
    Env,
    SelfReported,
    Absent,
}

/// Provenance attached to every artifact create request.
#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct Provenance {
    pub agent: Option<String>,
    pub agent_raw: Option<String>,
    pub agent_version: Option<String>,
    pub model: Option<String>,
    pub session_id: Option<String>,
    pub tool: Option<String>,
    pub repo_url: Option<String>,
    pub branch: Option<String>,
    pub commit_sha: Option<String>,
    pub dirty: Option<bool>,
    pub source_path: Option<String>,
    pub client: String,
    pub client_version: String,
    pub sources: ProvenanceSources,
}

/// Per-field provenance sources for nullable provenance values.
#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct ProvenanceSources {
    pub agent: ProvenanceSource,
    pub agent_raw: ProvenanceSource,
    pub agent_version: ProvenanceSource,
    pub model: ProvenanceSource,
    pub session_id: ProvenanceSource,
    pub tool: ProvenanceSource,
    pub repo_url: ProvenanceSource,
    pub branch: ProvenanceSource,
    pub commit_sha: ProvenanceSource,
    pub dirty: ProvenanceSource,
    pub source_path: ProvenanceSource,
}

/// Identity supplied by an MCP session when building provenance.
pub struct McpProvenanceInput {
    pub agent: Option<String>,
    pub agent_raw: Option<String>,
    pub agent_version: Option<String>,
    pub agent_source: ProvenanceSource,
    pub session_id: String,
    pub model: Option<String>,
}

/// Build CLI provenance from the process working directory and input file.
pub fn build_cli_provenance(cwd: &Path, source_path: Option<&Path>) -> Provenance {
    let git = discover_git_provenance(cwd);

    Provenance {
        agent: Some("artfct-cli".to_string()),
        agent_raw: None,
        agent_version: Some(env!("CARGO_PKG_VERSION").to_string()),
        model: None,
        session_id: None,
        tool: Some("cli".to_string()),
        repo_url: git.repo_url,
        branch: git.branch,
        commit_sha: git.commit_sha,
        dirty: git.dirty,
        source_path: source_path.map(|path| path.display().to_string()),
        client: "artfct-cli".to_string(),
        client_version: env!("CARGO_PKG_VERSION").to_string(),
        sources: ProvenanceSources {
            agent: ProvenanceSource::Config,
            agent_raw: ProvenanceSource::Absent,
            agent_version: ProvenanceSource::Config,
            model: ProvenanceSource::Absent,
            session_id: ProvenanceSource::Absent,
            tool: ProvenanceSource::Config,
            repo_url: git.repo_url_source,
            branch: git.branch_source,
            commit_sha: git.commit_sha_source,
            dirty: git.dirty_source,
            source_path: source_path
                .map(|_| ProvenanceSource::Config)
                .unwrap_or(ProvenanceSource::Absent),
        },
    }
}

/// Build MCP provenance from session identity and process-observed Git data.
pub fn build_mcp_provenance(cwd: &Path, input: McpProvenanceInput) -> Provenance {
    let git = discover_git_provenance(cwd);
    let agent_source = source_for_value(&input.agent, input.agent_source);
    let agent_raw_source = source_for_value(&input.agent_raw, input.agent_source);
    let agent_version_source = source_for_value(&input.agent_version, ProvenanceSource::ClientInfo);
    let model_source = input
        .model
        .as_ref()
        .map(|_| ProvenanceSource::SelfReported)
        .unwrap_or(ProvenanceSource::Absent);

    Provenance {
        agent: input.agent,
        agent_raw: input.agent_raw,
        agent_version: input.agent_version,
        model: input.model,
        session_id: Some(input.session_id),
        tool: Some("deploy_to_canvas".to_string()),
        repo_url: git.repo_url,
        branch: git.branch,
        commit_sha: git.commit_sha,
        dirty: git.dirty,
        source_path: None,
        client: "artfct-cli".to_string(),
        client_version: env!("CARGO_PKG_VERSION").to_string(),
        sources: ProvenanceSources {
            agent: agent_source,
            agent_raw: agent_raw_source,
            agent_version: agent_version_source,
            model: model_source,
            session_id: ProvenanceSource::Process,
            tool: ProvenanceSource::Config,
            repo_url: git.repo_url_source,
            branch: git.branch_source,
            commit_sha: git.commit_sha_source,
            dirty: git.dirty_source,
            source_path: ProvenanceSource::Absent,
        },
    }
}

fn source_for_value<T>(value: &Option<T>, source: ProvenanceSource) -> ProvenanceSource {
    if value.is_some() {
        source
    } else {
        ProvenanceSource::Absent
    }
}

struct GitProvenance {
    repo_url: Option<String>,
    repo_url_source: ProvenanceSource,
    branch: Option<String>,
    branch_source: ProvenanceSource,
    commit_sha: Option<String>,
    commit_sha_source: ProvenanceSource,
    dirty: Option<bool>,
    dirty_source: ProvenanceSource,
}

fn discover_git_provenance(cwd: &Path) -> GitProvenance {
    let (repo_url, repo_url_source) = git_output(cwd, &["remote", "get-url", "origin"])
        .map(|remote| scrub_remote_url(&remote))
        .map_or((None, ProvenanceSource::Absent), |remote| {
            (Some(remote), ProvenanceSource::Process)
        });
    let (branch, branch_source) = git_output(cwd, &["rev-parse", "--abbrev-ref", "HEAD"])
        .map_or((None, ProvenanceSource::Absent), |branch| {
            (Some(branch), ProvenanceSource::Process)
        });
    let (commit_sha, commit_sha_source) = git_output(cwd, &["rev-parse", "HEAD"])
        .map_or((None, ProvenanceSource::Absent), |commit_sha| {
            (Some(commit_sha), ProvenanceSource::Process)
        });
    let (dirty, dirty_source) = git_status(cwd).map_or((None, ProvenanceSource::Absent), |dirty| {
        (Some(dirty), ProvenanceSource::Process)
    });

    // Running this command is intentional even when the individual metadata
    // commands above fail: each Git field has an independent failure source.
    let _ = git_output(cwd, &["rev-parse", "--show-toplevel"]);

    GitProvenance {
        repo_url,
        repo_url_source,
        branch,
        branch_source,
        commit_sha,
        commit_sha_source,
        dirty,
        dirty_source,
    }
}

fn git_output(cwd: &Path, args: &[&str]) -> Option<String> {
    let output = Command::new("git")
        .args(args)
        .current_dir(cwd)
        .output()
        .ok()?;

    if !output.status.success() {
        return None;
    }

    let value = String::from_utf8(output.stdout).ok()?.trim().to_string();
    (!value.is_empty()).then_some(value)
}

fn git_status(cwd: &Path) -> Option<bool> {
    let output = Command::new("git")
        .args(["status", "--porcelain"])
        .current_dir(cwd)
        .output()
        .ok()?;

    if !output.status.success() {
        return None;
    }

    Some(!String::from_utf8(output.stdout).ok()?.is_empty())
}

fn scrub_remote_url(remote: &str) -> String {
    let remote = remote.trim();
    let Some(scheme_end) = remote.find("://") else {
        return remote.to_string();
    };

    let authority_start = scheme_end + 3;
    let authority_end = remote[authority_start..]
        .find(['/', '?', '#'])
        .map(|offset| authority_start + offset)
        .unwrap_or(remote.len());
    let authority = &remote[authority_start..authority_end];
    let Some(at) = authority.rfind('@') else {
        return remote.to_string();
    };

    format!(
        "{}://{}{}",
        &remote[..scheme_end],
        &authority[at + 1..],
        &remote[authority_end..]
    )
}

#[cfg(test)]
mod tests {
    use std::{fs, path::Path, process::Command};

    use serde_json::Value;
    use tempfile::TempDir;

    use super::{
        build_cli_provenance, build_mcp_provenance, scrub_remote_url, McpProvenanceInput,
        ProvenanceSource,
    };

    fn git(repo: &Path, args: &[&str]) {
        let status = Command::new("git")
            .args(args)
            .current_dir(repo)
            .status()
            .expect("git should be installed");
        assert!(status.success(), "git command failed: git {args:?}");
    }

    fn clean_repo() -> TempDir {
        let dir = tempfile::tempdir().expect("temporary directory should be created");
        git(dir.path(), &["init", "--quiet"]);
        git(dir.path(), &["config", "user.name", "Test User"]);
        git(dir.path(), &["config", "user.email", "test@example.com"]);
        fs::write(dir.path().join("x.html"), "<h1>hello</h1>").expect("file should be written");
        git(dir.path(), &["add", "x.html"]);
        git(dir.path(), &["commit", "--quiet", "-m", "initial"]);
        git(
            dir.path(),
            &["remote", "add", "origin", "https://github.com/o/r.git"],
        );
        dir
    }

    #[test]
    fn git_provenance_from_clean_repo() {
        let dir = clean_repo();
        let provenance = build_cli_provenance(dir.path(), Some(Path::new("x.html")));

        assert_eq!(
            provenance.repo_url.as_deref(),
            Some("https://github.com/o/r.git")
        );
        assert!(provenance.branch.is_some());
        assert!(provenance.commit_sha.is_some());
        assert_eq!(provenance.dirty, Some(false));
        assert_eq!(provenance.sources.repo_url, ProvenanceSource::Process);
        assert_eq!(provenance.sources.branch, ProvenanceSource::Process);
        assert_eq!(provenance.sources.commit_sha, ProvenanceSource::Process);
        assert_eq!(provenance.sources.dirty, ProvenanceSource::Process);
    }

    #[test]
    fn git_provenance_marks_dirty_worktree() {
        let dir = clean_repo();
        fs::write(dir.path().join("x.html"), "changed").expect("file should be changed");

        let provenance = build_cli_provenance(dir.path(), None);

        assert_eq!(provenance.dirty, Some(true));
        assert_eq!(provenance.sources.dirty, ProvenanceSource::Process);
    }

    #[test]
    fn git_provenance_absent_outside_repo() {
        let dir = tempfile::tempdir().expect("temporary directory should be created");
        let provenance = build_cli_provenance(dir.path(), None);

        assert_eq!(provenance.repo_url, None);
        assert_eq!(provenance.branch, None);
        assert_eq!(provenance.commit_sha, None);
        assert_eq!(provenance.dirty, None);
        assert_eq!(provenance.sources.repo_url, ProvenanceSource::Absent);
        assert_eq!(provenance.sources.branch, ProvenanceSource::Absent);
        assert_eq!(provenance.sources.commit_sha, ProvenanceSource::Absent);
        assert_eq!(provenance.sources.dirty, ProvenanceSource::Absent);
    }

    #[test]
    fn remote_url_credentials_are_scrubbed() {
        let dir = clean_repo();
        git(
            dir.path(),
            &[
                "remote",
                "set-url",
                "origin",
                "https://user:token@github.com/o/r.git",
            ],
        );
        let provenance = build_cli_provenance(dir.path(), None);

        assert_eq!(
            provenance.repo_url.as_deref(),
            Some("https://github.com/o/r.git")
        );
        assert_eq!(provenance.sources.repo_url, ProvenanceSource::Process);

        git(
            dir.path(),
            &[
                "remote",
                "set-url",
                "origin",
                "ssh://user:token@github.com/o/r.git",
            ],
        );
        let provenance = build_cli_provenance(dir.path(), None);

        assert_eq!(
            provenance.repo_url.as_deref(),
            Some("ssh://github.com/o/r.git")
        );
        assert_eq!(provenance.sources.repo_url, ProvenanceSource::Process);

        assert_eq!(
            scrub_remote_url("https://user:token@github.com/o/r.git"),
            "https://github.com/o/r.git"
        );
        assert_eq!(
            scrub_remote_url("ssh://user:token@github.com/o/r.git"),
            "ssh://github.com/o/r.git"
        );
        assert_eq!(
            scrub_remote_url("git@github.com:o/r.git"),
            "git@github.com:o/r.git"
        );
        assert_eq!(
            scrub_remote_url("https://github.com/o/r.git"),
            "https://github.com/o/r.git"
        );
    }

    #[test]
    fn cli_builder_omits_session_id() {
        let provenance = build_cli_provenance(Path::new("/tmp"), None);

        assert_eq!(provenance.session_id, None);
        assert_eq!(provenance.sources.session_id, ProvenanceSource::Absent);
    }

    #[test]
    fn every_nullable_field_has_a_source_entry() {
        let provenance = build_cli_provenance(Path::new("/tmp"), None);
        let value = serde_json::to_value(provenance).expect("provenance should serialize");
        let fields = [
            "agent",
            "agent_raw",
            "agent_version",
            "model",
            "session_id",
            "tool",
            "repo_url",
            "branch",
            "commit_sha",
            "dirty",
            "source_path",
        ];
        let allowed_sources = [
            "config",
            "client_info",
            "process",
            "env",
            "self_reported",
            "absent",
        ];
        let object = value.as_object().expect("provenance should be an object");
        let sources = value["sources"]
            .as_object()
            .expect("provenance sources should be an object");
        assert_eq!(sources.len(), fields.len());

        for field in fields {
            let field_value = object.get(field).expect("nullable field should be present");
            let source = sources
                .get(field)
                .and_then(Value::as_str)
                .expect("field source should be a string");

            assert!(
                allowed_sources.contains(&source),
                "invalid source for {field}"
            );
            if field_value.is_null() {
                assert_eq!(source, "absent", "null field {field} must be absent");
            } else {
                assert_ne!(
                    source, "absent",
                    "non-null field {field} must have a source"
                );
            }
        }
    }

    #[test]
    fn provenance_serializes_to_contract_shape() {
        let provenance = build_mcp_provenance(
            Path::new("/tmp"),
            McpProvenanceInput {
                agent: Some("cursor".to_string()),
                agent_raw: Some("Cursor".to_string()),
                agent_version: Some("1.0".to_string()),
                agent_source: ProvenanceSource::ClientInfo,
                session_id: "session".to_string(),
                model: Some("claude-opus-5".to_string()),
            },
        );
        let value: Value = serde_json::to_value(provenance).expect("provenance should serialize");
        let object = value.as_object().expect("provenance should be an object");

        for field in [
            "agent",
            "agent_raw",
            "agent_version",
            "model",
            "session_id",
            "tool",
            "repo_url",
            "branch",
            "commit_sha",
            "dirty",
            "source_path",
            "client",
            "client_version",
            "sources",
        ] {
            assert!(object.contains_key(field), "missing contract field {field}");
        }
        assert_eq!(value["tool"], "deploy_to_canvas");
        assert_eq!(value["sources"]["model"], "self_reported");
        crate::api::tests::validate_contract_schema(&value, "Provenance")
            .expect("provenance matches the contract schema");
    }
}
