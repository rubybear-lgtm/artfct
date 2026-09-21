use std::{
    fs,
    path::{Path, PathBuf},
};

use anyhow::{anyhow, Context, Result};
use console::Term;
use dialoguer::{theme::ColorfulTheme, MultiSelect};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::ui;

#[derive(Debug)]
pub enum ConfigFormat {
    /// Standard JSON: {"mcpServers": {"artfct": {"command": "...", "args": [...]}}}
    JsonMcpServers,
    /// OpenCode JSON: {"mcp": {"artfct": {"type": "local", "enabled": true, "command": [...]}}}
    JsonOpenCode,
    /// Codex TOML: [mcp_servers.artfct]\ncommand = "..."\nargs = [...]
    Toml,
}

pub struct AgentConfig {
    pub name: &'static str,
    /// The normalized host to pass to the MCP server for this named config.
    /// Project-local config has no known host and therefore leaves this unset.
    pub host: Option<&'static str>,
    pub path: PathBuf,
    pub format: ConfigFormat,
}

pub fn discover_agents() -> Vec<AgentConfig> {
    let home = dirs_home();
    let mut agents = Vec::new();

    if let Some(ref home) = home {
        agents.push(AgentConfig {
            name: "Claude Code",
            host: Some("claude-code"),
            path: home.join(".mcp.json"),
            format: ConfigFormat::JsonMcpServers,
        });
        agents.push(AgentConfig {
            name: "Cursor",
            host: Some("cursor"),
            path: home.join(".cursor").join("mcp.json"),
            format: ConfigFormat::JsonMcpServers,
        });
        agents.push(AgentConfig {
            name: "Gemini",
            host: Some("gemini"),
            path: home.join(".gemini").join("settings.json"),
            format: ConfigFormat::JsonMcpServers,
        });
        agents.push(AgentConfig {
            name: "Codex",
            host: Some("codex"),
            path: home.join(".codex").join("config.toml"),
            format: ConfigFormat::Toml,
        });
        agents.push(AgentConfig {
            name: "OpenCode",
            host: Some("opencode"),
            path: home.join(".config").join("opencode").join("opencode.json"),
            format: ConfigFormat::JsonOpenCode,
        });
    }

    if let Ok(cwd) = std::env::current_dir() {
        agents.push(AgentConfig {
            name: "Current project",
            host: None,
            path: cwd.join(".mcp.json"),
            format: ConfigFormat::JsonMcpServers,
        });
    }

    agents
}

pub fn setup_agents(silent: bool) -> Result<()> {
    let agents = discover_agents();

    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;

    setup_agents_with(&agents, &binary, silent).map(|_| ())
}

