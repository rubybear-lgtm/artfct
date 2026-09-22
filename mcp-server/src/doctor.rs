use std::path::PathBuf;

use crate::api;
use crate::auth::{self, CredentialStatus};
use crate::setup;
use crate::ui;

/// The credential states `artfct doctor` can report, together with the
/// recovery guidance each one prints.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CredentialState {
    Missing,
    Valid,
    Invalid,
    InsecurePermissions,
}

impl CredentialState {
    fn from_status(status: CredentialStatus) -> Self {
        match status {
            CredentialStatus::Missing => Self::Missing,
            CredentialStatus::Valid => Self::Valid,
            CredentialStatus::Invalid => Self::Invalid,
            CredentialStatus::InsecurePermissions => Self::InsecurePermissions,
        }
    }

    /// The state alone, without recovery guidance.
    pub fn summary(self) -> &'static str {
        match self {
            Self::Missing => "not configured",
            Self::Valid => "configured (value hidden)",
            Self::Invalid => "invalid",
            Self::InsecurePermissions => "permissions too broad",
        }
    }

    /// What the user has to do to repair this state, if anything.
    pub fn recovery(self) -> Option<&'static str> {
        match self {
            Self::Missing => Some("run `artfct login`"),
            Self::Valid => None,
            Self::Invalid | Self::InsecurePermissions => Some("run `artfct login` again"),
        }
    }

    /// The exact value the doctor prints for this state: the summary, plus the
    /// recovery guidance when the state needs repairing.
    pub fn rendered(self) -> String {
        match self.recovery() {
            Some(recovery) => format!("{} — {recovery}", self.summary()),
            None => self.summary().to_string(),
        }
    }
}

/// The exact value the doctor prints for `ARTFCT_ORG_TOKEN`. The token itself
/// is never part of the report.
pub fn environment_token_value(configured: bool) -> &'static str {
    if configured {
        "configured (value hidden)"
    } else {
        "not configured"
    }
}

/// One agent configuration file the doctor inspects for an artfct entry.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AgentReport {
    pub name: &'static str,
    pub path: PathBuf,
    pub configured: bool,
}

impl AgentReport {
    fn from_agent(agent: setup::AgentConfig) -> Self {
        Self {
            name: agent.name,
            configured: agent.path.exists(),
            path: agent.path,
        }
    }

    /// The exact value the doctor prints for this agent.
    pub fn rendered(&self) -> &'static str {
        if self.configured {
            "config found"
        } else {
            "not configured"
        }
    }
}

/// One labelled line of the printed report.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ReportLine {
    pub label: &'static str,
    pub value: String,
}

/// Everything `artfct doctor` decides before it contacts the API, as data.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DoctorReport {
    pub api_base_url: String,
    pub environment_token_configured: bool,
    pub saved_credential: CredentialState,
    pub agents: Vec<AgentReport>,
}

impl DoctorReport {
    /// The report for this machine, read from the same sources the command
    /// itself uses.
    pub fn collect(api_base_url: &str) -> Self {
        Self {
            api_base_url: api_base_url.to_string(),
            environment_token_configured: auth::environment_token_configured(),
            saved_credential: CredentialState::from_status(auth::credential_status()),
            agents: setup::discover_agents()
                .into_iter()
                .map(AgentReport::from_agent)
                .collect(),
        }
    }

    /// The `Diagnostics` section, in printed order.
    pub fn diagnostics_lines(&self) -> Vec<ReportLine> {
        vec![
            ReportLine {
                label: "Artifact Engine",
                value: self.api_base_url.clone(),
            },
            ReportLine {
                label: "MCP command",
                value: "artfct mcp serve".to_string(),
            },
            ReportLine {
                label: "Environment token",
                value: environment_token_value(self.environment_token_configured).to_string(),
            },
            ReportLine {
                label: "Saved credential",
                value: self.saved_credential.rendered(),
            },
        ]
    }

    /// The `Agent configuration` section, in printed order.
    pub fn agent_lines(&self) -> Vec<ReportLine> {
        self.agents
            .iter()
            .map(|agent| ReportLine {
                label: agent.name,
                value: agent.rendered().to_string(),
            })
            .collect()
    }
}

/// One line of the post-credential report.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ReportItem {
    pub kind: ReportItemKind,
    pub message: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ReportItemKind {
    Success,
    Error,
    /// An indented follow-up line carrying no status marker.
    Hint,
}

impl ReportItem {
    fn success(message: impl Into<String>) -> Self {
        Self {
            kind: ReportItemKind::Success,
            message: message.into(),
        }
    }

