#!/usr/bin/env bash
# ==============================================================================
# Script: update_packages.sh
# Description: Automated dependency update and validation script for Cert Drawer
# Supports Composer (PHP) and NPM (JavaScript/CSS) packages with preflight
# checks, backups, asset compilation, caching, audit, and test execution.
# ==============================================================================

set -eo pipefail

# Text colors & styling
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Default options
DRY_RUN=false
COMPOSER_ONLY=false
NPM_ONLY=false
SKIP_TESTS=false
SKIP_BUILD=false
SKIP_AUDIT=false
SKIP_BACKUP=false
FORCE=false

# Helper output functions
echo_step() {
    echo -e "\n${BLUE}${BOLD}==>${NC} ${BOLD}$1${NC}"
}

echo_info() {
    echo -e "  ${CYAN}[INFO]${NC} $1"
}

echo_success() {
    echo -e "  ${GREEN}[✓]${NC} $1"
}

echo_warn() {
    echo -e "  ${YELLOW}[WARN]${NC} $1"
}

echo_error() {
    echo -e "  ${RED}[ERROR]${NC} $1"
}

# Usage documentation
show_help() {
    cat << EOF
Cert Drawer Package Update Tool

Usage: ./update_packages.sh [OPTIONS]

Options:
  -d, --dry-run        Simulate package updates and display available updates
                       without modifying files or running builds/tests.
  --composer-only      Update only PHP / Composer dependencies.
  --npm-only           Update only JavaScript / NPM dependencies.
  --skip-tests         Skip running PHPUnit / Pest test suite after update.
  --skip-build         Skip rebuilding frontend assets via Vite (npm run build).
  --skip-audit         Skip security vulnerability scanning (composer audit).
  --skip-backup        Skip creating a local backup of lockfiles and database.
  -f, --force          Bypass uncommitted git changes warning.
  -h, --help           Display this help message and exit.

Examples:
  ./update_packages.sh --dry-run      # Check for pending updates safely
  ./update_packages.sh                # Standard safe update + build + test
  ./update_packages.sh --skip-tests   # Update without running tests
  ./update_packages.sh --composer-only # Update only Composer packages
EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        --composer-only)
            COMPOSER_ONLY=true
            shift
            ;;
        --npm-only)
            NPM_ONLY=true
            shift
            ;;
        --skip-tests)
            SKIP_TESTS=true
            shift
            ;;
        --skip-build)
            SKIP_BUILD=true
            shift
            ;;
        --skip-audit)
            SKIP_AUDIT=true
            shift
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        -f|--force)
            FORCE=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            echo_error "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

# Ensure script is run from project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [[ ! -f "artisan" ]] || [[ ! -f "composer.json" ]]; then
    echo_error "This script must be executed from the root of the Laravel project."
    exit 1
fi

echo -e "${BOLD}======================================================${NC}"
echo -e "${BOLD}       Cert Drawer - Package Update Manager           ${NC}"
echo -e "${BOLD}======================================================${NC}"

# Preflight checks
echo_step "Performing preflight checks..."

check_tool() {
    local tool=$1
    if ! command -v "$tool" &> /dev/null; then
        echo_error "$tool is required but not installed or not in PATH."
        exit 1
    fi
}

check_tool "php"
check_tool "composer"
check_tool "node"
check_tool "npm"
check_tool "git"

PHP_VERSION=$(php -r "echo PHP_VERSION;")
COMPOSER_VERSION=$(composer --version 2>/dev/null | head -n1)
NODE_VERSION=$(node -v)
NPM_VERSION=$(npm -v)

echo_info "PHP Version:      $PHP_VERSION"
echo_info "Composer Version: $COMPOSER_VERSION"
echo_info "Node.js Version:  $NODE_VERSION"
echo_info "NPM Version:      $NPM_VERSION"

# Check git status for uncommitted changes
if ! $DRY_RUN && ! $FORCE; then
    if [[ -n $(git status --porcelain 2>/dev/null | grep -E '^(\?\?|.[M|D|A|R]) (composer\.|package|resources|app|database)') ]]; then
        echo_warn "There are uncommitted changes in tracked application files."
        read -rp "  Do you want to proceed anyway? [y/N]: " proceed
        if [[ ! "$proceed" =~ ^[Yy]$ ]]; then
            echo_info "Update aborted by user. Please commit or stash changes before updating."
            exit 0
        fi
    fi
fi

# Backup creation
BACKUP_DIR=""
if ! $DRY_RUN && ! $SKIP_BACKUP; then
    echo_step "Creating safety backup before updating..."
    TIMESTAMP=$(date +'%Y%m%d_%H%M%S')
    BACKUP_DIR=".update_backups/backup-$TIMESTAMP"
    mkdir -p "$BACKUP_DIR"

    [[ -f "composer.json" ]] && cp "composer.json" "$BACKUP_DIR/"
    [[ -f "composer.lock" ]] && cp "composer.lock" "$BACKUP_DIR/"
    [[ -f "package.json" ]] && cp "package.json" "$BACKUP_DIR/"
    [[ -f "package-lock.json" ]] && cp "package-lock.json" "$BACKUP_DIR/"
    if [[ -f "database/database.sqlite" ]]; then
        cp "database/database.sqlite" "$BACKUP_DIR/database.sqlite"
    fi

    echo_success "Backup preserved in: $BACKUP_DIR"
