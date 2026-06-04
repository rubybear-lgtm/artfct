# artfct CLI

`artfct` publishes self-contained HTML files to temporary magic links.

## Install

Install the latest CLI release:

```sh
curl -fsSL https://artfct.dev/install.sh | sh
```

The installer downloads the right binary for macOS or Linux and installs it to
`~/.local/bin/artfct` by default.

If `~/.local/bin` is not on your `PATH`, add it:

```sh
export PATH="$HOME/.local/bin:$PATH"
```

Install a specific release:

```sh
ARTFCT_INSTALL_VERSION=v0.1.0 curl -fsSL https://artfct.dev/install.sh | sh
```

Install to a different directory:

```sh
ARTFCT_INSTALL_DIR=/usr/local/bin curl -fsSL https://artfct.dev/install.sh | sh
```

## Deploy HTML

Deploy a file:

```sh
artfct deploy ./dashboard.html
```

Deploy from stdin:

```sh
cat dashboard.html | artfct deploy --stdin
```

Set the artifact tier and expiration:

```sh
artfct deploy ./dashboard.html --tier ephemeral --ttl-minutes 30
```

The command prints the preview URL:

```text
https://artfct.dev/p/<artifact-id>
```

## Deploy Options

```text
Usage: artfct deploy [OPTIONS] [FILE]

Arguments:
  [FILE]  Path to a self-contained HTML file to publish

Options:
      --stdin                  Read the HTML payload from standard input
      --tier <TIER>            Artifact tier: public, secure, or ephemeral [default: ephemeral]
      --ttl-minutes <MINUTES>  Minutes until the artifact expires. Defaults to the backend policy
  -h, --help                   Print help
```

## MCP Server

Run the CLI as a local MCP server over stdio:

```sh
artfct mcp serve
```

Use that command in an MCP client configuration:

```json
{
    "mcpServers": {
        "artfct": {
            "command": "artfct",
            "args": ["mcp", "serve"]
        }
    }
}
```

The MCP server exposes `deploy_to_canvas`, which accepts a complete HTML payload
and returns an artfct preview URL.

## Diagnostics

Check the local CLI configuration and MCP command:

```sh
artfct doctor
```

Print command help:

```sh
artfct --help
artfct deploy --help
artfct mcp --help
```

## Environment

Override the API base URL:

```sh
ARTFCT_API_BASE_URL=https://artfct.dev artfct deploy ./dashboard.html
```

Installer options:

```text
ARTFCT_INSTALL_VERSION   Release tag to install. Defaults to latest.
ARTFCT_INSTALL_DIR       Install directory. Defaults to ~/.local/bin.
ARTFCT_INSTALL_REPO      GitHub repo. Defaults to rubybear-lgtm/artfct.
```
