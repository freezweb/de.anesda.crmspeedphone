(function () {
    'use strict';

    const root = document.querySelector('.speedphone');
    const message = document.getElementById('speedphone-message');
    const workspace = document.getElementById('speedphone-workspace');
    if (!root || !message || !workspace) {
        return;
    }

    let heartbeatTimer = null;
    startHeartbeat();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshLock();
        }
    });

    root.addEventListener('submit', async function (event) {
        const teamForm = event.target.closest('#speedphone-team-form');
        if (teamForm) {
            event.preventDefault();
            const teamData = new FormData(teamForm);
            teamData.set('operation', 'save_team_settings');
            teamData.set('csrf', root.dataset.csrf);
            setBusy(teamForm, true);
            try {
                const payload = await request(teamData);
                showMessage(payload.data.message, false);
                setBusy(teamForm, false);
            } catch (error) {
                showMessage(error.message || String(error), true);
                setBusy(teamForm, false);
            }
            return;
        }

        const form = event.target.closest('#speedphone-form');
        if (!form) {
            return;
        }
        event.preventDefault();

        const button = event.submitter;
        if (!button || !button.value) {
            return;
        }

        if (button.value === 'blocked'
            && !window.confirm('Diesen Kontakt dauerhaft für weitere Anrufe sperren?')) {
            return;
        }

        const needsCallback = button.value === 'callback' || button.value === 'email_callback';
        if (needsCallback && !form.elements.callback_date.value) {
            showMessage('Für den Rückruf muss ein Datum eingetragen werden.', true);
            form.elements.callback_date.focus();
            return;
        }
        if (button.value === 'email_callback' && !form.elements.new_email.value) {
            showMessage('Für „E-Mail jetzt senden + wieder anrufen“ ist eine E-Mail-Adresse erforderlich.', true);
            form.elements.new_email.focus();
            return;
        }

        const data = new FormData(form);
        data.set('result', button.value);
        data.set('csrf', root.dataset.csrf);
        button.dataset.submitting = 'true';
        setBusy(form, true);

        try {
            const payload = await request(data);
            const emailResult = payload.data.email;
            const emailMessage = emailResult && emailResult.message ? ' ' + emailResult.message : '';
            const emailFailed = emailResult && emailResult.sent === false;
            showMessage(payload.data.message + emailMessage, emailFailed);
            stopHeartbeat();
            await loadNextCandidate();
        } catch (error) {
            showMessage(error.message || String(error), true);
            if (document.body.contains(form)) {
                setBusy(form, false);
                delete button.dataset.submitting;
            }
        }
    });

    root.addEventListener('click', async function (event) {
        const ownedToggle = event.target.closest('[data-speedphone-owned-toggle]');
        if (ownedToggle) {
            const panel = document.getElementById('speedphone-owned-contacts');
            if (panel) {
                panel.hidden = !panel.hidden;
                root.querySelectorAll('[data-speedphone-owned-toggle]').forEach(function (toggle) {
                    toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                });
                if (!panel.hidden) {
                    panel.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
            return;
        }

        const teamToggle = event.target.closest('[data-speedphone-team-toggle]');
        if (teamToggle) {
            const panel = document.getElementById('speedphone-team-settings');
            if (panel) {
                panel.hidden = !panel.hidden;
                root.querySelectorAll('[data-speedphone-team-toggle]').forEach(function (toggle) {
                    toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                });
                if (!panel.hidden) {
                    panel.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
            return;
        }

        const button = event.target.closest('[data-speedphone-retry]');
        if (!button) {
            return;
        }
        button.disabled = true;
        try {
            await loadNextCandidate();
            showMessage('Der nächste Kontakt wurde geladen.', false);
        } catch (error) {
            button.disabled = false;
            showMessage(error.message || String(error), true);
        }
    });

    root.addEventListener('change', function (event) {
        const role = event.target.closest('[data-speedphone-role]');
        if (!role) {
            return;
        }
        const row = role.closest('tr');
        const commission = row ? row.querySelector('input[name^="commission_percent"]') : null;
        if (commission && role.value === 'external' && Number(commission.value.replace(',', '.')) === 0) {
            commission.value = '20.00';
        }
    });

    async function loadNextCandidate() {
        const data = new FormData();
        data.set('operation', 'next');
        data.set('csrf', root.dataset.csrf);

        try {
            const payload = await request(data);
            workspace.innerHTML = payload.data.workspace_html;
            updateStatistics(payload.data.statistics || {});
            startHeartbeat();
            const candidateName = workspace.querySelector('.candidate-name');
            if (candidateName) {
                candidateName.focus({preventScroll: true});
            }
        } catch (error) {
            workspace.innerHTML = '<section class="empty empty--error">'
                + '<h2>Der nächste Kontakt konnte nicht geladen werden</h2>'
                + '<p>Das vorherige Ergebnis wurde bereits gespeichert.</p>'
                + '<button type="button" class="button" data-speedphone-retry>Nächsten Kontakt erneut laden</button>'
                + '</section>';
            throw error;
        }
    }

    async function request(data) {
        const response = await fetch(root.dataset.apiUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Die Anfrage konnte nicht verarbeitet werden.');
        }

        return payload;
    }

    function updateStatistics(statistics) {
        Object.keys(statistics).forEach(function (key) {
            const target = root.querySelector('[data-stat="' + key + '"]');
            if (target) {
                target.textContent = String(statistics[key]);
            }
        });
    }

    function setBusy(form, busy) {
        Array.from(form.querySelectorAll('button, input, textarea, select')).forEach(function (element) {
            element.disabled = busy;
        });
        form.classList.toggle('is-busy', busy);
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function showMessage(text, isError) {
        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('message--error', isError);
        message.setAttribute('role', isError ? 'alert' : 'status');
        message.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function startHeartbeat() {
        stopHeartbeat();
        if (document.getElementById('speedphone-form')) {
            heartbeatTimer = window.setInterval(refreshLock, 60000);
        }
    }

    function stopHeartbeat() {
        if (heartbeatTimer !== null) {
            window.clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    async function refreshLock() {
        const form = document.getElementById('speedphone-form');
        if (!form || !form.elements.prospect_id || !form.elements.lock_token) {
            stopHeartbeat();
            return;
        }

        const data = new FormData();
        data.set('operation', 'heartbeat');
        data.set('prospect_id', form.elements.prospect_id.value);
        data.set('lock_token', form.elements.lock_token.value);
        data.set('csrf', root.dataset.csrf);

        try {
            await request(data);
        } catch (error) {
            stopHeartbeat();
            showMessage(error.message || 'Die Kontaktreservierung ist abgelaufen. Bitte den nächsten Kontakt laden.', true);
            setBusy(form, true);
        }
    }
}());
