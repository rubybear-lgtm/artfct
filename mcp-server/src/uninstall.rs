use std::fs;

use anyhow::{Context, Result};
use dialoguer::{theme::ColorfulTheme, Confirm};

use crate::{setup, ui};

pub fn uninstall(silent: bool) -> Result<()> {
    let binary = std::env::current_exe().context("Failed to determine CLI binary path")?;
    let binary_path = binary.to_string_lossy();

    ui::header("Uninstalling artfct");
    eprintln!();

    let agents = setup::discover_agents();
    let mut removed_from = 0;
    let mut not_found = 0;

    for agent in &agents {
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
        fs::remove_file(&*binary)
            .with_context(|| format!("Failed to remove binary at {binary_path}"))?;
        ui::success(format!("Removed binary at {binary_path}"));
    } else {
        ui::item_skip(format!("Binary left at {binary_path}"));
    }

    eprintln!();

    Ok(())
}