/// Configure the supplied agent configs using the same orchestration as the
/// public setup command. The return value is the number of entries written.
pub fn setup_agents_with(agents: &[AgentConfig], binary: &Path, silent: bool) -> Result<usize> {
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

        let install_result = match agent.host {
            Some(host) => {
                install_into_with_host(&agent.path, &binary_path, &agent.format, Some(host))
            }
            None => install_into(&agent.path, &binary_path, &agent.format),
        };

        match install_result {
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

    Ok(configured)
}

pub fn list_configs() {
    let binary_path = std::env::current_exe()
        .ok()
        .map(|p| p.to_string_lossy().into_owned())
        .unwrap_or_else(|| "artfct".to_string());

    ui::banner();
    ui::header("Agent config paths");
    eprintln!();
    let agents = discover_agents();
    for agent in &agents {
        let marker = if agent.path.exists() {
            "exists"
        } else {
            "   new"
        };
        let fmt = match agent.format {
            ConfigFormat::Toml => "toml",
            _ => "json",
        };
        eprintln!(
            "  {marker}  {:<16}  {:<6}  {:<12}  {}",
            agent.name,
            fmt,
            agent.host.unwrap_or("(unknown)"),
            agent.path.display()
        );
    }
    eprintln!();
    ui::header("MCP server commands");
    eprintln!();
    for agent in &agents {
        match agent.host {
            Some(host) => eprintln!("  {:<16} artfct mcp serve --host {host}", agent.name),
            None => eprintln!(
                "  {:<16} artfct mcp serve (project config; host omitted)",
                agent.name
            ),
        }
    }
    eprintln!();
    ui::header("Example MCP server entry (JSON)");
    eprintln!();
    let entry = serde_json::json!({
        "mcpServers": {
            "artfct": {
                "command": binary_path,
                "args": ["mcp", "serve", "--host", "cursor"]
            }
        }
    });
    for line in serde_json::to_string_pretty(&entry)
        .unwrap_or_default()
        .lines()
    {
        eprintln!("  {line}");
    }
    eprintln!();
}

fn binary_on_path() -> bool {
    std::env::var("PATH")
        .unwrap_or_default()
        .split(':')
        .map(std::path::Path::new)
        .any(|dir| dir.join("artfct").exists())
}

#[derive(Debug, PartialEq, Eq)]
pub enum InstallResult {
    Written,
    AlreadyConfigured,
}

pub fn install_into(
    config_path: &Path,
    binary_path: &str,
    format: &ConfigFormat,
) -> Result<InstallResult> {
    install_into_with_host(config_path, binary_path, format, None)
}

/// Install the MCP entry and, when the config belongs to a named agent, pass
/// that normalized agent name to the server through `--host`.
pub fn install_into_with_host(
    config_path: &Path,
    binary_path: &str,
    format: &ConfigFormat,
    configured_host: Option<&str>,
) -> Result<InstallResult> {
    ensure_parent(config_path)?;
    match format {
        ConfigFormat::JsonMcpServers => {
            install_json_mcp_servers(config_path, binary_path, configured_host)
        }
        ConfigFormat::JsonOpenCode => {
            install_json_opencode(config_path, binary_path, configured_host)
        }
        ConfigFormat::Toml => install_toml(config_path, binary_path, configured_host),
    }
}

pub fn remove_artfct_entry(config_path: &Path, format: &ConfigFormat) -> Result<bool> {
    match format {
        ConfigFormat::JsonMcpServers => remove_json_mcp_servers(config_path),
        ConfigFormat::JsonOpenCode => remove_json_opencode(config_path),
        ConfigFormat::Toml => remove_toml(config_path),
    }
}

// ── JSON mcpServers ────────────────────────────────────────────────────────────

fn install_json_mcp_servers(
    config_path: &Path,
    binary_path: &str,
    configured_host: Option<&str>,
) -> Result<InstallResult> {
    let mut parsed = read_json(config_path, serde_json::Value::Object(Default::default()))?;

    let servers = parsed
        .as_object_mut()
        .and_then(|obj| obj.get_mut("mcpServers"))
        .and_then(|s| s.as_object_mut());

    if let Some(servers) = servers {
        if let Some(entry) = servers.get_mut("artfct") {
            let expected_args = serde_json::json!(mcp_args(configured_host));
            if configured_host.is_none() || entry.get("args") == Some(&expected_args) {
                return Ok(InstallResult::AlreadyConfigured);
            }

            if let Some(entry) = entry.as_object_mut() {
                entry.insert("command".to_string(), binary_path.into());
                entry.insert("args".to_string(), expected_args);
            } else {
                *entry = artfct_json_entry(binary_path, configured_host);
            }
            return write_json(config_path, &parsed);
        }
        servers.insert(
            "artfct".to_string(),
            artfct_json_entry(binary_path, configured_host),
        );
    } else if let Some(obj) = parsed.as_object_mut() {
        obj.insert(
            "mcpServers".to_string(),
            serde_json::json!({"artfct": artfct_json_entry(binary_path, configured_host)}),
        );
    }

    write_json(config_path, &parsed)
}

fn remove_json_mcp_servers(config_path: &Path) -> Result<bool> {
    if !config_path.exists() {
        return Ok(false);
    }
    let mut parsed = read_json(config_path, serde_json::Value::Object(Default::default()))?;

    let removed = parsed
        .as_object_mut()
        .and_then(|obj| obj.get_mut("mcpServers"))
        .and_then(|s| s.as_object_mut())
        .map(|servers| servers.remove("artfct").is_some())
        .unwrap_or(false);

    if removed {
        cleanup_empty_json_key(&mut parsed, "mcpServers");
        if parsed.as_object().is_none_or(|o| o.is_empty()) {
            fs::remove_file(config_path)?;
        } else {
            write_json(config_path, &parsed)?;
        }
    }

    Ok(removed)
}

fn artfct_json_entry(binary_path: &str, configured_host: Option<&str>) -> serde_json::Value {
    serde_json::json!({"command": binary_path, "args": mcp_args(configured_host)})
}

// ── JSON OpenCode ──────────────────────────────────────────────────────────────

fn install_json_opencode(
    config_path: &Path,
    binary_path: &str,
    configured_host: Option<&str>,
) -> Result<InstallResult> {
    let default = serde_json::json!({"$schema": "https://opencode.ai/config.json"});
    let mut parsed = read_json(config_path, default)?;

    let mcp = parsed
        .as_object_mut()
        .and_then(|obj| obj.get_mut("mcp"))
        .and_then(|s| s.as_object_mut());

    if let Some(mcp) = mcp {
        if let Some(entry) = mcp.get_mut("artfct") {
            let expected_command = serde_json::json!([binary_path, "mcp", "serve"]
                .into_iter()
                .chain(
                    configured_host
                        .into_iter()
                        .flat_map(|host| ["--host", host])
                )
                .collect::<Vec<_>>());
            if configured_host.is_none() || entry.get("command") == Some(&expected_command) {
                return Ok(InstallResult::AlreadyConfigured);
            }

            if let Some(entry) = entry.as_object_mut() {
                entry.insert("command".to_string(), expected_command);
            } else {
                *entry = artfct_opencode_entry(binary_path, configured_host);
            }
            return write_json(config_path, &parsed);
        }
        mcp.insert(
            "artfct".to_string(),
            artfct_opencode_entry(binary_path, configured_host),
        );
    } else if let Some(obj) = parsed.as_object_mut() {
        obj.insert(
            "mcp".to_string(),
            serde_json::json!({"artfct": artfct_opencode_entry(binary_path, configured_host)}),
        );
    }

    write_json(config_path, &parsed)
}

fn remove_json_opencode(config_path: &Path) -> Result<bool> {
    if !config_path.exists() {
        return Ok(false);
    }
    let mut parsed = read_json(config_path, serde_json::Value::Object(Default::default()))?;

    let removed = parsed
        .as_object_mut()
        .and_then(|obj| obj.get_mut("mcp"))
        .and_then(|s| s.as_object_mut())
        .map(|mcp| mcp.remove("artfct").is_some())
        .unwrap_or(false);

    if removed {
        cleanup_empty_json_key(&mut parsed, "mcp");
        if parsed
            .as_object()
            .is_none_or(|o| o.is_empty() || o.keys().all(|k| k == "$schema"))
        {
            fs::remove_file(config_path)?;
        } else {
            write_json(config_path, &parsed)?;
        }
    }

    Ok(removed)
}

fn artfct_opencode_entry(binary_path: &str, configured_host: Option<&str>) -> serde_json::Value {
    serde_json::json!({
        "type": "local",
        "enabled": true,
        "command": opencode_command(binary_path, configured_host)
    })
}

// ── TOML (Codex) ──────────────────────────────────────────────────────────────

fn install_toml(
    config_path: &Path,
    binary_path: &str,
    configured_host: Option<&str>,
) -> Result<InstallResult> {
    let mut doc = read_toml(config_path)?;

    if let Some(entry) = doc
        .get_mut("mcp_servers")
        .and_then(|v| v.as_table_mut())
        .and_then(|t| t.get_mut("artfct"))
    {
        let expected_args = toml::Value::Array(
            mcp_args(configured_host)
                .into_iter()
                .map(toml::Value::String)
                .collect(),
        );
        if configured_host.is_none() || entry.get("args") == Some(&expected_args) {
            return Ok(InstallResult::AlreadyConfigured);
        }

        if let Some(entry) = entry.as_table_mut() {
            entry.insert(
                "command".to_string(),
                toml::Value::String(binary_path.to_string()),
            );
            entry.insert("args".to_string(), expected_args);
        } else {
            *entry = toml::Value::Table(toml_entry(binary_path, configured_host));
        }
        return write_toml(config_path, &doc);
    }

    let entry = toml_entry(binary_path, configured_host);

    let table = doc.as_table_mut().unwrap();
    let servers = table
        .entry("mcp_servers".to_string())
        .or_insert(toml::Value::Table(Default::default()));
    servers
        .as_table_mut()
        .unwrap()
        .insert("artfct".to_string(), toml::Value::Table(entry));

    write_toml(config_path, &doc)
}

fn mcp_args(configured_host: Option<&str>) -> Vec<String> {
    let mut args = vec!["mcp".to_string(), "serve".to_string()];
    if let Some(host) = configured_host {
        args.extend(["--host".to_string(), host.to_string()]);
    }
    args
}

fn opencode_command(binary_path: &str, configured_host: Option<&str>) -> Vec<String> {
    let mut command = vec![binary_path.to_string()];
    command.extend(mcp_args(configured_host));
    command
}

fn toml_entry(
    binary_path: &str,
    configured_host: Option<&str>,
) -> toml::map::Map<String, toml::Value> {
    let mut entry = toml::map::Map::new();
    entry.insert(
        "command".to_string(),
        toml::Value::String(binary_path.to_string()),
    );
    entry.insert(
        "args".to_string(),
        toml::Value::Array(
            mcp_args(configured_host)
                .into_iter()
                .map(toml::Value::String)
                .collect(),
        ),
    );
    entry
}

fn remove_toml(config_path: &Path) -> Result<bool> {
    if !config_path.exists() {
        return Ok(false);
    }
    let mut doc = read_toml(config_path)?;

    let removed = doc
        .get_mut("mcp_servers")
        .and_then(|v| v.as_table_mut())
        .map(|t| t.remove("artfct").is_some())
        .unwrap_or(false);

    if removed {
        if doc.as_table().is_none_or(|t| t.is_empty()) {
            fs::remove_file(config_path)?;
        } else {
            write_toml(config_path, &doc)?;
        }
    }

    Ok(removed)
}

// ── Shared helpers ─────────────────────────────────────────────────────────────

fn ensure_parent(path: &Path) -> Result<()> {
    if let Some(parent) = path.parent() {
        if !parent.as_os_str().is_empty() {
            fs::create_dir_all(parent)
                .with_context(|| format!("Failed to create directory {}", parent.display()))?;
        }
    }
    Ok(())
}

fn read_json(path: &Path, default: serde_json::Value) -> Result<serde_json::Value> {
    if !path.exists() {
        return Ok(default);
    }
    let content =
        fs::read_to_string(path).with_context(|| format!("Failed to read {}", path.display()))?;
    if content.trim().is_empty() {
        return Ok(default);
    }
    match serde_json::from_str(&content) {
        Ok(value) => Ok(value),
        Err(error) => {
            let backup_message = backup_existing(path)?
                .map(|path| format!(" Backup created at {}.", path.display()))
                .unwrap_or_default();
            Err(anyhow!(
                "Invalid JSON in {}.{backup_message} Fix the file manually and retry.",
                path.display()
            )
            .context(error))
        }
    }
}

fn write_json(path: &Path, value: &serde_json::Value) -> Result<InstallResult> {
    backup_existing(path)?;
    let formatted = serde_json::to_string_pretty(value)?;
    fs::write(path, formatted).with_context(|| format!("Failed to write {}", path.display()))?;
    Ok(InstallResult::Written)
}

fn cleanup_empty_json_key(value: &mut serde_json::Value, key: &str) {
    if let Some(obj) = value.as_object_mut() {
        let is_empty = obj
            .get(key)
            .and_then(|v| v.as_object())
            .is_some_and(|m| m.is_empty());
        if is_empty {
            obj.remove(key);
        }
    }
}

fn read_toml(path: &Path) -> Result<toml::Value> {
    if !path.exists() {
        return Ok(toml::Value::Table(Default::default()));
    }
    let content =
        fs::read_to_string(path).with_context(|| format!("Failed to read {}", path.display()))?;
    if content.trim().is_empty() {
        return Ok(toml::Value::Table(Default::default()));
    }
    match toml::from_str(&content) {
        Ok(value) => Ok(value),
        Err(error) => {
            let backup_message = backup_existing(path)?
                .map(|path| format!(" Backup created at {}.", path.display()))
                .unwrap_or_default();
            Err(anyhow!(
                "Invalid TOML in {}.{backup_message} Fix the file manually and retry.",
                path.display()
            )
            .context(error))
        }
    }
}

fn write_toml(path: &Path, value: &toml::Value) -> Result<InstallResult> {
    backup_existing(path)?;
    let formatted = toml::to_string_pretty(value)?;
    fs::write(path, formatted).with_context(|| format!("Failed to write {}", path.display()))?;
    Ok(InstallResult::Written)
}

fn backup_existing(path: &Path) -> Result<Option<PathBuf>> {
    if !path.exists() {
        return Ok(None);
    }

    let timestamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .context("System clock is before the Unix epoch")?
        .as_millis();
    let file_name = path
        .file_name()
        .map(|name| name.to_string_lossy())
        .ok_or_else(|| anyhow!("Cannot create a backup for {}", path.display()))?;
    let backup = path.with_file_name(format!("{file_name}.bak-{timestamp}"));

    fs::copy(path, &backup)
        .with_context(|| format!("Failed to back up {} before changing it", path.display()))?;

    Ok(Some(backup))
}

fn dirs_home() -> Option<PathBuf> {
    std::env::var("HOME").ok().map(PathBuf::from)
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::{
        discover_agents, install_into, remove_artfct_entry, setup_agents_with, AgentConfig,
        ConfigFormat, InstallResult,
    };

    #[test]
    fn discovers_known_agents() {
        assert!(!discover_agents().is_empty());
    }

    #[test]
    fn setup_writes_host_flag_for_each_agent() {
        let tmp = tempfile::tempdir().expect("tempdir");
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
        let binary = tmp.path().join("artfct");
        let binary_string = binary.to_str().expect("binary path is utf-8");

        assert_eq!(
            setup_agents_with(&agents, &binary, true).expect("configure agents"),
            5
        );

        for agent in &agents {
            let host = agent.host.expect("named agent host");
            let content = fs::read_to_string(&agent.path).expect("read agent config");

            match agent.format {
                ConfigFormat::JsonMcpServers => {
                    let parsed: serde_json::Value =
                        serde_json::from_str(&content).expect("parse JSON config");
                    assert_eq!(
                        parsed["mcpServers"]["artfct"]["args"],
                        serde_json::json!(["mcp", "serve", "--host", host])
                    );
                }
                ConfigFormat::JsonOpenCode => {
                    let parsed: serde_json::Value =
                        serde_json::from_str(&content).expect("parse OpenCode config");
                    assert_eq!(
                        parsed["mcp"]["artfct"]["command"],
                        serde_json::json!([binary_string, "mcp", "serve", "--host", host])
                    );
                }
                ConfigFormat::Toml => {
                    let parsed: toml::Value = toml::from_str(&content).expect("parse TOML config");
                    assert_eq!(
                        parsed["mcp_servers"]["artfct"]["args"],
                        toml::Value::Array(
                            ["mcp", "serve", "--host", host]
                                .into_iter()
                                .map(|arg| toml::Value::String(arg.to_string()))
                                .collect()
                        )
                    );
                }
            }
        }
    }

    #[test]
    fn setup_is_idempotent() {
        let tmp = tempfile::tempdir().expect("tempdir");
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
        let binary = tmp.path().join("artfct");

        assert_eq!(
            setup_agents_with(&agents, &binary, true).expect("first setup"),
            5
        );
        let first: Vec<_> = agents
            .iter()
            .map(|agent| fs::read(&agent.path).expect("read first config"))
            .collect();

        assert_eq!(
            setup_agents_with(&agents, &binary, true).expect("second setup"),
            0
        );
        for (agent, first) in agents.iter().zip(first) {
            assert_eq!(fs::read(&agent.path).expect("read second config"), first);
        }
    }

    // ── JSON mcpServers ──────────────────────────────────────────────────────

    #[test]
    fn json_creates_new_config() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("new.mcp.json");

        let result = install_into(
            &path,
            "/usr/local/bin/artfct",
            &ConfigFormat::JsonMcpServers,
        )
        .unwrap();
        assert_eq!(result, InstallResult::Written);

        let parsed: serde_json::Value =
            serde_json::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert_eq!(
            parsed["mcpServers"]["artfct"]["command"],
            "/usr/local/bin/artfct"
        );
        assert_eq!(parsed["mcpServers"]["artfct"]["args"][0], "mcp");
    }

    #[test]
    fn json_update_creates_a_timestamped_backup() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("existing.mcp.json");
        let existing = r#"{"mcpServers":{"other":{"command":"keep"}}}"#;
        fs::write(&path, existing).unwrap();

        install_into(
            &path,
            "/usr/local/bin/artfct",
            &ConfigFormat::JsonMcpServers,
        )
        .expect("update config");

        let backups: Vec<_> = fs::read_dir(tmp.path())
            .unwrap()
            .filter_map(Result::ok)
            .filter(|entry| {
                entry
                    .file_name()
                    .to_string_lossy()
                    .starts_with("existing.mcp.json.bak-")
            })
            .collect();
        assert_eq!(backups.len(), 1);
        assert_eq!(fs::read(backups[0].path()).unwrap(), existing.as_bytes());
    }

    #[test]
    fn invalid_json_is_backed_up_and_not_overwritten() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("broken.mcp.json");
        let invalid = b"{not valid json";
        fs::write(&path, invalid).unwrap();

        let error = install_into(
            &path,
            "/usr/local/bin/artfct",
            &ConfigFormat::JsonMcpServers,
        )
        .expect_err("invalid config should require manual recovery");

        assert!(format!("{error:#}").contains("Fix the file manually"));
        assert_eq!(fs::read(&path).unwrap(), invalid);
        let backups: Vec<_> = fs::read_dir(tmp.path())
            .unwrap()
            .filter_map(Result::ok)
            .filter(|entry| {
                entry
                    .file_name()
                    .to_string_lossy()
                    .starts_with("broken.mcp.json.bak-")
            })
            .collect();
        assert_eq!(backups.len(), 1);
        assert_eq!(fs::read(backups[0].path()).unwrap(), invalid);
    }

    #[test]
    fn json_skips_already_configured() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("exists.mcp.json");

        let existing =
            r#"{"mcpServers": {"artfct": {"command": "artfct", "args": ["mcp", "serve"]}}}"#;
        fs::write(&path, existing).unwrap();

        let result = install_into(
            &path,
            "/usr/local/bin/artfct",
            &ConfigFormat::JsonMcpServers,
        )
        .unwrap();
        assert_eq!(result, InstallResult::AlreadyConfigured);
    }

    #[test]
    fn json_merges_into_existing() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("merge.mcp.json");

        fs::write(&path, r#"{"mcpServers": {"other": {"command": "echo"}}}"#).unwrap();

        install_into(
            &path,
            "/usr/local/bin/artfct",
            &ConfigFormat::JsonMcpServers,
        )
        .unwrap();

        let parsed: serde_json::Value =
            serde_json::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert!(parsed["mcpServers"]
            .as_object()
            .unwrap()
            .contains_key("other"));
        assert!(parsed["mcpServers"]
            .as_object()
            .unwrap()
            .contains_key("artfct"));
    }

    #[test]
    fn json_removes_artfct_entry() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("remove.mcp.json");

        fs::write(
            &path,
            r#"{"mcpServers": {"artfct": {"command": "artfct"}, "other": {"command": "echo"}}}"#,
        )
        .unwrap();

        let removed = remove_artfct_entry(&path, &ConfigFormat::JsonMcpServers).unwrap();
        assert!(removed);
        let content = fs::read_to_string(&path).unwrap();
        assert!(!content.contains("\"artfct\""));
        assert!(content.contains("other"));
    }

    // ── JSON OpenCode ────────────────────────────────────────────────────────

    #[test]
    fn opencode_creates_new_config() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("opencode.json");

        let result =
            install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::JsonOpenCode).unwrap();
        assert_eq!(result, InstallResult::Written);

        let parsed: serde_json::Value =
            serde_json::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert_eq!(parsed["mcp"]["artfct"]["type"], "local");
        assert_eq!(parsed["mcp"]["artfct"]["enabled"], true);
        assert_eq!(
            parsed["mcp"]["artfct"]["command"][0],
            "/usr/local/bin/artfct"
        );
        assert_eq!(parsed["mcp"]["artfct"]["command"][1], "mcp");
        assert_eq!(parsed["mcp"]["artfct"]["command"][2], "serve");
        assert_eq!(parsed["$schema"], "https://opencode.ai/config.json");
    }

    #[test]
    fn opencode_skips_already_configured() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("opencode.json");

        let existing = r#"{"mcp": {"artfct": {"type": "local", "enabled": true, "command": ["/bin/artfct", "mcp", "serve"]}}}"#;
        fs::write(&path, existing).unwrap();

        let result =
            install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::JsonOpenCode).unwrap();
        assert_eq!(result, InstallResult::AlreadyConfigured);
    }

    #[test]
    fn opencode_merges_into_existing() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("opencode.json");

        fs::write(
            &path,
            r#"{"$schema": "https://opencode.ai/config.json", "mcp": {"other": {"type": "local", "command": ["echo"]}}}"#,
        )
        .unwrap();

        install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::JsonOpenCode).unwrap();

        let parsed: serde_json::Value =
            serde_json::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert!(parsed["mcp"].as_object().unwrap().contains_key("other"));
        assert!(parsed["mcp"].as_object().unwrap().contains_key("artfct"));
    }

    // ── TOML (Codex) ────────────────────────────────────────────────────────

    #[test]
    fn toml_creates_new_config() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("config.toml");

        let result = install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::Toml).unwrap();
        assert_eq!(result, InstallResult::Written);

        let parsed: toml::Value = toml::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert_eq!(
            parsed["mcp_servers"]["artfct"]["command"].as_str().unwrap(),
            "/usr/local/bin/artfct"
        );
        assert_eq!(
            parsed["mcp_servers"]["artfct"]["args"].as_array().unwrap()[0]
                .as_str()
                .unwrap(),
            "mcp"
        );
    }

    #[test]
    fn toml_skips_already_configured() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("config.toml");

        fs::write(
            &path,
            "[mcp_servers.artfct]\ncommand = \"/bin/artfct\"\nargs = [\"mcp\", \"serve\"]\n",
        )
        .unwrap();

        let result = install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::Toml).unwrap();
        assert_eq!(result, InstallResult::AlreadyConfigured);
    }

    #[test]
    fn toml_merges_into_existing() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("config.toml");

        fs::write(
            &path,
            "[mcp_servers.other]\ncommand = \"echo\"\nargs = []\n",
        )
        .unwrap();

        install_into(&path, "/usr/local/bin/artfct", &ConfigFormat::Toml).unwrap();

        let parsed: toml::Value = toml::from_str(&fs::read_to_string(&path).unwrap()).unwrap();
        assert!(parsed["mcp_servers"]
            .as_table()
            .unwrap()
            .contains_key("other"));
        assert!(parsed["mcp_servers"]
            .as_table()
            .unwrap()
            .contains_key("artfct"));
    }

    #[test]
    fn toml_removes_artfct_entry() {
        let tmp = tempfile::tempdir().expect("tempdir");
        let path = tmp.path().join("config.toml");

        fs::write(
            &path,
            "[mcp_servers.artfct]\ncommand = \"/bin/artfct\"\nargs = [\"mcp\", \"serve\"]\n\n[mcp_servers.other]\ncommand = \"echo\"\nargs = []\n",
        )
        .unwrap();

        let removed = remove_artfct_entry(&path, &ConfigFormat::Toml).unwrap();
        assert!(removed);
        let content = fs::read_to_string(&path).unwrap();
        assert!(!content.contains("artfct"));
        assert!(content.contains("other"));
    }
}
