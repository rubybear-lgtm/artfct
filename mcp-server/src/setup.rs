use std::{fs, path::PathBuf};

use anyhow::{Context, Result};
use console::Term;
use dialoguer::{theme::ColorfulTheme, MultiSelect};

use crate::ui;

pub struct AgentConfig {
    pub name: &'static str,
    pub path: PathBuf,
}

pub fn discover_agents() -> Vec<AgentConfig> {
    let home = dirs_home();
    let mut agents = Vec::new();

    if let Some(ref home) = home {
        agents.push(AgentConfig {
            name: "Claude Code",
            path: home.join(".mcp.json"),
        });
        agents.push(AgentConfig {
            name: "Cursor",
            path: home.join(".cursor").join("mcp.json"),
        });
        agents.push(AgentConfig {
            name: "Gemini",
            path: home.join(".gemini").join("mcp.json"),
        });
        agents.push(AgentConfig {
            name: "Codex",
            path: home.join(".codex").join("mcp.json"),
        });
    }

    if let Ok(cwd) = std::env::current_dir() {
        agents.push(AgentConfig {
            name: "Current project",
            path: cwd.join(".mcp.json"),
        });
    }

    agents
}

pub fn setup_agents(silent: bool) -> Result<()> {
    let agents = discover_agents();

    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;
    let binary_path = binary.to_string_lossy();

    ui::banner();

    let selected = if silent {
        (0..agents.len()).collect()
    } else {
        let items: Vec<&str> = agents.iter().map(|a| a.name).collect();
        let defaults: Vec<bool> = vec![true; items.len()];

        let selected = MultiSelect::with_theme(&ColorfulTheme::default())
            .with_prompt("Select agents to configure")
            .items(&items)
            .defaults(&defaults)
            .interact()
            .context("Failed to show selection")?;

        let term = Term::stderr();
        let _ = term.clear_last_lines(1);
        for (i, agent) in agents.iter().enumerate() {
            if selected.contains(&i) {
                ui::item_success(agent.name);
            } else {
                ui::item_skip(agent.name);
            }
        }

        selected
    };

    eprintln!();

    let mut configured = 0;
    let mut skipped = 0;

    for (i, agent) in agents.iter().enumerate() {
        if !selected.contains(&i) {
            skipped += 1;
            continue;
        }

        match install_into(&agent.path, &binary_path) {
            Ok(InstallResult::Written) => {
                ui::item_success(format!("Configured {}", agent.name));
                configured += 1;
            }
            Ok(InstallResult::AlreadyConfigured) => {
                ui::item_skip(format!("Already configured {}", agent.name));
                skipped += 1;
            }
            Err(err) => {
                ui::item_error(format!("Failed {} — {err}", agent.name));
            }
        }
    }

    eprintln!();

    if configured > 0 {
        ui::success(format!(
            "Done — {configured} agent{} configured{}",
            if configured == 1 { "" } else { "s" },
            if skipped > 0 {
                format!(", {skipped} skipped")
            } else {
                String::new()
            }
        ));
    } else if skipped > 0 {
        ui::success("Already up to date");
    } else {
        eprintln!("No agents selected");
    }

    eprintln!();
    ui::label_value("Binary", &binary_path);
    ui::label_value("MCP command", "artfct mcp serve");
    eprintln!();

    if !binary_on_path() {
        let parent = binary
            .parent()
            .map(|p| p.display().to_string())
            .unwrap_or_default();
        ui::warn(format!(
            "artfct is not on your PATH — add {parent} to PATH to use it in the terminal"
        ));
        eprintln!();
    }

    Ok(())
}

pub fn list_configs() {
    let binary_path = std::env::current_exe()
        .ok()
        .map(|p| p.to_string_lossy().into_owned())
        .unwrap_or_else(|| "artfct".to_string());

    ui::banner();
    ui::header("Agent config paths");
    eprintln!();
    for agent in discover_agents() {
        let marker = if agent.path.exists() {
            "exists"
        } else {
            "   new"
        };
        eprintln!("  {marker}  {:<16}  {}", agent.name, agent.path.display());
    }
    eprintln!();
    ui::header("MCP server entry");
    eprintln!();
    let entry = mcp_entry_json(&binary_path);
    for line in serde_json::to_string_pretty(&entry)
        .unwrap_or_default()
        .lines()
    {
        eprintln!("  {line}");
    }
    eprintln!();
}

