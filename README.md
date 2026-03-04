# BackWPup Helper

BackWPup Helper provides small, focused developer and testing utilities that make it easy
to prepare, inspect, and manipulate BackWPup state during development and automated tests.
It exposes a discreet admin-topbar entry and WP-CLI helpers so you can quickly clear
local backup folders and toggle the Big backup flag without editing files by hand.

## Compatibility

- PHP: 7.4 — 8.5
- WordPress: 6.9+

## Features

- Developer/testing helpers exposed in the admin bar (visible to users with `manage_options`).
- Quickly clear BackWPup backup folders (`uploads/backwpup`, `uploads/backwpup-restore`) for repeatable test setups.
- Toggle the `wp-content/bigFiles/.donotbackup` flag to simulate Big backup enabled/disabled states.
- **Debug monitor** — watch the WordPress `debug.log` file in real time from the admin bar:
  - Toggle monitoring on/off (persisted in the database via the Options API).
  - Automatic polling detects new log entries and shows a pulsing amber dot on the top-level admin bar item with a tooltip.
  - The debug log row shows `Debug log: nothing in logs` in a dimmed/deactivated visual state when the file is missing or empty.
  - When changes are detected, the row displays a pulsing `changed` indicator next to the log size, and a clickable toast is shown (`click to view`).
  - Change-indicator state is persisted in the browser (with a 1-hour validity window) so reloads keep consistent behavior.
  - Click to open a full log viewer modal (dark terminal theme, scrollable, selectable, with Copy and Scroll-to-bottom buttons). File content is read server-side via PHP, not fetched over HTTP.
  - Delete the debug log file directly from the admin bar.
  - Large files (> 512 KB) are safely truncated—only the last 512 KB is shown.
- A confirmation modal prevents accidental removals during exploratory testing.
- WP-CLI helpers for scripting and CI-friendly test flows.

## Security & Best Practices

- Actions are restricted to users with the `manage_options` capability.
- AJAX requests use a nonce (`bwh_nonce`) checked server-side.
- File operations prefer `WP_Filesystem` when available for compatibility with FTP/SSH transports; fallback to SPL iterators for direct filesystem access.

## Installation

