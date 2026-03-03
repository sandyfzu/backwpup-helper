/**
 * BackWPup Helper — Admin bar interactions
 *
 * Handles: backup clearing, big-backup toggle, debug monitor toggle,
 * debug log polling, change-detection (pulsing dot), log viewer modal,
 * and debug log deletion.
 *
 * Dependencies: None (pure vanilla JS). Requires the `bwh_ajax` localized
 * object provided by BWH_Main::enqueue_assets().
 */
(function () {
    'use strict';

    /* ================================================================
     * Helpers
     * ================================================================ */

    /**
     * Get the <a> inside a WP admin-bar node by its ID suffix.
     * @param {string} id - Node ID without the 'wp-admin-bar-' prefix.
     * @returns {HTMLAnchorElement|null}
     */
    function getAnchor(id) {
        var node = document.getElementById('wp-admin-bar-' + id);
        if (!node) return null;
        return node.querySelector('a');
    }

    /**
     * Get the <li> wrapper of an admin-bar node.
     * @param {string} id
     * @returns {HTMLElement|null}
     */
    function getBarNode(id) {
        return document.getElementById('wp-admin-bar-' + id);
    }

    /**
     * Render a coloured state tag inside an anchor.
     * @param {HTMLAnchorElement} anchor
     * @param {string}           label  - Prefix text (e.g. "Big backup")
     * @param {string}           state  - 'active' or 'inactive'
     */
    function setStateText(anchor, label, state) {
        if (!anchor) return;
        var span = document.createElement('span');
        span.className = 'bwh-state ' + (state === 'active' ? 'bwh-active' : 'bwh-inactive');
        span.textContent = state;
        anchor.textContent = label + ': ';
        anchor.appendChild(span);
    }

    /**
     * Build a FormData with the AJAX action name and the shared nonce.
     * @param {string} action - WordPress AJAX action name.
     * @returns {FormData}
     */
    function makeForm(action) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', bwh_ajax.nonce);
        return fd;
    }

    /**
     * POST to admin-ajax.php and parse JSON.
     * @param {FormData} form
     * @returns {Promise<Object>}
     */
    function post(form) {
        return fetch(bwh_ajax.ajax_url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    /**
     * Show a transient toast message.
     * @param {string} msg
     */
    function showMessage(msg) {
        var el = document.createElement('div');
        el.className = 'bwh-msg';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 3000);
    }

    /* ================================================================
     * Main init
     * ================================================================ */

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof bwh_ajax === 'undefined') return;

        /* ── References ── */
        var clearAnchor         = getAnchor('bwh_clear');
        var bigBackupAnchor     = getAnchor('bwh_bigbackup');
        var debugMonitorAnchor  = getAnchor('bwh_debug_monitor');
        var debugLogAnchor      = getAnchor('bwh_debug_log');
        var deleteLogAnchor     = getAnchor('bwh_delete_debug_log');
        var debugLogNode        = getBarNode('bwh_debug_log');
        var deleteLogNode       = getBarNode('bwh_delete_debug_log');
        var rootAnchor          = getAnchor('bwh_root');

        /* ── State ── */
        var monitorActive   = bwh_ajax.debug_monitor === 'active';
        var lastFingerprint = '';
        var pollTimer       = null;
        var pulsingDot      = null; // reference to the dot element on root

        /* ================================================================
         * 1. Big backup toggle
         * ================================================================ */

        setStateText(bigBackupAnchor, 'Big backup', bwh_ajax.state || 'inactive');

        if (bigBackupAnchor) {
            bigBackupAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                post(makeForm('bwh_toggle_big_backup'))
                    .then(function (data) {
                        if (data && data.success && data.data && data.data.state) {
                            setStateText(bigBackupAnchor, 'Big backup', data.data.state);
                            showMessage('Big backup set to ' + data.data.state + '.');
                        } else {
                            showMessage('Could not toggle state.');
                        }
                    })
                    .catch(function () { showMessage('Request failed.'); });
            });
        }

        /* ================================================================
         * 2. Clear backup data (with confirmation modal)
         * ================================================================ */

        function createConfirmModal() {
            if (document.getElementById('bwh-confirm-modal')) return;

            var overlay = document.createElement('div');
            overlay.className = 'bwh-modal-overlay';

            var modal = document.createElement('div');
            modal.id = 'bwh-confirm-modal';
            modal.className = 'bwh-modal-container';
            modal.style.display = 'none';

            var box = document.createElement('div');
            box.className = 'bwh-modal';

            var body = document.createElement('div');
            body.className = 'bwh-modal-body';

            var p = document.createElement('p');
            p.textContent = 'Are you sure you want to permanently remove BackWPup backup folders from uploads?';

            var actions = document.createElement('div');
            actions.className = 'bwh-modal-actions';

            var btnConfirm = document.createElement('button');
            btnConfirm.className = 'bwh-btn bwh-confirm';
            btnConfirm.textContent = 'Remove';

            var btnCancel = document.createElement('button');
            btnCancel.className = 'bwh-btn bwh-cancel';
            btnCancel.textContent = 'Cancel';

            actions.appendChild(btnCancel);
            actions.appendChild(btnConfirm);
            body.appendChild(p);
            body.appendChild(actions);
            box.appendChild(body);
            modal.appendChild(overlay);
            modal.appendChild(box);
            document.body.appendChild(modal);

            function closeModal() { modal.style.display = 'none'; }

            btnCancel.addEventListener('click', closeModal);
            overlay.addEventListener('click', closeModal);
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && modal.style.display !== 'none') closeModal();
            });

            btnConfirm.addEventListener('click', function () {
                closeModal();
                post(makeForm('bwh_clear_backups'))
                    .then(function (data) {
                        if (data && data.success) {
                            showMessage('Backups cleared.');
                        } else {
                            showMessage('Nothing to remove or error occurred.');
                        }
                    })
                    .catch(function () { showMessage('Request failed.'); });
            });
        }

        if (clearAnchor) {
            clearAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                createConfirmModal();
                var modal = document.getElementById('bwh-confirm-modal');
                if (modal) modal.style.display = 'block';
            });
        }

        /* ================================================================
         * 3. Debug monitor
         * ================================================================ */

        /* ── 3a. Pulsing indicator dot on the top-level root item ── */

        function ensurePulsingDot() {
            if (pulsingDot || !rootAnchor) return;
            pulsingDot = document.createElement('span');
            pulsingDot.className = 'bwh-pulse-dot';
            // Native title attribute → browser provides a built-in tooltip.
            pulsingDot.title = 'Debug log has changed since last viewed';
            pulsingDot.style.display = 'none';
            rootAnchor.appendChild(pulsingDot);
        }

        function showPulsingDot() {
            ensurePulsingDot();
            if (pulsingDot) pulsingDot.style.display = '';
        }

        function hidePulsingDot() {
            if (pulsingDot) pulsingDot.style.display = 'none';
        }

        /* ── 3b. Debug log sub-item visibility ── */

        /**
         * Update the debug log sub-items based on monitor state and log status.
         * @param {Object|null} logStatus - Result from get_debug_log_status or null.
         */
        function updateDebugLogUI(logStatus) {
            if (!debugLogNode || !deleteLogNode) return;

            // Hide both items when monitor is inactive.
            if (!monitorActive) {
                debugLogNode.style.display = 'none';
                deleteLogNode.style.display = 'none';
                return;
            }

            // Monitor is active — show log status.
            debugLogNode.style.display = '';

            if (!logStatus || !logStatus.exists || logStatus.size === 0) {
                // Log is empty or missing.
                if (debugLogAnchor) {
                    debugLogAnchor.textContent = '';
                    var label = document.createTextNode('Debug log: ');
                    var span = document.createElement('span');
                    span.className = 'bwh-state bwh-active';
                    span.textContent = 'clear';
                    debugLogAnchor.appendChild(label);
                    debugLogAnchor.appendChild(span);
                    debugLogAnchor.style.cursor = 'default';
                }
                deleteLogNode.style.display = 'none';
            } else {
                // Log has content — show size in amber, make clickable.
                if (debugLogAnchor) {
                    debugLogAnchor.textContent = '';
                    var lbl = document.createTextNode('Debug log: ');
                    var sz = document.createElement('span');
                    sz.className = 'bwh-state bwh-amber';
                    sz.textContent = logStatus.size_human;
                    debugLogAnchor.appendChild(lbl);
                    debugLogAnchor.appendChild(sz);
                    debugLogAnchor.style.cursor = 'pointer';
                }
                deleteLogNode.style.display = '';
            }
        }

        /* ── 3c. Polling ── */

        function doPoll() {
            if (!monitorActive) return;

            post(makeForm('bwh_debug_log_status'))
                .then(function (data) {
                    if (!data || !data.success) return;

                    var s = data.data;
                    updateDebugLogUI(s);

                    var newFP = s.fingerprint || '';
                    if (lastFingerprint && newFP && newFP !== lastFingerprint) {
                        showPulsingDot();
                    }
                    lastFingerprint = newFP;
                })
                .catch(function () { /* silent — next poll will retry */ });
        }

        function startPolling() {
            stopPolling();
            var interval = (bwh_ajax.poll_interval || 10) * 1000;
            pollTimer = setInterval(doPoll, interval);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        /* ── 3d. Monitor toggle handler ── */

        setStateText(debugMonitorAnchor, 'Debug monitor', monitorActive ? 'active' : 'inactive');

        if (debugMonitorAnchor) {
            debugMonitorAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                post(makeForm('bwh_toggle_debug_monitor'))
                    .then(function (data) {
                        if (!data || !data.success || !data.data) {
                            showMessage('Could not toggle debug monitor.');
                            return;
                        }
                        var newState = data.data.state;
                        monitorActive = newState === 'active';
                        setStateText(debugMonitorAnchor, 'Debug monitor', newState);
                        showMessage('Debug monitor set to ' + newState + '.');

                        if (monitorActive) {
                            var ls = data.data.log_status || null;
                            lastFingerprint = ls ? (ls.fingerprint || '') : '';
                            updateDebugLogUI(ls);
                            startPolling();
                        } else {
                            stopPolling();
                            hidePulsingDot();
                            lastFingerprint = '';
                            updateDebugLogUI(null);
                        }
                    })
                    .catch(function () { showMessage('Request failed.'); });
            });
        }

        /* ── 3e. Initial state on page load ── */

        if (monitorActive && bwh_ajax.debug_log_status) {
            lastFingerprint = bwh_ajax.debug_log_status.fingerprint || '';
            updateDebugLogUI(bwh_ajax.debug_log_status);
            startPolling();
        } else {
            updateDebugLogUI(null);
        }

        /* ── 3f. Debug log viewer (click to open) ── */

        if (debugLogAnchor) {
            debugLogAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                // Only open when monitor is active and log has content.
                if (!monitorActive) return;
                if (debugLogAnchor.style.cursor === 'default') return;

                openLogViewer();
            });
        }

        function openLogViewer() {
            // Build or reuse modal
            var existing = document.getElementById('bwh-logviewer-modal');
            if (existing) existing.parentNode.removeChild(existing);

            var container = document.createElement('div');
            container.id = 'bwh-logviewer-modal';
            container.className = 'bwh-logviewer-container';

            var overlay = document.createElement('div');
            overlay.className = 'bwh-logviewer-overlay';

            var panel = document.createElement('div');
            panel.className = 'bwh-logviewer-panel';

            // Header
            var header = document.createElement('div');
            header.className = 'bwh-logviewer-header';

            var title = document.createElement('span');
            title.className = 'bwh-logviewer-title';
            title.textContent = 'Debug Log Viewer';

            var meta = document.createElement('span');
            meta.className = 'bwh-logviewer-meta';
            meta.textContent = 'Loading\u2026';

            var headerActions = document.createElement('span');
            headerActions.className = 'bwh-logviewer-header-actions';

            var copyBtn = document.createElement('button');
            copyBtn.className = 'bwh-logviewer-btn';
            copyBtn.textContent = 'Copy';
            copyBtn.title = 'Copy log content to clipboard';

            var closeBtn = document.createElement('button');
            closeBtn.className = 'bwh-logviewer-btn bwh-logviewer-close';
            closeBtn.textContent = '\u2715';
            closeBtn.title = 'Close';

            headerActions.appendChild(copyBtn);
            headerActions.appendChild(closeBtn);
            header.appendChild(title);
            header.appendChild(meta);
            header.appendChild(headerActions);

            // Body
            var body = document.createElement('div');
            body.className = 'bwh-logviewer-body';

            var pre = document.createElement('pre');
            pre.className = 'bwh-logviewer-content';
            pre.textContent = 'Loading\u2026';

            body.appendChild(pre);

            // Scroll-to-bottom button
            var scrollBtn = document.createElement('button');
            scrollBtn.className = 'bwh-logviewer-scroll-bottom';
            scrollBtn.textContent = '\u2193 Bottom';
            scrollBtn.title = 'Scroll to bottom';
            body.appendChild(scrollBtn);

            panel.appendChild(header);
            panel.appendChild(body);
            container.appendChild(overlay);
            container.appendChild(panel);
            document.body.appendChild(container);

            // Close handlers
            function closeViewer() {
                if (container.parentNode) container.parentNode.removeChild(container);
            }

            overlay.addEventListener('click', closeViewer);
            closeBtn.addEventListener('click', closeViewer);
            document.addEventListener('keydown', function onEsc(ev) {
                if (ev.key === 'Escape') {
                    closeViewer();
                    document.removeEventListener('keydown', onEsc);
                }
            });

            // Copy handler
            copyBtn.addEventListener('click', function () {
                var text = pre.textContent || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        showMessage('Copied to clipboard.');
                    }).catch(function () {
                        showMessage('Copy failed.');
                    });
                } else {
                    // Fallback for older contexts (HTTP, older browsers)
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        showMessage('Copied to clipboard.');
                    } catch (_) {
                        showMessage('Copy failed.');
                    }
                }
            });

            // Scroll-to-bottom handler
            scrollBtn.addEventListener('click', function () {
                body.scrollTop = body.scrollHeight;
            });

            // Fetch content
            post(makeForm('bwh_debug_log_content'))
                .then(function (data) {
                    if (!data || !data.success || !data.data) {
                        pre.textContent = 'Error: could not load log content.';
                        meta.textContent = '';
                        return;
                    }
                    var d = data.data;
                    pre.textContent = d.content || '(empty)';
                    var metaParts = [d.total_size || ''];
                    if (d.truncated) {
                        metaParts.push('showing last 512 KB');
                    }
                    meta.textContent = metaParts.filter(Boolean).join(' \u2014 ');

                    // Scroll to bottom (newest entries)
                    requestAnimationFrame(function () {
                        body.scrollTop = body.scrollHeight;
                    });
                })
                .catch(function () {
                    pre.textContent = 'Request failed.';
                    meta.textContent = '';
                });

            // Acknowledge changes — hide pulsing dot.
            hidePulsingDot();
        }

        /* ── 3g. Delete debug log ── */

        if (deleteLogAnchor) {
            deleteLogAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                post(makeForm('bwh_delete_debug_log'))
                    .then(function (data) {
                        if (data && data.success && data.data) {
                            var r = data.data.result;
                            if (r === 'deleted') {
                                showMessage('Debug log deleted.');
                            } else if (r === 'not_found') {
                                showMessage('No debug log to delete.');
                            }
                            lastFingerprint = '';
                            hidePulsingDot();
                            updateDebugLogUI(data.data.log_status || null);
                        } else {
                            showMessage('Could not delete debug log.');
                        }
                    })
                    .catch(function () { showMessage('Request failed.'); });
            });
        }
    });

})();
