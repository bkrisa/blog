#!/bin/bash
#
# Usage:
#   ./update.sh
#
# Recommended cron entry (checks every 15 minutes):
#   */15 * * * * /path/to/openblog/update.sh >> /var/log/openblog-update.log 2>&1

set -e

cd "$(dirname "$0")"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Checking for updates..."

# Pull the latest changes from the remote repository
if git pull origin main --ff-only; then
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Update complete."
else
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Update failed - local branch has diverged from origin/main. Resolve manually."
  exit 1
fi