    fn error(message: impl Into<String>) -> Self {
        Self {
            kind: ReportItemKind::Error,
            message: message.into(),
        }
    }

    fn hint(message: impl Into<String>) -> Self {
        Self {
            kind: ReportItemKind::Hint,
            message: message.into(),
        }
    }
}

/// The organization context the API resolved for the saved credential.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum OrganizationContext {
    Selected { slug: String, role: String },
    NoneSelected,
}

/// The outcome of the organization lookup.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum OrganizationLookup {
    Resolved {
        context: OrganizationContext,
        available: usize,
    },
    Unavailable {
        error: String,
    },
}

impl OrganizationLookup {
    fn from_response(response: &api::OrganizationsResponse) -> Self {
        let context = match response
            .organizations
            .iter()
            .find(|organization| organization.selected)
        {
            Some(organization) => OrganizationContext::Selected {
                slug: organization.slug.clone(),
                role: organization
                    .role
                    .clone()
                    .unwrap_or_else(|| "unknown role".to_string()),
            },
            None => OrganizationContext::NoneSelected,
        };

        Self::Resolved {
            context,
            available: response.organizations.len(),
        }
    }

    /// The lines the doctor prints for this lookup, including the recovery
    /// command to select an organization when none is selected.
    pub fn items(&self) -> Vec<ReportItem> {
        match self {
            Self::Resolved { context, available } => {
                let selected = match context {
                    OrganizationContext::Selected { slug, role } => {
                        ReportItem::success(format!("Selected: {slug} · {role}"))
                    }
                    OrganizationContext::NoneSelected => ReportItem::error(
                        "No organization is selected; run `artfct login --oauth --organization <slug>`",
                    ),
                };

                vec![
                    selected,
                    ReportItem::success(format!("Available organizations: {available}")),
                ]
            }
            Self::Unavailable { error } => vec![ReportItem::error(format!(
                "Could not resolve organization context — {error}"
            ))],
        }
    }
}

/// The outcome of the hosted MCP health probe.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum McpHealthReport {
    Connected {
        server_name: String,
        protocol_version: String,
        tool_count: usize,
    },
    Failed {
        error: String,
    },
}

impl McpHealthReport {
    fn from_health(health: api::McpHealth) -> Self {
        Self::Connected {
            server_name: health.server_name,
            protocol_version: health.protocol_version,
            tool_count: health.tool_count,
        }
    }

    /// The lines the doctor prints for this probe, including the recovery
    /// guidance emitted when the health check fails.
    pub fn items(&self) -> Vec<ReportItem> {
        match self {
            Self::Connected {
                server_name,
                protocol_version,
                tool_count,
            } => vec![ReportItem::success(format!(
                "Connected: {server_name} · protocol {protocol_version} · {tool_count} tools"
            ))],
            Self::Failed { error } => vec![
                ReportItem::error(format!("MCP health check failed — {error}")),
                ReportItem::hint("Run `artfct login --oauth` to refresh access, then retry."),
            ],
        }
    }
}

/// Prints the local diagnostics report for this machine.
pub fn print_report(api_base_url: &str) {
    render_report(&DoctorReport::collect(api_base_url));
}

fn render_report(report: &DoctorReport) {
    ui::banner();
    ui::header("Diagnostics");
    eprintln!();
    for line in report.diagnostics_lines() {
        ui::label_value(line.label, &line.value);
    }
    eprintln!();

    ui::header("Agent configuration");
    eprintln!();
    for line in report.agent_lines() {
        ui::label_value(line.label, &line.value);
    }
    eprintln!();
}

