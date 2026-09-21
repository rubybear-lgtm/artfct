use crate::api;
use crate::auth;
use crate::setup;
use crate::ui;

pub fn print_report(api_base_url: &str) {
    ui::banner();
    ui::header("Diagnostics");
    eprintln!();
    ui::label_value("Artifact Engine", api_base_url);
    ui::label_value("MCP command", "artfct mcp serve");
    ui::label_value(
        "Environment token",
        if auth::environment_token_configured() {
            "configured (value hidden)"
        } else {
            "not configured"
        },
    );
    ui::label_value(
        "Saved credential",
        match auth::credential_status() {
            auth::CredentialStatus::Missing => "not configured — run `artfct login`",
            auth::CredentialStatus::Valid => "configured (value hidden)",
            auth::CredentialStatus::Invalid => "invalid — run `artfct login` again",
            auth::CredentialStatus::InsecurePermissions => {
                "permissions too broad — run `artfct login` again"
            }
        },
    );
    eprintln!();

    ui::header("Agent configuration");
    eprintln!();
    for agent in setup::discover_agents() {
        ui::label_value(
            agent.name,
            if agent.path.exists() {
                "config found"
            } else {
                "not configured"
            },
        );
    }
    eprintln!();
}

pub async fn run_report(api_base_url: &str) -> anyhow::Result<()> {
    print_report(api_base_url);

    if !auth::has_credentials() {
        return Ok(());
    }

    let client = reqwest::Client::new();
    let Some(token) = auth::access_token(&client, api_base_url).await? else {
        return Ok(());
    };

    eprintln!();
    ui::header("Organization context");
    eprintln!();
    match api::organizations(&client, api_base_url, &token).await {
        Ok(response) => {
            if let Some(organization) = response
                .organizations
                .iter()
                .find(|organization| organization.selected)
            {
                ui::item_success(format!(
                    "Selected: {} · {}",
                    organization.slug,
                    organization.role.as_deref().unwrap_or("unknown role")
                ));
            } else {
                ui::item_error(
                    "No organization is selected; run `artfct login --oauth --organization <slug>`",
                );
            }
            ui::item_success(format!(
                "Available organizations: {}",
                response.organizations.len()
            ));
        }
        Err(error) => {
            ui::item_error(format!("Could not resolve organization context — {error}"));
        }
    }

    eprintln!();
    ui::header("Hosted MCP health");
    eprintln!();
    match api::probe_mcp(&client, api_base_url, &token).await {
        Ok(health) => {
            ui::item_success(format!(
                "Connected: {} · protocol {} · {} tools",
                health.server_name, health.protocol_version, health.tool_count
            ));
        }
        Err(error) => {
            ui::item_error(format!("MCP health check failed — {error}"));
            eprintln!("  Run `artfct login --oauth` to refresh access, then retry.");
        }
    }
    eprintln!();

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::print_report;

    #[test]
    fn runs_without_panic() {
        print_report("https://artfct.dev");
    }
}