fn mcp_entry_json(binary_path: &str) -> serde_json::Value {
    serde_json::json!({
        "mcpServers": {
            "artfct": {
                "command": binary_path,
                "args": ["mcp", "serve"]
            }
        }
    })
}

fn binary_on_path() -> bool {
    std::env::var("PATH")
        .unwrap_or_default()
        .split(':')
        .map(std::path::Path::new)
        .any(|dir| dir.join("artfct").exists())
}

#[derive(Debug, PartialEq, Eq)]
enum InstallResult {
    Written,
    AlreadyConfigured,
}

fn install_into(config_path: &PathBuf, binary_path: &str) -> Result<InstallResult> {
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
            servers.insert(
                "artfct".to_string(),
                serde_json::json!({
                    "command": binary_path,
                    "args": ["mcp", "serve"]
                }),
            );
        } else {
            if let Some(obj) = parsed.as_object_mut() {
                obj.insert(
                    "mcpServers".to_string(),
                    serde_json::json!({
                        "artfct": {
                            "command": binary_path,
                            "args": ["mcp", "serve"]
                        }
                    }),
                );
            }
        }

        let formatted = serde_json::to_string_pretty(&parsed)?;
        fs::write(config_path, formatted)
            .with_context(|| format!("Failed to write {}", config_path.display()))?;
    } else {
        if let Some(parent) = config_path.parent() {
            fs::create_dir_all(parent)
                .with_context(|| format!("Failed to create directory {}", parent.display()))?;
        }

        let entry = mcp_entry_json(binary_path);
        let formatted = serde_json::to_string_pretty(&entry)?;
        fs::write(config_path, formatted)
            .with_context(|| format!("Failed to write {}", config_path.display()))?;
    }

    Ok(InstallResult::Written)
}

fn dirs_home() -> Option<PathBuf> {
    std::env::var("HOME").ok().map(PathBuf::from)
}

#[cfg(test)]
mod tests {
    use std::fs;

    const MCP_SERVER_ENTRY_TEMPLATE: &str = r#"{
    "mcpServers": {
        "artfct": {
            "command": "artfct",
            "args": ["mcp", "serve"]
        }
    }
}"#;

    use super::{discover_agents, install_into, InstallResult};

    #[test]
    fn discovers_known_agents() {
        let agents = discover_agents();
        assert!(!agents.is_empty());
    }

    #[test]
    fn creates_new_config_with_binary_path() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("new.mcp.json");

        let result = install_into(&path, "/usr/local/bin/artfct").expect("install_into");
        assert_eq!(result, InstallResult::Written);

        let content = fs::read_to_string(&path).expect("read");
        let parsed: serde_json::Value = serde_json::from_str(&content).expect("json");
        assert_eq!(
            parsed["mcpServers"]["artfct"]["command"],
            "/usr/local/bin/artfct"
        );
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

        fs::write(&path, MCP_SERVER_ENTRY_TEMPLATE).expect("write");
        let result = install_into(&path, "/usr/local/bin/artfct").expect("install_into");
        assert_eq!(result, InstallResult::AlreadyConfigured);
    }

    #[test]
    fn merges_into_existing_with_other_servers() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("merge.mcp.json");

        let existing = r#"{"mcpServers": {"other": {"command": "echo"}}}"#;
        fs::write(&path, existing).expect("write");

        let result = install_into(&path, "/usr/local/bin/artfct").expect("install_into");
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

        let result = install_into(&path, "/usr/local/bin/artfct").expect("install_into");
        assert_eq!(result, InstallResult::Written);

        let content = fs::read_to_string(&path).expect("read");
        let parsed: serde_json::Value = serde_json::from_str(&content).expect("json");
        assert_eq!(parsed["someKey"], "value");
        assert_eq!(
            parsed["mcpServers"]["artfct"]["command"],
            "/usr/local/bin/artfct"
        );
    }
}
