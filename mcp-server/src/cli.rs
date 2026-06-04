use std::path::PathBuf;

use clap::{Args, Parser, Subcommand};

#[derive(Debug, Parser)]
#[command(
    name = "artfct",
    version,
    about = "Publish self-contained HTML artifacts to magic links",
    long_about = "Publish self-contained HTML artifacts to artfct.dev and run the local MCP server used by coding agents.",
    after_help = "Examples:
  artfct deploy ./dashboard.html
  cat dashboard.html | artfct deploy --stdin --ttl-minutes 30
  artfct mcp serve
  artfct doctor

Environment:
  ARTFCT_API_BASE_URL    Override the API base URL. Defaults to https://artfct.dev"
)]
pub struct Cli {
    #[command(subcommand)]
    pub command: Command,
}

#[derive(Debug, Subcommand)]
pub enum Command {
    #[command(
        about = "Upload an HTML file or stdin and print the preview URL",
        after_help = "Examples:
  artfct deploy ./page.html
  artfct deploy ./page.html --tier public --ttl-minutes 120
  printf '<h1>Hello</h1>' | artfct deploy --stdin"
    )]
    Deploy(DeployArgs),
    #[command(about = "Manage the artfct MCP server entrypoint")]
    Mcp {
        #[command(subcommand)]
        command: McpCommand,
    },
    #[command(about = "Print local CLI and MCP diagnostics")]
    Doctor,
}

#[derive(Debug, Args)]
pub struct DeployArgs {
    #[arg(
        value_name = "FILE",
        conflicts_with = "stdin",
        help = "Path to a self-contained HTML file to publish"
    )]
    pub file: Option<PathBuf>,

    #[arg(long, help = "Read the HTML payload from standard input")]
    pub stdin: bool,

    #[arg(
        long,
        default_value = "ephemeral",
        value_name = "TIER",
        help = "Artifact tier: public, secure, or ephemeral"
    )]
    pub tier: String,

    #[arg(
        long,
        value_name = "MINUTES",
        help = "Minutes until the artifact expires. Defaults to the backend policy"
    )]
    pub ttl_minutes: Option<u64>,
}

#[derive(Debug, PartialEq, Eq)]
pub enum DeployInput {
    File(PathBuf),
    Stdin,
}

impl DeployArgs {
    pub fn input(&self) -> DeployInput {
        match (&self.file, self.stdin) {
            (Some(path), _) => DeployInput::File(path.clone()),
            (None, _) => DeployInput::Stdin,
        }
    }
}

#[derive(Debug, Subcommand)]
pub enum McpCommand {
    #[command(
        about = "Run the MCP server over stdio for Claude Code, Codex, Gemini, and other agents",
        after_help = "Use this command as the MCP server command in an agent config:
  artfct mcp serve"
    )]
    Serve,
}

#[cfg(test)]
mod tests {
    use std::path::PathBuf;

    use clap::{CommandFactory, Parser};

    use super::{Cli, Command, DeployInput, McpCommand};

    #[test]
    fn parses_deploy_from_stdin() {
        let cli = Cli::parse_from(["artfct", "deploy", "--stdin"]);

        let Command::Deploy(args) = cli.command else {
            panic!("expected deploy command");
        };

        assert_eq!(args.input(), DeployInput::Stdin);
    }

    #[test]
    fn parses_deploy_from_file() {
        let cli = Cli::parse_from(["artfct", "deploy", "preview.html"]);

        let Command::Deploy(args) = cli.command else {
            panic!("expected deploy command");
        };

        assert_eq!(
            args.input(),
            DeployInput::File(PathBuf::from("preview.html"))
        );
    }

    #[test]
    fn parses_mcp_serve() {
        let cli = Cli::parse_from(["artfct", "mcp", "serve"]);

        assert!(matches!(
            cli.command,
            Command::Mcp {
                command: McpCommand::Serve,
            }
        ));
    }

    #[test]
    fn parses_doctor() {
        let cli = Cli::parse_from(["artfct", "doctor"]);

        assert!(matches!(cli.command, Command::Doctor));
    }

    #[test]
    fn help_includes_examples_and_flag_descriptions() {
        let mut buffer = Vec::new();

        Cli::command()
            .write_long_help(&mut buffer)
            .expect("help should render");

        let help = String::from_utf8(buffer).expect("help should be utf-8");

        assert!(help.contains("artfct deploy ./dashboard.html"));
        assert!(help.contains("ARTFCT_API_BASE_URL"));
        assert!(help.contains("Upload an HTML file or stdin"));
    }
}
