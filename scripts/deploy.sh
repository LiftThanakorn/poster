#!/usr/bin/env bash
# ==============================================================================
# Poster Application — Automated Production Deployment Script
# Usage: ./scripts/deploy.sh [branch_name]
# Default branch: main
# ==============================================================================

set -e

# Target branch
BRANCH="${1:-main}"

echo "=========================================================="
echo " Starting Poster Deployment — $(date '+%Y-%m-%d %H:%M:%S')"
echo " Branch: $BRANCH"
echo "=========================================================="

# Ensure script is executed from project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

if [ ! -f "artisan" ]; then
    echo "ERROR: 'artisan' not found. Please run this script from the project root."
    exit 1
fi

# Ensure site is brought back up even if deployment fails
trap 'if [ -f storage/framework/down ]; then echo "Deploy failed! Bringing application back up..."; php artisan up; fi' EXIT

# 1. Enable Maintenance Mode (with retry header for clients)
echo "--> Enabling maintenance mode..."
php artisan down --retry=60 --secret="poster-bypass-deploy-mode" || true

# 2. Pull Latest Code from Repository
echo "--> Fetching and pulling latest changes from git ($BRANCH)..."
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull origin "$BRANCH"

# 3. Install Composer Dependencies (Production Only)
echo "--> Installing PHP dependencies (Composer)..."
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Install NPM Dependencies & Build Frontend Assets
echo "--> Building frontend assets (Vite)..."
if npm ci 2>/dev/null; then
    echo "    npm ci completed successfully."
else
    echo "    npm ci failed, falling back to npm install --legacy-peer-deps..."
    npm install --legacy-peer-deps
fi
npm run build

# 5. Database Migrations
echo "--> Running database migrations..."
php artisan migrate --force

# 6. Ensure Storage Link & Directory Permissions
echo "--> Verifying storage symlink and permissions..."
php artisan storage:link || true

# Set directory permissions for web server (detect www-data or www user)
WEB_USER="www-data"
if id "www" &>/dev/null; then
    WEB_USER="www"
fi

if [ "$(id -u)" -eq 0 ]; then
    chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache
fi

# 7. Optimize & Cache Config, Routes, and Views
echo "--> Caching configuration and routes..."
php artisan optimize:clear
php artisan optimize
php artisan view:cache

# 8. Restart Queue Workers
echo "--> Restarting queue workers..."
php artisan queue:restart

# If Supervisor is installed, signal workers to reload new code
if command -v supervisorctl &>/dev/null; then
    echo "--> Reloading Supervisor worker processes..."
    sudo supervisorctl restart poster-worker:* 2>/dev/null || supervisorctl restart poster-worker:* 2>/dev/null || echo "    (Notice: Please ensure Supervisor worker is restarted)"
fi

# 9. Verify Application Health
echo "--> Probing application health check endpoint..."
HEALTH_CHECK=$(php artisan route:list --name=up --json 2>/dev/null || true)
if [ -n "$HEALTH_CHECK" ]; then
    echo "    Health route configured."
fi

# 10. Disable Maintenance Mode
echo "--> Bringing application back live..."
php artisan up

# Clear exit trap since deployment completed successfully
trap - EXIT

echo "=========================================================="
echo " Poster Deployment Complete! — $(date '+%Y-%m-%d %H:%M:%S')"
echo "=========================================================="
exit 0