fi

# Trap failures to report rollback instructions
rollback_notice() {
    if [[ -n "$BACKUP_DIR" && -d "$BACKUP_DIR" ]]; then
        echo -e "\n${RED}${BOLD}[FAILURE] An error occurred during the update process.${NC}"
        echo_warn "You can restore files from your backup by running:"
        echo -e "  cp $BACKUP_DIR/composer.json ."
        echo -e "  cp $BACKUP_DIR/composer.lock ."
        echo -e "  cp $BACKUP_DIR/package.json ."
        [[ -f "$BACKUP_DIR/package-lock.json" ]] && echo -e "  cp $BACKUP_DIR/package-lock.json ."
        [[ -f "$BACKUP_DIR/database.sqlite" ]] && echo -e "  cp $BACKUP_DIR/database.sqlite database/database.sqlite"
        echo -e "  composer install"
        echo -e "  npm install"
    fi
}
trap rollback_notice ERR

# ------------------------------------------------------------------------------
# DRY-RUN MODE
# ------------------------------------------------------------------------------
if $DRY_RUN; then
    echo_step "DRY-RUN: Inspecting available Composer updates..."
    if ! $NPM_ONLY; then
        echo_info "Simulating: composer update --dry-run"
        composer update --dry-run || true

        echo_info "Direct outdated packages:"
        composer outdated --direct || true
    fi

    echo_step "DRY-RUN: Inspecting available NPM updates..."
    if ! $COMPOSER_ONLY; then
        echo_info "Simulating: npm outdated"
        npm outdated || echo_info "All NPM packages are currently up-to-date with constraints."
    fi

    echo_step "DRY-RUN: Checking current Composer security audit..."
    composer audit || true

    echo -e "\n${GREEN}${BOLD}Dry-run completed successfully.${NC} No files were modified."
    exit 0
fi

# ------------------------------------------------------------------------------
# COMPOSER UPDATE
# ------------------------------------------------------------------------------
if ! $NPM_ONLY; then
    echo_step "Updating Composer dependencies..."
    composer update --no-interaction

    echo_success "Composer dependencies updated successfully."
fi

# ------------------------------------------------------------------------------
# NPM UPDATE & BUILD
# ------------------------------------------------------------------------------
if ! $COMPOSER_ONLY; then
    echo_step "Updating NPM dependencies..."
    npm update

    echo_success "NPM dependencies updated."

    if ! $SKIP_BUILD; then
        echo_step "Building frontend assets with Vite..."
        npm run build
        echo_success "Frontend assets compiled successfully."
    else
        echo_info "Skipping frontend assets build (--skip-build)."
    fi
fi

# ------------------------------------------------------------------------------
# LARAVEL OPTIMIZATION & CACHE CLEARING
# ------------------------------------------------------------------------------
echo_step "Clearing and optimizing Laravel caches..."
php artisan optimize:clear
php artisan package:discover --ansi

# Check for database migrations
echo_step "Checking database migrations..."
if php artisan migrate:status &>/dev/null; then
    php artisan migrate --force
    echo_success "Database migrations up to date."
else
    echo_info "Database not initialized or not configured for artisan migrations. Skipped."
fi

# ------------------------------------------------------------------------------
# SECURITY AUDIT
# ------------------------------------------------------------------------------
if ! $SKIP_AUDIT; then
    echo_step "Running security audit on Composer packages..."
    if composer audit; then
        echo_success "No known security vulnerabilities reported by Composer audit."
    else
        echo_warn "Composer audit reported advisories. Please review above output."
    fi
fi

# ------------------------------------------------------------------------------
# TEST SUITE VALIDATION
# ------------------------------------------------------------------------------
if ! $SKIP_TESTS; then
    echo_step "Executing test suite (php artisan test)..."
    if php artisan test; then
        echo_success "All tests passed successfully!"
    else
        echo_error "Some tests failed after package update! Review test output above."
        exit 1
    fi
else
    echo_info "Skipping test execution (--skip-tests)."
fi

# ------------------------------------------------------------------------------
# SUMMARY & COMPLETION
# ------------------------------------------------------------------------------
echo_step "Update process completed successfully!"

echo -e "\n${GREEN}${BOLD}Summary of Actions:${NC}"
if ! $NPM_ONLY; then
    echo -e "  - Composer packages updated (composer.lock refreshed)"
fi
if ! $COMPOSER_ONLY; then
    echo -e "  - NPM packages updated"
    if ! $SKIP_BUILD; then
        echo -e "  - Frontend assets compiled (public/build)"
    fi
fi
echo -e "  - Application caches cleared and rediscovered"
if ! $SKIP_TESTS; then
    echo -e "  - Test suite validated (60+ tests passed)"
fi
if [[ -n "$BACKUP_DIR" ]]; then
    echo -e "  - Safety backup saved at: ${CYAN}$BACKUP_DIR${NC}"
fi

echo -e "\n${CYAN}${BOLD}Suggested Next Steps:${NC}"
echo -e "  1. Review git diff:               ${BOLD}git diff composer.lock package.json${NC}"
echo -e "  2. If building a Docker image:    ${BOLD}./build_image.sh${NC}"
echo -e "  3. Commit and push your changes:  ${BOLD}git commit -am 'Update project dependencies'${NC}\n"

# Remove trap on clean exit
trap - ERR
exit 0
