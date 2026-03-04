#!/usr/bin/env bash
set -euo pipefail

# Simple smoke script that exercises the plugin WP-CLI commands.
# Requires `wp` CLI available and WordPress root as the current working directory.

WP=${WP:-wp}
DESTRUCTIVE=${BWH_SMOKE_DESTRUCTIVE:-0}

cleanup() {
	# Restore original states if they changed.
	if [[ "${BIGBACKUP_TOGGLED:-0}" == "1" ]]; then
		${WP} bwh bigbackup toggle >/dev/null 2>&1 || true
	fi

	if [[ "${DEBUGMONITOR_TOGGLED:-0}" == "1" ]]; then
		${WP} bwh debugmonitor toggle >/dev/null 2>&1 || true
	fi
}

trap cleanup EXIT

BIGBACKUP_TOGGLED=0
DEBUGMONITOR_TOGGLED=0

echo "Checking WP-CLI availability..."
${WP} --info >/dev/null 2>&1 || { echo "wp command not available."; exit 2; }

echo "Checking plugin active state..."
${WP} plugin is-active backwpup-helper >/dev/null 2>&1 || { echo "Plugin is not active. Activate it first."; exit 2; }

echo
echo "=== backups ==="
echo "Dry-run clear (no deletion):"
${WP} bwh backups clear --dry-run

echo
echo "=== bigbackup ==="
echo "Current status:"
ORIG_BIGBACKUP="$(${WP} bwh bigbackup status)"
echo "${ORIG_BIGBACKUP}"

echo "Toggle once:"
${WP} bwh bigbackup toggle
BIGBACKUP_TOGGLED=1

echo "Status after toggle (should change):"
${WP} bwh bigbackup status

echo
echo "=== debugmonitor ==="
echo "Current status:"
ORIG_DEBUGMONITOR="$(${WP} bwh debugmonitor status)"
echo "${ORIG_DEBUGMONITOR}"

echo "Toggle once:"
${WP} bwh debugmonitor toggle
DEBUGMONITOR_TOGGLED=1

echo "Status after toggle (should change):"
${WP} bwh debugmonitor status

echo
echo "=== debuglog ==="
echo "Debug log status:"
${WP} bwh debuglog status

echo "View debug log (may be empty/clear depending on environment):"
# Never fail smoke run if view reports no file; command may legitimately error in clean envs.
${WP} bwh debuglog view >/dev/null 2>&1 || echo "debuglog view not available (missing/empty file) - acceptable for smoke test"

if [[ "${DESTRUCTIVE}" == "1" ]]; then
	echo "Destructive mode enabled: deleting debug log"
	# Deletion is environment-dependent; do not fail if file doesn't exist.
	${WP} bwh debuglog delete >/dev/null 2>&1 || true
	${WP} bwh debuglog status
else
	echo "Skipping debuglog delete (set BWH_SMOKE_DESTRUCTIVE=1 to enable)."
fi

echo "Smoke WP-CLI tests completed."
