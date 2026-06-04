use std::path::PathBuf;
use std::{fs, io};

use anyhow::{Context, Result};

pub fn uninstall(silent: bool) -> Result<()> {
    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;
    let binary_path = binary.to_string_lossy();

    let paths = discover_config_paths();
    let mut removed_from = 0;
    let mut not_found = 0;

    for path in &paths {
        if !path.exists() {
            continue;
        }

        match remove_artfct_entry(path) {
            Ok(true) => {
                eprintln!("  Removed artfct from: {}", path.display());
                removed_from += 1;
            }
            Ok(false) => {
                not_found += 1;
            }
            Err(err) => {
                eprintln!("  Failed: {} ({err})", path.display());
            }
        }
    }

    if removed_from > 0 {
        eprintln!(
            "Removed MCP entries from {removed_from} configs{}",
            if not_found > 0 {
                format!(" ({not_found} already clean)")
            } else {
                String::new()
            }
        );
    } else {
        eprintln!("No artfct MCP entries found in agent configs");
    }

    let should_remove_binary = if silent {
        true
    } else {
        eprint!("Remove binary at {}? [Y/n] ", binary_path);
        read_yn()?
    };

    if should_remove_binary {
        fs::remove_file(&*binary)
            .with_context(|| format!("Failed to remove binary at {binary_path}"))?;
        eprintln!("Removed binary: {binary_path}");
    } else {
        eprintln!("Binary left at: {binary_path}");
    }

    Ok(())
}

fn remove_artfct_entry(config_path: &PathBuf) -> Result<bool> {
    let content = fs::read_to_string(config_path)
        .with_context(|| format!("Failed to read {}", config_path.display()))?;

    let mut parsed: serde_json::Value = if content.trim().is_empty() {
        return Ok(false);
    } else {
        serde_json::from_str(&content)
            .with_context(|| format!("Invalid JSON in {}", config_path.display()))?
    };

    let removed = if let Some(servers) = parsed
        .as_object_mut()
        .and_then(|obj| obj.get_mut("mcpServers"))
        .and_then(|s| s.as_object_mut())
    {
        let had_artfct = servers.remove("artfct").is_some();

        if servers.is_empty() {
            if let Some(obj) = parsed.as_object_mut() {
                obj.remove("mcpServers");
            }
        }

        had_artfct
    } else {
        false
    };

    if removed {
        if parsed.as_object().map_or(true, |o| o.is_empty()) {
            fs::remove_file(config_path).with_context(|| {
                format!("Failed to remove empty config {}", config_path.display())
            })?;
        } else {
            let formatted = serde_json::to_string_pretty(&parsed)?;
            fs::write(config_path, formatted)
                .with_context(|| format!("Failed to write {}", config_path.display()))?;
        }
    }

    Ok(removed)
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
    let home = std::env::var("HOME").ok().map(PathBuf::from);
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

#[cfg(test)]
mod tests {
    use std::fs;

    use super::remove_artfct_entry;

    #[test]
    fn removes_artfct_from_config() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("test.mcp.json");

        let config =
            r#"{"mcpServers": {"artfct": {"command": "artfct"}, "other": {"command": "echo"}}}"#;
        fs::write(&path, config).expect("write");

        let result = remove_artfct_entry(&path).expect("remove");
        assert!(result);

        let content = fs::read_to_string(&path).expect("read");
        assert!(!content.contains("artfct"));
        assert!(content.contains("other"));
    }

    #[test]
    fn removes_file_when_only_artfct() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("only.mcp.json");

        let config = r#"{"mcpServers": {"artfct": {"command": "artfct"}}}"#;
        fs::write(&path, config).expect("write");

        let result = remove_artfct_entry(&path).expect("remove");
        assert!(result);
        assert!(!path.exists());
    }

    #[test]
    fn noop_when_no_artfct() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("no_artfct.mcp.json");

        let config = r#"{"mcpServers": {"other": {"command": "echo"}}}"#;
        fs::write(&path, config).expect("write");

        let result = remove_artfct_entry(&path).expect("remove");
        assert!(!result);
    }
}
