# BackWPup Helper

BackWPup Helper provides small, focused developer and testing utilities that make it easy
to prepare, inspect, and manipulate BackWPup state during development and automated tests.
It exposes a discreet admin-topbar entry and WP-CLI helpers so you can quickly clear
local backup folders and toggle the Big backup flag without editing files by hand.

Compatibility

- PHP: 7.4 — 8.5
- WordPress: 6.9+

Features

- Developer/testing helpers exposed in the admin bar (visible to users with `manage_options`).
- Quickly clear BackWPup backup folders (`uploads/backwpup`, `uploads/backwpup-restore`) for repeatable test setups.
- Toggle the `wp-content/bigFiles/.donotbackup` flag to simulate Big backup enabled/disabled states.
- A confirmation modal prevents accidental removals during exploratory testing.
- WP-CLI helpers for scripting and CI-friendly test flows: `wp bwh bigbackup status`, `wp bwh bigbackup toggle`, `wp bwh backups clear [--dry-run]`.

Security & Best Practices

- Actions are restricted to users with the `manage_options` capability.
- AJAX requests use a nonce (`bwh_nonce`) checked server-side.
- File operations prefer `WP_Filesystem` when available for compatibility with FTP/SSH transports; fallback to SPL iterators for direct filesystem access.

Installation

1. Copy the `backwpup-helper` folder into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin Plugins screen or via WP-CLI:

```bash
wp plugin activate backwpup-helper
```


Usage

- Use the admin bar entry while logged in as a user with `manage_options` to access quick test actions.
- Prefer the WP-CLI commands when scripting tests or running in CI; the CLI commands are easier to assert in automated flows.

WP-CLI

- `wp bwh bigbackup status` — prints `active` or `inactive` depending on the presence of `wp-content/bigFiles/.donotbackup`.
- `wp bwh bigbackup toggle` — toggles the `.donotbackup` flag.
- `wp bwh backups clear [--dry-run]` — clears `backwpup` folders under uploads; use `--dry-run` to preview.

Examples

```bash
# Show current big backup state
$ wp bwh bigbackup status
active

# Toggle the flag
$ wp bwh bigbackup toggle
Success: Big backup -> inactive

# Verify state after toggle
$ wp bwh bigbackup status
inactive

# Dry-run clear (no deletion)
$ wp bwh backups clear --dry-run
Would remove: /path/to/wp-content/uploads/backwpup
Would remove: /path/to/wp-content/uploads/backwpup-restore

# Actual clear (removes directories)
$ wp bwh backups clear
Success: Removed: /path/to/wp-content/uploads/backwpup
Success: Removed: /path/to/wp-content/uploads/backwpup-restore
```


Testing

- A smoke script is included at `tests/smoke-wpcli.sh` to exercise the CLI helpers (requires the `wp` CLI and running from the WordPress root).
- For unit or integration tests, call `BWH_Service` methods directly to simulate filesystem behavior in isolation.

Implementation notes

- Core logic is in `includes/class-backwpup-service.php` (class `BWH_Service`) to avoid duplication between web and CLI code and avoid naming conflicts with BackWPup.
- Admin UI assets are in `assets/js/admin.js` and `assets/css/admin.css`.
- CLI glue is in `includes/class-backwpup-commands.php` and web/AJAX handlers in `includes/class-backwpup-helper.php`.

References

- WP_Filesystem: [https://developer.wordpress.org/apis/handbook/filesystem/](https://developer.wordpress.org/apis/handbook/filesystem/)
- AJAX in plugins: [https://developer.wordpress.org/plugins/javascript/ajax/](https://developer.wordpress.org/plugins/javascript/ajax/)
- Admin bar API: [https://developer.wordpress.org/reference/classes/wp_admin_bar/](https://developer.wordpress.org/reference/classes/wp_admin_bar/)
