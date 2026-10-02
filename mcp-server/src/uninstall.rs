use std::{fs, path::Path};

use anyhow::{Context, Result};
use dialoguer::{theme::ColorfulTheme, Confirm};

use crate::{setup, ui};

pub fn uninstall(silent: bool) -> Result<()> {
    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;
    let agents = setup::discover_agents();

    uninstall_with(&agents, &binary, silent).map(|_| ())
}

/// Remove MCP entries and the supplied binary using the same orchestration as
/// the public uninstall command. The return value is the number of entries
/// removed from agent configs.
pub fn uninstall_with(agents: &[setup::AgentConfig], binary: &Path, silent: bool) -> Result<usize> {
    let binary_path = binary.to_string_lossy();

    ui::header("Uninstalling artfct");
    eprintln!();

    let mut removed_from = 0;
    let mut not_found = 0;

    for agent in agents {
        if !agent.path.exists() {
            continue;
        }

        match setup::remove_artfct_entry(&agent.path, &agent.format) {
            Ok(true) => {
                ui::item_success(format!("Removed from {}", agent.name));
                removed_from += 1;
            }
            Ok(false) => {
                ui::item_skip(format!("Not present in {}", agent.name));
                not_found += 1;
            }
            Err(err) => {
                ui::item_error(format!("Failed {} — {err}", agent.name));
            }
        }
    }

    eprintln!();

    if removed_from > 0 {
        ui::success(format!(
            "Removed MCP entries from {removed_from} config{}{}",
            if removed_from == 1 { "" } else { "s" },
            if not_found > 0 {
                format!(", {not_found} already clean")
            } else {
                String::new()
            }
        ));
    } else {
        eprintln!("No artfct MCP entries found in agent configs");
    }

    eprintln!();

    let should_remove_binary = if silent {
        true
    } else {
        Confirm::with_theme(&ColorfulTheme::default())
            .with_prompt(format!("Remove binary at {binary_path}?"))
            .default(true)
            .interact()?
    };

    eprintln!();

    if should_remove_binary {
        fs::remove_file(binary)
            .with_context(|| format!("Failed to remove binary at {binary_path}"))?;
        ui::success(format!("Removed binary at {binary_path}"));
    } else {
        ui::item_skip(format!("Binary left at {binary_path}"));
    }

    eprintln!();

    Ok(removed_from)
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::uninstall_with;
    use crate::setup::{install_into_with_host, AgentConfig, ConfigFormat};

    #[test]
    fn uninstall_removes_entry_with_host_flag() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let binary = tmp.path().join("artfct");
        fs::write(&binary, "test binary").expect("create temp binary");

        let agents = vec![
            AgentConfig {
                name: "Claude Code",
                host: Some("claude-code"),
                path: tmp.path().join("claude-code.config"),
                format: ConfigFormat::JsonMcpServers,
            },
            AgentConfig {
                name: "Cursor",
                host: Some("cursor"),
                path: tmp.path().join("cursor.config"),
                format: ConfigFormat::JsonMcpServers,
            },
            AgentConfig {
                name: "Gemini",
                host: Some("gemini"),
                path: tmp.path().join("gemini.config"),
                format: ConfigFormat::JsonMcpServers,
            },
            AgentConfig {
                name: "Codex",
                host: Some("codex"),
                path: tmp.path().join("codex.config"),
                format: ConfigFormat::Toml,
            },
            AgentConfig {
                name: "OpenCode",
                host: Some("opencode"),
                path: tmp.path().join("opencode.config"),
                format: ConfigFormat::JsonOpenCode,
            },
        ];

        for agent in &agents {
            install_into_with_host(
                &agent.path,
                binary.to_str().expect("binary path is utf-8"),
                &agent.format,
                agent.host,
            )
            .expect("install host-bearing config");
        }

        assert_eq!(
            uninstall_with(&agents, &binary, true).expect("uninstall temp configs"),
            5
        );
        assert!(!binary.exists());
        for agent in &agents {
            if agent.path.exists() {
                let content = fs::read_to_string(&agent.path).expect("read remaining config");
                assert!(
                    !content.contains("artfct"),
                    "{} entry still exists",
                    agent.name
                );
            }
        }
    }
}