1. Copy the `backwpup-helper` folder into `wp-content/plugins/` or **simply** download the zip from the [releases](https://github.com/sandyfzu/backwpup-helper/releases) page in the GitHub repository and install it in your WordPress as any other plugin.
2. Activate the plugin from the WordPress admin Plugins screen or via WP-CLI:

```bash
wp plugin activate backwpup-helper
```

## Usage

- Use the admin bar entry while logged in as a user with `manage_options` to access quick test actions.
- Prefer the WP-CLI commands when scripting tests or running in CI; the CLI commands are easier to assert in automated flows.

## WP-CLI

### Backups

- `wp bwh backups clear [--dry-run]` — clears `backwpup` folders under uploads; use `--dry-run` to preview.

### Big backup

- `wp bwh bigbackup status` — prints `active` or `inactive` depending on the presence of `wp-content/bigFiles/.donotbackup`.
- `wp bwh bigbackup toggle` — toggles the `.donotbackup` flag.

### Debug monitor

- `wp bwh debugmonitor status` — prints `active` or `inactive`.
- `wp bwh debugmonitor toggle` — toggles the debug monitor option.

### Debug log

- `wp bwh debuglog status` — prints `clear` or the human-readable file size.
- `wp bwh debuglog view` — outputs the log content to stdout (tail, max 512 KB).
- `wp bwh debuglog delete` — deletes the debug log file.

## About the Big backup flag

BackWPup uses a simple, opt-out mechanism to skip directories during backup: if a directory contains a file named `.donotbackup`, BackWPup will ignore that directory and its contents when creating backups. This plugin exposes a convenient toggle that manages a flag file at `wp-content/bigFiles/.donotbackup` so you can simulate both smaller and larger backup runs.

- When the flag file **exists** (`.donotbackup` present) the `bigFiles` directory is excluded from backups (Big backup is *inactive*).
- When the flag file **does not exist** the `bigFiles` directory is included in backups (Big backup is *active*), which will make the backup significantly larger if the directory actually contains large files.

Important: this toggle only affects backup size if `wp-content/bigFiles/` actually contains large files. If the directory is empty or only has small items, toggling the flag won't materially change the backup. Use the toggle to prepare reproducible test scenarios where you need to include or exclude heavy assets during BackWPup runs.

You can create some full backups with BackWPup and move them to `wp-content/bigFiles/` to get large files.

## Examples

```bash
# Show current big backup state
$ wp bwh bigbackup status
active

# Toggle the flag
$ wp bwh bigbackup toggle
Success: Big backup -> inactive

# Dry-run clear (no deletion)
$ wp bwh backups clear --dry-run
Would remove: /path/to/wp-content/uploads/backwpup
Would remove: /path/to/wp-content/uploads/backwpup-restore

# Actual clear
$ wp bwh backups clear
Success: Removed: /path/to/wp-content/uploads/backwpup
Success: Removed: /path/to/wp-content/uploads/backwpup-restore

# Debug monitor
$ wp bwh debugmonitor status
inactive
$ wp bwh debugmonitor toggle
Success: Debug monitor -> active

# Debug log
$ wp bwh debuglog status
45.2 KB (1741012345-46283)
$ wp bwh debuglog view | tail -5
[03-Mar-2026 14:23:05 UTC] PHP Warning: ...
$ wp bwh debuglog delete
Success: Debug log deleted.
```

## Testing

- A smoke script is included at `tests/smoke-wpcli.sh` to exercise the CLI helpers (requires the `wp` CLI and running from the WordPress root).
- For unit or integration tests, call `BWH_Service` methods directly to simulate filesystem behavior in isolation.

## Implementation notes

- Core logic is in `includes/class-backwpup-service.php` (class `BWH_Service`) to avoid duplication between web and CLI code and avoid naming conflicts with BackWPup.
- Admin UI assets are in `assets/js/admin.js` and `assets/css/admin.css`.
- CLI glue is in `includes/class-backwpup-commands.php` and web/AJAX handlers in `includes/class-backwpup-helper.php`.
- The debug monitor option is stored using the WordPress Options API (`bwh_debug_monitor`). It is cleaned up on plugin deletion via `uninstall.php`.
- Debug log path resolution respects `WP_DEBUG_LOG` when set to a custom string in `wp-config.php`; otherwise defaults to `wp-content/debug.log`.
- File fingerprinting uses `filemtime()` + `filesize()` (single OS stat call, zero file reads) for lightweight change detection during polling.
- Backup directory size info is cached with WordPress transients for 20 seconds, and refreshed on hover with a 20-second client cooldown.
- Client-side change-indicator acknowledgement is persisted with `localStorage` for up to 1 hour.

## References

- WP_Filesystem: [https://developer.wordpress.org/apis/handbook/filesystem/](https://developer.wordpress.org/apis/handbook/filesystem/)
- AJAX in plugins: [https://developer.wordpress.org/plugins/javascript/ajax/](https://developer.wordpress.org/plugins/javascript/ajax/)
- Admin bar API: [https://developer.wordpress.org/reference/classes/wp_admin_bar/](https://developer.wordpress.org/reference/classes/wp_admin_bar/)
- WP_Admin_Bar::add_group(): [https://developer.wordpress.org/reference/classes/wp_admin_bar/add_group/](https://developer.wordpress.org/reference/classes/wp_admin_bar/add_group/)
- Options API: [https://developer.wordpress.org/plugins/settings/options-api/](https://developer.wordpress.org/plugins/settings/options-api/)
- Transients API: [https://developer.wordpress.org/apis/transients/](https://developer.wordpress.org/apis/transients/)
- WP_DEBUG_LOG: [https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/](https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/)
- PHP fseek: [https://www.php.net/manual/en/function.fseek.php](https://www.php.net/manual/en/function.fseek.php)
- Web Storage API (`localStorage`): [https://developer.mozilla.org/docs/Web/API/Window/localStorage](https://developer.mozilla.org/docs/Web/API/Window/localStorage)
