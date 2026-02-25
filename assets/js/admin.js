// BackWPup Helper admin JS
// Requires: bwh_ajax localized object with {ajax_url, nonce, state}

(function () {
    'use strict';

    function getAnchor(id){
        var node = document.getElementById('wp-admin-bar-' + id);
        if (!node) return null;
        return node.querySelector('a');
    }

    function setStateText(anchor, state){
        if (!anchor) return;
        var span = document.createElement('span');
        span.className = 'bwh-state ' + (state === 'active' ? 'bwh-active' : 'bwh-inactive');
        span.textContent = state;
        anchor.innerHTML = 'Big backup: ';
        anchor.appendChild(span);
        anchor.setAttribute('data-state', state);
    }

    function showMessage(msg){
        // Simple transient message using alert is avoided; create a small ephemeral node
        var el = document.createElement('div');
        el.className = 'bwh-msg';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function(){ document.body.removeChild(el); }, 3000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof bwh_ajax === 'undefined') return;

        var clearAnchor = getAnchor('bwh_clear');
        var toggleAnchor = getAnchor('bwh_bigbackup');

        // Initialize state label
        setStateText(toggleAnchor, bwh_ajax.state || 'inactive');

        // Confirmation modal for clear action
        function createConfirmModal(){
            if (document.getElementById('bwh-confirm-modal')) return;

            // overlay
            var overlay = document.createElement('div');
            overlay.className = 'bwh-modal-overlay';

            // modal container
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

            // handlers
            btnCancel.addEventListener('click', function(){
                modal.style.display = 'none';
            });
            overlay.addEventListener('click', function(){
                modal.style.display = 'none';
            });
            btnConfirm.addEventListener('click', function(){
                modal.style.display = 'none';
                // perform AJAX clear
                var form = new FormData();
                form.append('action', 'bwh_clear_backups');
                form.append('nonce', bwh_ajax.nonce);

                fetch(bwh_ajax.ajax_url, { method: 'POST', body: form, credentials: 'same-origin' })
                    .then(function (resp) { return resp.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            showMessage('Backups cleared.');
                        } else {
                            showMessage('Nothing to remove or error occurred.');
                        }
                    }).catch(function () { showMessage('Request failed.'); });
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

        if (toggleAnchor) {
            toggleAnchor.addEventListener('click', function (e) {
                e.preventDefault();

                var form = new FormData();
                form.append('action', 'bwh_toggle_big_backup');
                form.append('nonce', bwh_ajax.nonce);

                fetch(bwh_ajax.ajax_url, { method: 'POST', body: form, credentials: 'same-origin' })
                    .then(function (resp) { return resp.json(); })
                    .then(function (data) {
                        if (data && data.success && data.data && data.data.state) {
                            setStateText(toggleAnchor, data.data.state);
                            showMessage('Big backup set to ' + data.data.state + '.');
                        } else {
                            showMessage('Could not toggle state.');
                        }
                    }).catch(function () { showMessage('Request failed.'); });
            });
        }
    });

})();
