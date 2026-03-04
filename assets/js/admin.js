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
     * Get the direct admin-bar item element (<a> or <div>) for a node.
     *
     * Important for root items where href=false, because WordPress renders
     * a non-anchor `.ab-item`. We must avoid querying descendant submenu links.
     *
     * @param {string} id
     * @returns {HTMLElement|null}
     */
    function getBarItem(id) {
        var node = getBarNode(id);
        if (!node) return null;

        var children = node.children;
        for (var i = 0; i < children.length; i++) {
            if (children[i].classList && children[i].classList.contains('ab-item')) {
                return children[i];
            }
        }

        return null;
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
     * Ensure the singleton toast stack container exists.
     * @returns {HTMLElement}
     */
    function ensureToastStack() {
        var existing = document.getElementById('bwh-toast-stack');
        if (existing) return existing;

        var stack = document.createElement('div');
        stack.id = 'bwh-toast-stack';
        stack.className = 'bwh-toast-stack';
        document.body.appendChild(stack);
        return stack;
    }

    /**
     * Capture current toast top positions for FLIP animations.
     * @param {HTMLElement} container
     * @returns {Object<string, number>}
     */
    function captureToastPositions(container) {
        var map = {};
        var items = container.children;
        for (var i = 0; i < items.length; i++) {
            var el = items[i];
            var id = el.getAttribute('data-toast-id');
            if (id) {
                map[id] = el.getBoundingClientRect().top;
            }
        }
        return map;
    }

    /**
     * Animate existing toast vertical movement after insert/remove.
     * @param {HTMLElement} container
     * @param {Object<string, number>} before
     */
    function animateToastStack(container, before) {
        var items = container.children;
        for (var i = 0; i < items.length; i++) {
            var el = items[i];
            var id = el.getAttribute('data-toast-id');
            if (!id || typeof before[id] !== 'number') continue;

            var nowTop = el.getBoundingClientRect().top;
            var delta = before[id] - nowTop;
            if (!delta) continue;

            if (typeof el.animate === 'function') {
                el.animate(
                    [
                        { transform: 'translateY(' + delta + 'px)' },
                        { transform: 'translateY(0)' }
                    ],
                    {
                        duration: 240,
                        easing: 'cubic-bezier(0.2, 0, 0, 1)'
                    }
                );
            }
        }
    }

    /**
     * Remove a toast with exit animation and stack reflow animation.
     * @param {HTMLElement} toast
     */
    function removeToast(toast) {
        if (!toast || toast.__removing) return;
        toast.__removing = true;

        if (toast.__timer) {
            clearTimeout(toast.__timer);
            toast.__timer = null;
        }

        var container = toast.parentNode;
        if (!container) return;

        var before = captureToastPositions(container);
        toast.classList.remove('bwh-msg-visible');
        toast.classList.add('bwh-msg-leaving');

        setTimeout(function () {
            if (!toast.parentNode) return;
            toast.parentNode.removeChild(toast);
            animateToastStack(container, before);

            if (!container.children.length && container.parentNode) {
                container.parentNode.removeChild(container);
            }
        }, 240);
    }

    /**
     * Create and enqueue a toast at the top of the stack.
     * @param {Object} opts
     * @param {string} [opts.text]
     * @param {Node} [opts.content]
     * @param {number} [opts.duration]
     * @param {string} [opts.className]
     * @param {Function} [opts.onClick]
     */
    function enqueueToast(opts) {
        var container = ensureToastStack();
        var before = captureToastPositions(container);

        var toast = document.createElement('div');
        toast.className = 'bwh-msg';
        if (opts && opts.className) {
            toast.className += ' ' + opts.className;
        }

        toast.setAttribute('data-toast-id', String(Date.now()) + '-' + String(Math.random()).slice(2, 8));

        if (opts && opts.content) {
            toast.appendChild(opts.content);
        } else {
            toast.textContent = opts && opts.text ? opts.text : '';
        }

        if (opts && typeof opts.onClick === 'function') {
            toast.classList.add('bwh-msg-clickable');
            toast.setAttribute('role', 'button');
            toast.tabIndex = 0;
            toast.addEventListener('click', function () {
                try {
                    opts.onClick();
                } catch (_) {
                    // ignore handler failures to keep toast system resilient
                }
                removeToast(toast);
            });
            toast.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    try {
                        opts.onClick();
                    } catch (_) {
                        // ignore handler failures
                    }
                    removeToast(toast);
                }
            });
        }

        container.insertBefore(toast, container.firstChild);
        animateToastStack(container, before);

        requestAnimationFrame(function () {
            toast.classList.add('bwh-msg-visible');
        });

        var duration = (opts && typeof opts.duration === 'number') ? opts.duration : 3000;
        toast.__timer = setTimeout(function () {
            removeToast(toast);
        }, duration);
    }

    /**
     * Show a transient toast message.
     * @param {string} msg
     */
    function showMessage(msg) {
        enqueueToast({ text: msg, duration: 3000 });
    }

    /**
     * Show a one-off toast when new debug log changes are detected.
     * @param {Function} [onClick]
     */
    function showDebugChangedToast(onClick) {
        var content = document.createElement('span');
        content.appendChild(document.createTextNode('Debug log '));

        var changed = document.createElement('span');
        changed.className = 'bwh-pulse-text';
        changed.textContent = 'changed';
        content.appendChild(changed);

        content.appendChild(document.createTextNode(' — click to view the latest entries.'));

        enqueueToast({
            content: content,
            className: 'bwh-msg-change',
            duration: 4800,
            onClick: onClick
        });
    }

    /**
     * Show a reusable confirmation modal.
     *
     * Creates a modal on the fly, shows it, and removes it from the DOM on
     * close. This avoids stale state and allows different text per invocation.
     *
     * @param {Object}   opts
     * @param {string}   opts.message     - Confirmation message text.
     * @param {string}   opts.confirmText - Label for the confirm button.
     * @param {string}   [opts.cancelText='Cancel'] - Label for the cancel button.
     * @param {Function} opts.onConfirm   - Callback invoked on confirmation.
     */
    function showConfirmModal(opts) {
        var existing = document.getElementById('bwh-confirm-modal');
        if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

        var container = document.createElement('div');
        container.id = 'bwh-confirm-modal';
        container.className = 'bwh-modal-container';

        var overlay = document.createElement('div');
        overlay.className = 'bwh-modal-overlay';

        var box = document.createElement('div');
        box.className = 'bwh-modal';

        var body = document.createElement('div');
        body.className = 'bwh-modal-body';

        var p = document.createElement('p');
        p.textContent = opts.message || 'Are you sure?';

        var actions = document.createElement('div');
        actions.className = 'bwh-modal-actions';

        var btnConfirm = document.createElement('button');
        btnConfirm.className = 'bwh-btn bwh-confirm';
        btnConfirm.textContent = opts.confirmText || 'Confirm';

        var btnCancel = document.createElement('button');
        btnCancel.className = 'bwh-btn bwh-cancel';
        btnCancel.textContent = opts.cancelText || 'Cancel';

        actions.appendChild(btnCancel);
        actions.appendChild(btnConfirm);
        body.appendChild(p);
        body.appendChild(actions);
        box.appendChild(body);
        container.appendChild(overlay);
        container.appendChild(box);
        document.body.appendChild(container);

        function closeModal() {
            if (container.parentNode) container.parentNode.removeChild(container);
            document.removeEventListener('keydown', onEsc);
        }

        function onEsc(ev) {
            if (ev.key === 'Escape') closeModal();
        }

        btnCancel.addEventListener('click', closeModal);
        overlay.addEventListener('click', closeModal);
        document.addEventListener('keydown', onEsc);

        btnConfirm.addEventListener('click', function () {
            closeModal();
            if (typeof opts.onConfirm === 'function') opts.onConfirm();
        });
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
        var rootItem            = getBarItem('bwh_root');
        var rootNode            = getBarNode('bwh_root');
        var backupSizeNode      = getBarNode('bwh_backup_size');
        var backupSizeItem      = getBarItem('bwh_backup_size');

        var STORAGE_KEY = 'bwh_debug_indicator_state_v1';
        var STATE_TTL_MS = 60 * 60 * 1000; // 1 hour

        /* ── State ── */
        var monitorActive   = bwh_ajax.debug_monitor === 'active';
        var latestFingerprint = '';
        var acknowledgedFingerprint = '';
        var notifiedFingerprint = '';
        var logHasContent = false;
        var currentLogStatus = null;
        var handleDebugChangedToastClick = null;
        var pollTimer       = null;
        var pollInFlight    = false;
        var pulsingDot      = null; // reference to the dot element on root

        /**
         * Safely read state from localStorage.
         * @returns {Object|null}
         */
        function readStoredState() {
            try {
                var raw = window.localStorage.getItem(STORAGE_KEY);
                if (!raw) return null;
                var parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') return null;
                return parsed;
            } catch (_) {
                return null;
            }
        }

        /**
         * Safely persist indicator state.
         */
        function writeStoredState() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    latestFingerprint: latestFingerprint || '',
                    acknowledgedFingerprint: acknowledgedFingerprint || '',
                    notifiedFingerprint: notifiedFingerprint || '',
                    updatedAt: Date.now()
                }));
            } catch (_) {
                // Storage can fail (private mode, blocked storage, quota). Ignore safely.
            }
        }

        /**
         * Clear stored indicator state.
         */
        function clearStoredState() {
            try {
                window.localStorage.removeItem(STORAGE_KEY);
            } catch (_) {
                // ignore safely
            }
        }

        /**
         * Whether current state has an unseen change.
         * @returns {boolean}
         */
        function hasUnseenChange() {
            return !!(
                monitorActive &&
                logHasContent &&
                latestFingerprint &&
                latestFingerprint !== acknowledgedFingerprint
            );
        }

        /**
         * Render debug log anchor in "has content" mode.
         * @param {Object} logStatus
         * @param {boolean} unseen
         */
        function renderDebugLogHasContent(logStatus, unseen) {
            if (!debugLogAnchor) return;

            debugLogAnchor.textContent = '';

            var lbl = document.createTextNode('Debug log: ');
            var sz = document.createElement('span');
            sz.className = 'bwh-state bwh-amber';
            sz.textContent = logStatus.size_human;

            debugLogAnchor.appendChild(lbl);
            debugLogAnchor.appendChild(sz);

            if (unseen) {
                var changed = document.createElement('span');
                changed.className = 'bwh-state bwh-pulse-text';
                changed.textContent = ' changed';
                debugLogAnchor.appendChild(changed);
            }

            var hint = document.createElement('span');
            hint.className = 'bwh-state';
            hint.textContent = ' (click to view)';
            debugLogAnchor.appendChild(hint);

            debugLogAnchor.style.cursor = 'pointer';
        }

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

        if (clearAnchor) {
            clearAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                showConfirmModal({
                    message: 'Are you sure you want to permanently remove BackWPup backup folders from uploads?',
                    confirmText: 'Remove',
                    onConfirm: function () {
                        post(makeForm('bwh_clear_backups'))
                            .then(function (data) {
                                if (data && data.success) {
                                    showMessage('Backups cleared.');
                                    updateBackupSizeNode({ exists: false });
                                    lastHoverRefresh = 0;
                                } else {
                                    showMessage('Nothing to remove or error occurred.');
                                }
                            })
                            .catch(function () { showMessage('Request failed.'); });
                    }
                });
            });
        }

        /* ================================================================
         * 3. Debug monitor
         * ================================================================ */

        /* ── 3a. Pulsing indicator dot on the top-level root item ── */

        function ensurePulsingDot() {
            if (pulsingDot || !rootItem) return;
            pulsingDot = document.createElement('span');
            pulsingDot.className = 'bwh-pulse-dot';
            // Native title attribute → browser provides a built-in tooltip.
            pulsingDot.title = 'Debug log has changed since last viewed';
            pulsingDot.style.display = 'none';
            rootItem.appendChild(pulsingDot);
        }

        function showPulsingDot() {
            ensurePulsingDot();
            if (pulsingDot) pulsingDot.style.display = 'inline-block';
        }

        function hidePulsingDot() {
            if (pulsingDot) pulsingDot.style.display = 'none';
        }

        /**
         * Render linked change indicators.
         *
         * @param {boolean} visible
         */
        function setChangeIndicatorsVisible(visible) {
            if (visible) {
                showPulsingDot();
            } else {
                hidePulsingDot();
            }
        }

        /**
         * Derive visibility from latest vs acknowledged fingerprint.
         */
        function renderChangeIndicators() {
            var unseen = hasUnseenChange();
            setChangeIndicatorsVisible(unseen);

            if (monitorActive && logHasContent && currentLogStatus) {
                renderDebugLogHasContent(currentLogStatus, unseen);
            }

            if (unseen && latestFingerprint && latestFingerprint !== notifiedFingerprint) {
                showDebugChangedToast(handleDebugChangedToastClick);
                notifiedFingerprint = latestFingerprint;
            }

            if (!monitorActive || !logHasContent) {
                notifiedFingerprint = '';
            }

            writeStoredState();
        }

        /**
         * Mark current log state as acknowledged by the user.
         */
        function acknowledgeCurrentChange() {
            if (latestFingerprint) {
                acknowledgedFingerprint = latestFingerprint;
            }
            renderChangeIndicators();
        }

        /* ── 3b. Debug log sub-item visibility ── */

        /**
         * Update the debug log sub-items based on monitor state and log status.
         * @param {Object|null} logStatus - Result from get_debug_log_status or null.
         */
        function updateDebugLogUI(logStatus) {
            if (!debugLogNode || !deleteLogNode) return;
            currentLogStatus = logStatus;

            // Hide both items when monitor is inactive.
            if (!monitorActive) {
                debugLogNode.style.display = 'none';
                deleteLogNode.style.display = 'none';
                logHasContent = false;
                currentLogStatus = null;
                renderChangeIndicators();
                return;
            }

            // Monitor is active — show log status.
            debugLogNode.style.display = '';

            if (!logStatus || !logStatus.exists || logStatus.size === 0) {
                logHasContent = false;
                latestFingerprint = '';
                acknowledgedFingerprint = '';
                currentLogStatus = null;

                if (debugLogNode && debugLogNode.classList) {
                    debugLogNode.classList.add('bwh-item-dimmed');
                }

                // Log is empty or missing.
                if (debugLogAnchor) {
                    debugLogAnchor.textContent = '';
                    var label = document.createTextNode('Debug log: ');
                    var span = document.createElement('span');
                    span.className = 'bwh-state bwh-active';
                    span.textContent = 'nothing in logs';
                    debugLogAnchor.appendChild(label);
                    debugLogAnchor.appendChild(span);
                    debugLogAnchor.style.cursor = 'default';
                }
                deleteLogNode.style.display = 'none';
            } else {
                logHasContent = true;
                latestFingerprint = logStatus.fingerprint || '';
                if (debugLogNode && debugLogNode.classList) {
                    debugLogNode.classList.remove('bwh-item-dimmed');
                }
                deleteLogNode.style.display = '';
            }

            renderChangeIndicators();
        }

        /* ── 3c. Polling ── */

        function doPoll() {
            if (!monitorActive || pollInFlight) return;

            pollInFlight = true;

            post(makeForm('bwh_debug_log_status'))
                .then(function (data) {
                    if (!data || !data.success) return;

                    var s = data.data;
                    updateDebugLogUI(s);
                })
                .catch(function () { /* silent — next poll will retry */ })
                .then(function () {
                    pollInFlight = false;
                });
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
            pollInFlight = false;
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
                            latestFingerprint = ls ? (ls.fingerprint || '') : '';
                            acknowledgedFingerprint = latestFingerprint;
                            notifiedFingerprint = latestFingerprint;
                            updateDebugLogUI(ls);
                            startPolling();
                        } else {
                            stopPolling();
                            latestFingerprint = '';
                            acknowledgedFingerprint = '';
                            notifiedFingerprint = '';
                            logHasContent = false;
                            updateDebugLogUI(null);
                            clearStoredState();
                        }
                    })
                    .catch(function () { showMessage('Request failed.'); });
            });
        }

        /* ── 3e. Initial state on page load ── */

        if (monitorActive && bwh_ajax.debug_log_status) {
            latestFingerprint = bwh_ajax.debug_log_status.fingerprint || '';

            var persisted = readStoredState();
            if (persisted && typeof persisted.updatedAt === 'number') {
                var age = Date.now() - persisted.updatedAt;
                if (age <= STATE_TTL_MS) {
                    acknowledgedFingerprint = typeof persisted.acknowledgedFingerprint === 'string'
                        ? persisted.acknowledgedFingerprint
                        : latestFingerprint;
                    notifiedFingerprint = typeof persisted.notifiedFingerprint === 'string'
                        ? persisted.notifiedFingerprint
                        : '';
                } else {
                    // Expired state: reset to current fingerprint and suppress indicators.
                    acknowledgedFingerprint = latestFingerprint;
                    notifiedFingerprint = latestFingerprint;
                }
            } else {
                acknowledgedFingerprint = latestFingerprint;
                notifiedFingerprint = latestFingerprint;
            }

            updateDebugLogUI(bwh_ajax.debug_log_status);
            startPolling();
        } else {
            clearStoredState();
            updateDebugLogUI(null);
        }

        /* ── 3f. Debug log viewer (click to open) ── */

        if (debugLogAnchor) {
            debugLogAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                // Only open when monitor is active and log has content.
                if (!monitorActive) return;
                if (debugLogAnchor.style.cursor === 'default') return;

                acknowledgeCurrentChange();
                openLogViewer();
            });
        }

        handleDebugChangedToastClick = function () {
            if (!monitorActive || !logHasContent || !debugLogAnchor) return;
            if (debugLogAnchor.style.cursor === 'default') return;

            acknowledgeCurrentChange();
            openLogViewer();
        };

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

            // Keep indicators synced to acknowledged state.
            renderChangeIndicators();
        }

        /* ── 3g. Delete debug log (with confirmation) ── */

        if (deleteLogAnchor) {
            deleteLogAnchor.addEventListener('click', function (e) {
                e.preventDefault();
                showConfirmModal({
                    message: 'Are you sure you want to delete the debug.log file? This action cannot be undone.',
                    confirmText: 'Delete',
                    onConfirm: function () {
                        // Deletion intent acknowledges current change notification.
                        acknowledgeCurrentChange();

                        post(makeForm('bwh_delete_debug_log'))
                            .then(function (data) {
                                if (data && data.success && data.data) {
                                    var r = data.data.result;
                                    if (r === 'deleted') {
                                        showMessage('Debug log deleted.');
                                    } else if (r === 'not_found') {
                                        showMessage('No debug log to delete.');
                                    }
                                    updateDebugLogUI(data.data.log_status || null);
                                } else {
                                    showMessage('Could not delete debug log.');
                                    renderChangeIndicators();
                                }
                            })
                            .catch(function () {
                                showMessage('Request failed.');
                                renderChangeIndicators();
                            });
                    }
                });
            });
        }

        /* ================================================================
         * 4. Hover refresh (generic, with cooldown)
         * ================================================================ */

        var HOVER_COOLDOWN   = 20; // seconds
        var lastHoverRefresh = 0;

        /**
         * Update the backup-size admin bar node.
         * @param {Object|null} info - Result from BWH_Service::get_backup_dir_info().
         */
        function updateBackupSizeNode(info) {
            if (!backupSizeNode) return;
            if (info && info.exists && info.size > 0) {
                if (backupSizeItem) {
                    backupSizeItem.textContent = 'Backup data: ' + info.size_human;
                }
                backupSizeNode.style.display = '';
            } else {
                backupSizeNode.style.display = 'none';
            }
        }

        /**
         * Process hover refresh response. Extend this function to handle
         * additional data keys in the future.
         * @param {Object} data - Server response from bwh_hover_refresh.
         */
        function handleHoverData(data) {
            if (!data) return;
            if (data.backup_dir) {
                updateBackupSizeNode(data.backup_dir);
            }
            // Future: handle more hover data keys here.
        }

        if (rootNode) {
            rootNode.addEventListener('mouseenter', function () {
                var now = Date.now() / 1000;
                if (now - lastHoverRefresh < HOVER_COOLDOWN) return;
                lastHoverRefresh = now;

                post(makeForm('bwh_hover_refresh'))
                    .then(function (data) {
                        if (data && data.success && data.data) {
                            handleHoverData(data.data);
                        }
                    })
                    .catch(function () { /* silent — non-critical */ });
            });
        }

        // Initial state from server-rendered hover data.
        if (bwh_ajax.hover_data) {
            handleHoverData(bwh_ajax.hover_data);
        }
    });

})();
