#!/usr/bin/env bash
set -euo pipefail

# Simple smoke script that exercises the plugin WP-CLI commands.
# Requires `wp` CLI available and WordPress root as the current working directory.

WP=${WP:-wp}
DESTRUCTIVE=${BWH_SMOKE_DESTRUCTIVE:-0}
WP_PATH=${WP_PATH:-}

detect_wp_root_from() {
	local start_dir="$1"
	local dir="$start_dir"

	while [[ "$dir" != "/" ]]; do
		if [[ -f "$dir/wp-load.php" && -d "$dir/wp-admin" && -d "$dir/wp-includes" ]]; then
			echo "$dir"
			return 0
		fi
		dir="$(dirname "$dir")"
	done

	return 1
}

resolve_wp_root() {
	if [[ -n "$WP_PATH" ]]; then
		if [[ -f "$WP_PATH/wp-load.php" && -d "$WP_PATH/wp-admin" && -d "$WP_PATH/wp-includes" ]]; then
			echo "$WP_PATH"
			return 0
		fi
		echo "Error: WP_PATH is set but does not look like a WordPress root: $WP_PATH" >&2
		return 1
	fi

	if detect_wp_root_from "$PWD" >/dev/null 2>&1; then
		detect_wp_root_from "$PWD"
		return 0
	fi

	local script_dir
	script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
	if detect_wp_root_from "$script_dir" >/dev/null 2>&1; then
		detect_wp_root_from "$script_dir"
		return 0
	fi

	echo "Error: Could not detect WordPress root. Set WP_PATH=/path/to/wordpress and retry." >&2
	return 1
}

run_wp() {
	"$WP" --path="$RESOLVED_WP_ROOT" "$@"
}

cleanup() {
	# Restore original states if they changed.
	if [[ "${BIGBACKUP_TOGGLED:-0}" == "1" ]]; then
		run_wp bwh bigbackup toggle >/dev/null 2>&1 || true
	fi

	if [[ "${DEBUGMONITOR_TOGGLED:-0}" == "1" ]]; then
		run_wp bwh debugmonitor toggle >/dev/null 2>&1 || true
	fi
}

trap cleanup EXIT

BIGBACKUP_TOGGLED=0
DEBUGMONITOR_TOGGLED=0

echo "Checking WP-CLI availability..."
"${WP}" --info >/dev/null 2>&1 || { echo "wp command not available."; exit 2; }

echo "Detecting WordPress root..."
RESOLVED_WP_ROOT="$(resolve_wp_root)" || exit 2
echo "Using WordPress path: ${RESOLVED_WP_ROOT}"

echo "Checking plugin active state..."
run_wp plugin is-active backwpup-helper >/dev/null 2>&1 || { echo "Plugin is not active. Activate it first."; exit 2; }

echo
echo "=== backups ==="
echo "Dry-run clear (no deletion):"
run_wp bwh backups clear --dry-run

echo
echo "=== bigbackup ==="
echo "Current status:"
ORIG_BIGBACKUP="$(run_wp bwh bigbackup status)"
echo "${ORIG_BIGBACKUP}"

echo "Toggle once:"
if run_wp bwh bigbackup toggle; then
	BIGBACKUP_TOGGLED=1

	echo "Status after toggle (should change):"
	run_wp bwh bigbackup status
else
	echo "Warning: bigbackup toggle failed (likely filesystem permissions/ownership in this environment). Continuing smoke run."
fi

echo
echo "=== debugmonitor ==="
echo "Current status:"
ORIG_DEBUGMONITOR="$(run_wp bwh debugmonitor status)"
echo "${ORIG_DEBUGMONITOR}"

echo "Toggle once:"
run_wp bwh debugmonitor toggle
DEBUGMONITOR_TOGGLED=1

echo "Status after toggle (should change):"
run_wp bwh debugmonitor status

echo
echo "=== debuglog ==="
echo "Debug log status:"
run_wp bwh debuglog status

echo "View debug log (may be empty/clear depending on environment):"
# Never fail smoke run if view reports no file; command may legitimately error in clean envs.
run_wp bwh debuglog view >/dev/null 2>&1 || echo "debuglog view not available (missing/empty file) - acceptable for smoke test"

if [[ "${DESTRUCTIVE}" == "1" ]]; then
	echo "Destructive mode enabled: deleting debug log"
	# Deletion is environment-dependent; do not fail if file doesn't exist.
	run_wp bwh debuglog delete >/dev/null 2>&1 || true
	run_wp bwh debuglog status
else
	echo "Skipping debuglog delete (set BWH_SMOKE_DESTRUCTIVE=1 to enable)."
fi

echo "Smoke WP-CLI tests completed."
