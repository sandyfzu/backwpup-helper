# BackWPup Helper

Lightweight WordPress plugin that adds an admin-topbar item with a hover submenu to manage BackWPup Helper backup folders and toggle the "Big backup" flag.

Compatibility

- PHP: 7.4 — 8.5
- WordPress: 6.9+

Features

- Admin top bar entry `BackWPup` (visible to users with `manage_options`).
- Submenu items:
  - `Clear backup data` — removes `uploads/backwpup` and `uploads/backwpup-restore` recursively.
  - `Big backup: active|inactive` — toggles the `wp-content/bigFiles/.donotbackup` flag file.
- Confirmation modal for the clear action.
- WP-CLI commands: `wp bwh bigbackup status`, `wp bwh bigbackup toggle`, `wp bwh backups clear [--dry-run]`.

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

- Visit the front-end or admin while logged in as an administrator; the admin bar will show `BackWPup`.
- Hover it to reveal submenu and use the provided actions.

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

- A simple smoke script is included at `tests/smoke-wpcli.sh` (requires the `wp` CLI and running from the WordPress root).
- For automated unit testing, set up the WordPress PHPUnit test suite (not included). Use `BWH_Service` methods for isolated testing of filesystem behavior.

Implementation notes

- Core logic is in `includes/class-backwpup-service.php` (class `BWH_Service`) to avoid duplication between web and CLI code and avoid naming conflicts with BackWPup.
- Admin UI assets are in `assets/js/admin.js` and `assets/css/admin.css`.
- CLI glue is in `includes/class-backwpup-commands.php` and web/AJAX handlers in `includes/class-backwpup-helper.php`.

References

- WP_Filesystem: [https://developer.wordpress.org/apis/handbook/filesystem/](https://developer.wordpress.org/apis/handbook/filesystem/)
- AJAX in plugins: [https://developer.wordpress.org/plugins/javascript/ajax/](https://developer.wordpress.org/plugins/javascript/ajax/)
- Admin bar API: [https://developer.wordpress.org/reference/classes/wp_admin_bar/](https://developer.wordpress.org/reference/classes/wp_admin_bar/)