fn render_items(items: &[ReportItem]) {
    for item in items {
        match item.kind {
            ReportItemKind::Success => ui::item_success(item.message.as_str()),
            ReportItemKind::Error => ui::item_error(item.message.as_str()),
            ReportItemKind::Hint => eprintln!("  {}", item.message),
        }
    }
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
    let lookup = match api::organizations(&client, api_base_url, &token).await {
        Ok(response) => OrganizationLookup::from_response(&response),
        Err(error) => OrganizationLookup::Unavailable {
            error: error.to_string(),
        },
    };
    render_items(&lookup.items());

    eprintln!();
    ui::header("Hosted MCP health");
    eprintln!();
    let health = match api::probe_mcp(&client, api_base_url, &token).await {
        Ok(health) => McpHealthReport::from_health(health),
        Err(error) => McpHealthReport::Failed {
            error: error.to_string(),
        },
    };
    render_items(&health.items());
    eprintln!();

    Ok(())
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::{
        environment_token_value, print_report, AgentReport, CredentialState, DoctorReport,
        McpHealthReport, OrganizationContext, OrganizationLookup, ReportItem, ReportItemKind,
        ReportLine,
    };
    use crate::api::{OrganizationSummary, OrganizationsResponse};
    use crate::auth::CredentialStatus;
    use crate::setup::{AgentConfig, ConfigFormat};

    #[test]
    fn runs_without_panic() {
        print_report("https://artfct.dev");
    }

    #[test]
    fn maps_every_credential_status_to_a_reported_state() {
        assert_eq!(
            CredentialState::from_status(CredentialStatus::Missing),
            CredentialState::Missing
        );
        assert_eq!(
            CredentialState::from_status(CredentialStatus::Valid),
            CredentialState::Valid
        );
        assert_eq!(
            CredentialState::from_status(CredentialStatus::Invalid),
            CredentialState::Invalid
        );
        assert_eq!(
            CredentialState::from_status(CredentialStatus::InsecurePermissions),
            CredentialState::InsecurePermissions
        );
    }

    #[test]
    fn missing_credential_reports_login_guidance() {
        let state = CredentialState::Missing;

        assert_eq!(state.summary(), "not configured");
        assert_eq!(state.recovery(), Some("run `artfct login`"));
        assert_eq!(state.rendered(), "not configured — run `artfct login`");
    }

    #[test]
    fn valid_credential_reports_no_recovery_guidance() {
        let state = CredentialState::Valid;

        assert_eq!(state.summary(), "configured (value hidden)");
        assert_eq!(state.recovery(), None);
        assert_eq!(state.rendered(), "configured (value hidden)");
    }

    #[test]
    fn invalid_credential_reports_login_retry_guidance() {
        let state = CredentialState::Invalid;

        assert_eq!(state.recovery(), Some("run `artfct login` again"));
        assert_eq!(state.rendered(), "invalid — run `artfct login` again");
    }

    #[test]
    fn insecure_credential_permissions_report_login_retry_guidance() {
        let state = CredentialState::InsecurePermissions;

        assert_eq!(state.summary(), "permissions too broad");
        assert_eq!(state.recovery(), Some("run `artfct login` again"));
        assert_eq!(
            state.rendered(),
            "permissions too broad — run `artfct login` again"
        );
    }

    #[test]
    fn environment_token_values_hide_the_token() {
        assert_eq!(
            environment_token_value(true),
            "configured (value hidden)",
            "a configured environment token must never be echoed"
        );
        assert_eq!(environment_token_value(false), "not configured");
    }

    #[test]
    fn diagnostics_lines_report_a_missing_credential_with_guidance() {
        let report = report_for(false, CredentialState::Missing);

        assert_eq!(
            report.diagnostics_lines(),
            vec![
                ReportLine {
                    label: "Artifact Engine",
                    value: "https://artfct.dev".to_string(),
                },
                ReportLine {
                    label: "MCP command",
                    value: "artfct mcp serve".to_string(),
                },
                ReportLine {
                    label: "Environment token",
                    value: "not configured".to_string(),
                },
                ReportLine {
                    label: "Saved credential",
                    value: "not configured — run `artfct login`".to_string(),
                },
            ]
        );
    }

    #[test]
    fn diagnostics_lines_report_a_configured_credential_without_guidance() {
        let report = report_for(true, CredentialState::Valid);
        let lines = report.diagnostics_lines();

        assert_eq!(lines[2].value, "configured (value hidden)");
        assert_eq!(lines[3].value, "configured (value hidden)");
        assert!(
            lines
                .iter()
                .all(|line| !line.value.contains("artfct login")),
            "a healthy report must not emit recovery guidance: {lines:?}"
        );
    }

    #[test]
    fn reports_configured_and_unconfigured_agents() {
        let home = tempfile::tempdir().expect("tempdir");
        let configured_path = home.path().join("configured.json");
        fs::write(&configured_path, "{}").expect("write agent config");
        let missing_path = home.path().join("missing.json");

        let configured = AgentReport::from_agent(AgentConfig {
            name: "Cursor",
            host: Some("cursor"),
            path: configured_path.clone(),
            format: ConfigFormat::JsonMcpServers,
        });
        let missing = AgentReport::from_agent(AgentConfig {
            name: "Codex",
            host: Some("codex"),
            path: missing_path.clone(),
            format: ConfigFormat::Toml,
        });

        assert!(configured.configured);
        assert_eq!(configured.name, "Cursor");
        assert_eq!(configured.path, configured_path);
        assert_eq!(configured.rendered(), "config found");

        assert!(!missing.configured);
        assert_eq!(missing.name, "Codex");
        assert_eq!(missing.path, missing_path);
        assert_eq!(missing.rendered(), "not configured");

        let report = DoctorReport {
            agents: vec![configured, missing],
            ..report_for(false, CredentialState::Missing)
        };
        assert_eq!(
            report.agent_lines(),
            vec![
                ReportLine {
                    label: "Cursor",
                    value: "config found".to_string(),
                },
                ReportLine {
                    label: "Codex",
                    value: "not configured".to_string(),
                },
            ]
        );
    }

    #[test]
    fn report_covers_every_discovered_agent() {
        let report = DoctorReport::collect("https://artfct.dev");
        let discovered = crate::setup::discover_agents();

        assert_eq!(report.agents.len(), discovered.len());
        for (reported, agent) in report.agents.iter().zip(&discovered) {
            assert_eq!(reported.name, agent.name);
            assert_eq!(reported.path, agent.path);
            assert!(
                matches!(reported.rendered(), "config found" | "not configured"),
                "unexpected agent state for {}",
                reported.name
            );
        }
    }

    #[test]
    fn organization_lookup_reports_the_selected_organization() {
        let response = OrganizationsResponse {
            organizations: vec![
                organization("beta", Some("viewer"), false),
                organization("acme", Some("admin"), true),
            ],
        };

        let lookup = OrganizationLookup::from_response(&response);

        assert_eq!(
            lookup,
            OrganizationLookup::Resolved {
                context: OrganizationContext::Selected {
                    slug: "acme".to_string(),
                    role: "admin".to_string(),
                },
                available: 2,
            }
        );
        assert_eq!(
            lookup.items(),
            vec![
                ReportItem::success("Selected: acme · admin"),
                ReportItem::success("Available organizations: 2"),
            ]
        );
    }

    #[test]
    fn organization_lookup_reports_unknown_role_without_a_role() {
        let response = OrganizationsResponse {
            organizations: vec![organization("acme", None, true)],
        };

        let lookup = OrganizationLookup::from_response(&response);

        assert_eq!(
            lookup.items()[0].message,
            "Selected: acme · unknown role".to_string()
        );
    }

    #[test]
    fn organization_lookup_reports_login_guidance_when_none_is_selected() {
        let response = OrganizationsResponse {
            organizations: vec![organization("beta", Some("viewer"), false)],
        };

        let lookup = OrganizationLookup::from_response(&response);
        let items = lookup.items();

        assert_eq!(
            items[0],
            ReportItem::error(
                "No organization is selected; run `artfct login --oauth --organization <slug>`"
            )
        );
        assert_eq!(items[1], ReportItem::success("Available organizations: 1"));
    }

    #[test]
    fn organization_lookup_failure_reports_the_error_line() {
        let lookup = OrganizationLookup::Unavailable {
            error: "Failed to reach the Artfct organizations endpoint".to_string(),
        };

        assert_eq!(
            lookup.items(),
            vec![ReportItem::error(
                "Could not resolve organization context — Failed to reach the Artfct organizations endpoint"
            )]
        );
    }

    #[test]
    fn failed_mcp_health_check_reports_refresh_guidance() {
        let health = McpHealthReport::Failed {
            error: "Hosted MCP initialize returned HTTP 401 Unauthorized".to_string(),
        };

        let items = health.items();

        assert_eq!(items.len(), 2);
        assert_eq!(items[0].kind, ReportItemKind::Error);
        assert_eq!(
            items[0].message,
            "MCP health check failed — Hosted MCP initialize returned HTTP 401 Unauthorized"
        );
        assert_eq!(items[1].kind, ReportItemKind::Hint);
        assert_eq!(
            items[1].message,
            "Run `artfct login --oauth` to refresh access, then retry."
        );
    }

    #[test]
    fn connected_mcp_health_reports_server_details() {
        let health = McpHealthReport::from_health(crate::api::McpHealth {
            server_name: "artfct-mcp".to_string(),
            protocol_version: "2025-11-25".to_string(),
            tool_count: 7,
        });

        assert_eq!(
            health.items(),
            vec![ReportItem::success(
                "Connected: artfct-mcp · protocol 2025-11-25 · 7 tools"
            )]
        );
    }

    fn report_for(
        environment_token_configured: bool,
        saved_credential: CredentialState,
    ) -> DoctorReport {
        DoctorReport {
            api_base_url: "https://artfct.dev".to_string(),
            environment_token_configured,
            saved_credential,
            agents: Vec::new(),
        }
    }

    fn organization(slug: &str, role: Option<&str>, selected: bool) -> OrganizationSummary {
        OrganizationSummary {
            name: slug.to_string(),
            slug: slug.to_string(),
            role: role.map(str::to_string),
            selected,
        }
    }
}
