use std::{fs, io, path::PathBuf};

use anyhow::{Context, Result};

const MCP_SERVER_ENTRY: &str = r#"{
    "mcpServers": {
        "artfct": {
            "command": "artfct",
            "args": ["mcp", "serve"]
        }
    }
}"#;

pub fn setup_agents(silent: bool) -> Result<()> {
    let paths = discover_config_paths();

    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;
    let binary_path = binary.to_string_lossy();

    let mut configured = 0;
    let mut skipped = 0;

    for path in &paths {
        match install_into(path, &binary_path, silent) {
            Ok(InstallResult::Written) => {
                eprintln!("  Configured: {}", path.display());
                configured += 1;
            }
            Ok(InstallResult::AlreadyConfigured) => {
                eprintln!("  Already configured: {}", path.display());
                skipped += 1;
            }
            Ok(InstallResult::UserSkipped) => {
                eprintln!("  Skipped: {}", path.display());
                skipped += 1;
            }
            Err(err) => {
                eprintln!("  Failed: {} ({err})", path.display());
            }
        }
    }

    if configured > 0 {
        eprintln!("Done — installed into {configured} configs (skipped {skipped})");
    } else if skipped > 0 {
        eprintln!("All {skipped} configs already had artfct or were skipped");
    } else {
        eprintln!("No writable agent configs found");
    }

    eprintln!("Binary: {binary_path}\nMCP server entry: artfct mcp serve");

    Ok(())
}

pub fn list_configs() {
    eprintln!("Agent config paths that would be used:");
    for path in discover_config_paths() {
        let marker = if path.exists() { "exists" } else { "new" };
        eprintln!("  {marker:>6}  {}", path.display());
    }
    eprintln!();
    eprintln!("MCP server entry:");
    eprintln!("  {}", MCP_SERVER_ENTRY.replace('\n', "\n  ").trim_end());
}

#[derive(Debug, PartialEq, Eq)]
enum InstallResult {
    Written,
    AlreadyConfigured,
    UserSkipped,
}

fn install_into(config_path: &PathBuf, _binary_path: &str, silent: bool) -> Result<InstallResult> {
    if config_path.exists() {
        let content = fs::read_to_string(config_path)
            .with_context(|| format!("Failed to read {}", config_path.display()))?;

        let mut parsed: serde_json::Value = if content.trim().is_empty() {
            serde_json::Value::Object(serde_json::Map::new())
        } else {
            serde_json::from_str(&content)
                .with_context(|| format!("Invalid JSON in {}", config_path.display()))?
        };

        let servers = parsed
            .as_object_mut()
            .and_then(|obj| obj.get_mut("mcpServers"))
            .and_then(|s| s.as_object_mut());

        if let Some(servers) = servers {
            if servers.contains_key("artfct") {
                return Ok(InstallResult::AlreadyConfigured);
            }
            let entry: serde_json::Value = serde_json::json!({
                "command": "artfct",
                "args": ["mcp", "serve"]
            });
            servers.insert("artfct".to_string(), entry);
        } else {
            let entry = serde_json::json!({
                "artfct": {
                    "command": "artfct",
                    "args": ["mcp", "serve"]
                }
            });
            if let Some(obj) = parsed.as_object_mut() {
                obj.insert("mcpServers".to_string(), entry);
            }
        }

        if !silent {
            if !confirm_overwrite(config_path)? {
                return Ok(InstallResult::UserSkipped);
            }
        }

        let formatted = serde_json::to_string_pretty(&parsed)?;
        fs::write(config_path, formatted)
            .with_context(|| format!("Failed to write {}", config_path.display()))?;
    } else {
        if !silent {
            if !confirm_create(config_path)? {
                return Ok(InstallResult::UserSkipped);
            }
        }

        if let Some(parent) = config_path.parent() {
            fs::create_dir_all(parent)
                .with_context(|| format!("Failed to create directory {}", parent.display()))?;
        }

        fs::write(config_path, MCP_SERVER_ENTRY)
            .with_context(|| format!("Failed to write {}", config_path.display()))?;
    }

    Ok(InstallResult::Written)
}

fn confirm_overwrite(path: &PathBuf) -> Result<bool> {
    eprint!("Add artfct to {}? [Y/n] ", path.display());
    read_yn()
}

fn confirm_create(path: &PathBuf) -> Result<bool> {
    eprint!("Create {} with artfct MCP server? [Y/n] ", path.display());
    read_yn()
}

fn read_yn() -> Result<bool> {
    let mut input = String::new();
    io::stdin()
        .read_line(&mut input)
        .context("Failed to read input")?;

    let trimmed = input.trim().to_lowercase();
    Ok(trimmed.is_empty() || trimmed == "y" || trimmed == "yes")
}

fn discover_config_paths() -> Vec<PathBuf> {
    let home = dirs_home();
    let mut paths = Vec::new();

    if let Some(ref home) = home {
        paths.push(home.join(".mcp.json"));
        paths.push(home.join(".cursor").join("mcp.json"));
        paths.push(home.join(".gemini").join("mcp.json"));
        paths.push(home.join(".codex").join("mcp.json"));
    }

    if let Ok(cwd) = std::env::current_dir() {
        paths.push(cwd.join(".mcp.json"));
    }

    paths
}

fn dirs_home() -> Option<PathBuf> {
    std::env::var("HOME").ok().map(PathBuf::from)
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::{discover_config_paths, install_into, InstallResult, MCP_SERVER_ENTRY};

    #[test]
    fn discovers_known_paths() {
        let paths = discover_config_paths();
        assert!(!paths.is_empty());
    }

    #[test]
    fn creates_new_config() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("new.mcp.json");

        let result = install_into(&path, "", true).expect("install_into");
        assert_eq!(result, InstallResult::Written);

        let content = fs::read_to_string(&path).expect("read");
        let parsed: serde_json::Value = serde_json::from_str(&content).expect("json");
        assert_eq!(parsed["mcpServers"]["artfct"]["command"], "artfct");
        let args = parsed["mcpServers"]["artfct"]["args"]
            .as_array()
            .expect("array");
        assert_eq!(args[0], "mcp");
        assert_eq!(args[1], "serve");
    }

    #[test]
    fn skips_already_configured() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("exists.mcp.json");

        fs::write(&path, MCP_SERVER_ENTRY).expect("write");
        let result = install_into(&path, "", true).expect("install_into");
        assert_eq!(result, InstallResult::AlreadyConfigured);
    }

    #[test]
    fn merges_into_existing_with_other_servers() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("merge.mcp.json");

        let existing = r#"{"mcpServers": {"other": {"command": "echo"}}}"#;
        fs::write(&path, existing).expect("write");

        let result = install_into(&path, "", true).expect("install_into");
        assert_eq!(result, InstallResult::Written);

        let content = fs::read_to_string(&path).expect("read");
        let parsed: serde_json::Value = serde_json::from_str(&content).expect("json");
        let servers = parsed["mcpServers"].as_object().expect("object");
        assert!(servers.contains_key("other"));
        assert!(servers.contains_key("artfct"));
    }

    #[test]
    fn adds_mcp_servers_when_missing() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("no_servers.mcp.json");

        let existing = r#"{"someKey": "value"}"#;
        fs::write(&path, existing).expect("write");

        let result = install_into(&path, "", true).expect("install_into");
        assert_eq!(result, InstallResult::Written);

        let content = fs::read_to_string(&path).expect("read");
        let parsed: serde_json::Value = serde_json::from_str(&content).expect("json");
        assert_eq!(parsed["someKey"], "value");
        assert!(parsed["mcpServers"]["artfct"]["command"] == "artfct");
    }
}
