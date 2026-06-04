use std::path::PathBuf;

use clap::{Args, Parser, Subcommand};

#[derive(Debug, Parser)]
#[command(
    name = "artfct",
    version,
    about = "Publish HTML artifacts to magic links"
)]
pub struct Cli {
    #[command(subcommand)]
    pub command: Command,
}

#[derive(Debug, Subcommand)]
pub enum Command {
    Deploy(DeployArgs),
    Mcp {
        #[command(subcommand)]
        command: McpCommand,
    },
    Doctor,
}

#[derive(Debug, Args)]
pub struct DeployArgs {
    #[arg(value_name = "FILE", conflicts_with = "stdin")]
    pub file: Option<PathBuf>,

    #[arg(long)]
    pub stdin: bool,

    #[arg(long, default_value = "ephemeral")]
    pub tier: String,

    #[arg(long)]
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
    Serve,
}

#[cfg(test)]
mod tests {
    use std::path::PathBuf;

    use clap::Parser;

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
}
