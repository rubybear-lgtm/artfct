#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SUDO=""
if [ "$(id -u)" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

# 1. Ensure ~/.bashrc does not exit early in non-interactive shells and includes ~/.local/bin.
mkdir -p "$HOME/.local/bin"
if [ -f "$HOME/.bashrc" ]; then
    sed -i '/case \$- in/,/esac/d' "$HOME/.bashrc" 2>/dev/null || true
fi
grep -qxF 'export PATH="$HOME/.local/bin:$PATH"' "$HOME/.bashrc" 2>/dev/null || echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$HOME/.bashrc"
export PATH="$HOME/.local/bin:$PATH"

# 2. Install PHP 8.5 and required extensions via packages.sury.org on Debian/Ubuntu.
if command -v dpkg >/dev/null 2>&1 && command -v apt-get >/dev/null 2>&1; then
    PHP_PACKAGES=(
        php8.5-cli
        php8.5-common
        php8.5-curl
        php8.5-mbstring
        php8.5-xml
        php8.5-sqlite3
        php8.5-pgsql
        php8.5-bcmath
        php8.5-intl
        php8.5-gd
        php8.5-zip
        php8.5-readline
        php8.5-mysql
    )

    if ! dpkg -s "${PHP_PACKAGES[@]}" >/dev/null 2>&1; then
        $SUDO env DEBIAN_FRONTEND=noninteractive apt-get update
        $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends lsb-release ca-certificates curl
        if [ ! -f /etc/apt/sources.list.d/php.list ]; then
            curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
            $SUDO dpkg -i /tmp/debsuryorg-archive-keyring.deb
            rm -f /tmp/debsuryorg-archive-keyring.deb
            echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
                | $SUDO tee /etc/apt/sources.list.d/php.list >/dev/null
            $SUDO env DEBIAN_FRONTEND=noninteractive apt-get update
        fi
        $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${PHP_PACKAGES[@]}"
    fi

    if [ -x /usr/bin/php8.5 ]; then
        $SUDO update-alternatives --set php /usr/bin/php8.5 >/dev/null
        if [ ! -f /etc/php/8.5/cli/conf.d/99-opcache-cli.ini ] || ! grep -qxF 'opcache.enable_cli=1' /etc/php/8.5/cli/conf.d/99-opcache-cli.ini; then
            echo 'opcache.enable_cli=1' | $SUDO tee /etc/php/8.5/cli/conf.d/99-opcache-cli.ini >/dev/null
        fi
    fi
fi

# 3. Install gitleaks and actionlint into ~/.local/bin on Linux.
if [ "$(uname -s)" = "Linux" ]; then
    ARCH="$(uname -m)"
    case "$ARCH" in
        x86_64)
            GITLEAKS_ARCH="x64"
            ACTIONLINT_ARCH="amd64"
            ;;
        aarch64|arm64)
            GITLEAKS_ARCH="arm64"
            ACTIONLINT_ARCH="arm64"
            ;;
        *)
            GITLEAKS_ARCH=""
            ACTIONLINT_ARCH=""
            ;;
    esac

    GITLEAKS_VERSION="8.21.2"
    if [ -n "$GITLEAKS_ARCH" ] && ! command -v gitleaks >/dev/null 2>&1; then
        curl --retry 5 --retry-delay 2 --retry-all-errors -fsSL \
            "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${GITLEAKS_ARCH}.tar.gz" \
            | tar -xz -C "$HOME/.local/bin" gitleaks
    fi

    ACTIONLINT_VERSION="1.7.7"
    if [ -n "$ACTIONLINT_ARCH" ] && ! command -v actionlint >/dev/null 2>&1; then
        curl --retry 5 --retry-delay 2 --retry-all-errors -fsSL \
            "https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/actionlint_${ACTIONLINT_VERSION}_linux_${ACTIONLINT_ARCH}.tar.gz" \
            | tar -xz -C "$HOME/.local/bin" actionlint
    fi
fi

# 4. Ensure Rust wasm32-unknown-unknown target is installed for the Cloudflare Worker.
if command -v rustup >/dev/null 2>&1; then
    if ! rustup target list --installed | grep -qx 'wasm32-unknown-unknown'; then
        rustup target add wasm32-unknown-unknown
    fi
fi

# 5. Configure repository dependencies, Laravel environment, Playwright Chromium, and frontend build.
cd "$REPO_DIR"

git config core.hooksPath .githooks

composer install --no-interaction --prefer-dist --optimize-autoloader

if [ ! -f .env ]; then
    cp .env.example .env
fi
if ! grep -qE '^APP_KEY=.+' .env; then
    php artisan key:generate --no-interaction
fi

mkdir -p database
touch database/database.sqlite
php artisan migrate --force --no-interaction

LOCK_HASH="$(sha256sum package-lock.json | awk '{print $1}')"
if [ ! -d node_modules ] || [ "$(cat node_modules/.env-setup-lockhash 2>/dev/null || true)" != "$LOCK_HASH" ]; then
    npm ci --no-fund
    printf '%s\n' "$LOCK_HASH" > node_modules/.env-setup-lockhash
fi

if ! node -e "const fs = require('fs'); const { chromium } = require('playwright'); process.exit(fs.existsSync(chromium.executablePath()) ? 0 : 1);"; then
    npx playwright install chromium
fi

if [ ! -f public/build/manifest.json ] || [ ! -f bootstrap/ssr/app.js ] || [ ! -d resources/js/wayfinder ]; then
    APP_ENV=production npm run build:ssr
fi
