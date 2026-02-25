#!/usr/bin/env bash
set -euo pipefail

# Simple smoke script that exercises the plugin WP-CLI commands.
# Requires `wp` CLI available and WordPress root as the current working directory.

WP=${WP:-wp}

echo "Checking plugin active state..."
${WP} plugin is-active backwpup-helper >/dev/null 2>&1 || { echo "Plugin is not active. Activate it first."; exit 2; }

echo "Big backup status:"
${WP} bwh status

echo "Toggling big backup state..."
${WP} bwh toggle

echo "Status after toggle:"
${WP} bwh status

echo "Dry-run clear (no deletion):"
${WP} bwh clear --dry-run

echo "Smoke WP-CLI tests completed."
