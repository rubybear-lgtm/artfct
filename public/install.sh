#!/bin/sh
set -eu

repo="${ARTFCT_INSTALL_REPO:-rubybear-lgtm/artfct}"
version="${ARTFCT_INSTALL_VERSION:-latest}"
install_dir="${ARTFCT_INSTALL_DIR:-$HOME/.local/bin}"
binary_name="${ARTFCT_INSTALL_BINARY:-artfct}"
base_url="${ARTFCT_INSTALL_BASE_URL:-https://github.com/$repo/releases}"

say() {
    printf '%s\n' "$*"
}

fail() {
    say "error: $*" >&2
    exit 1
}

need() {
    command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

detect_target() {
    os="$(uname -s 2>/dev/null | tr '[:upper:]' '[:lower:]')"
    arch="$(uname -m 2>/dev/null)"

    case "$os" in
        darwin)
            os="apple-darwin"
            ;;
        linux)
            os="unknown-linux-gnu"
            ;;
        *)
            fail "unsupported operating system: $os"
            ;;
    esac

    case "$arch" in
        arm64 | aarch64)
            arch="aarch64"
            ;;
        x86_64 | amd64)
            arch="x86_64"
            ;;
        *)
            fail "unsupported CPU architecture: $arch"
            ;;
    esac

    printf '%s-%s' "$arch" "$os"
}

download_url_for() {
    target="$1"
    asset="artfct-$target.tar.gz"

    if [ "$version" = "latest" ]; then
        printf '%s/latest/download/%s' "$base_url" "$asset"
    else
        printf '%s/download/%s/%s' "$base_url" "$version" "$asset"
    fi
}

install_artfct() {
    need curl
    need tar
    need mktemp

    target="$(detect_target)"
    url="$(download_url_for "$target")"
    tmpdir="$(mktemp -d)"
    archive="$tmpdir/artfct.tar.gz"

    cleanup() {
        rm -rf "$tmpdir"
    }

    trap cleanup EXIT INT TERM

    say "Installing artfct for $target"
    say "Downloading $url"

    curl -fsSL "$url" -o "$archive" || fail "download failed"
    tar -xzf "$archive" -C "$tmpdir" || fail "archive extraction failed"

    if [ ! -f "$tmpdir/$binary_name" ]; then
        fail "archive did not contain $binary_name"
    fi

    mkdir -p "$install_dir"
    cp "$tmpdir/$binary_name" "$install_dir/$binary_name"
    chmod 755 "$install_dir/$binary_name"

    say "Installed $binary_name to $install_dir/$binary_name"

    case ":$PATH:" in
        *":$install_dir:"*) ;;
        *)
            say "Add this directory to PATH if needed:"
            say "  export PATH=\"$install_dir:\$PATH\""
            ;;
    esac

    "$install_dir/$binary_name" doctor || true

    if ! "$install_dir/$binary_name" setup --help >/dev/null 2>&1; then
        say "Setup command is not available in this build yet."
        return
    fi

    if [ "${ARTFCT_INSTALL_SETUP:-}" = "0" ]; then
        say "Skipping setup. Run it later with: artfct setup"
        return
    fi

    if [ -t 0 ] && [ -t 1 ]; then
        "$install_dir/$binary_name" setup
    else
        "$install_dir/$binary_name" setup --silent
    fi
}

install_artfct
