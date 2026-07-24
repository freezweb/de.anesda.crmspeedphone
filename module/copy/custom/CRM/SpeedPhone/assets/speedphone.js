(function () {
    'use strict';

    const root = document.querySelector('.speedphone');
    const message = document.getElementById('speedphone-message');
    const workspace = document.getElementById('speedphone-workspace');
    if (!root || !message || !workspace) {
        return;
    }
    if (root.dataset.speedphoneInitialized === 'true') {
        return;
    }
    root.dataset.speedphoneInitialized = 'true';

    const LIVE_UPDATE_INTERVAL_MS = 10000;
    let liveUpdateTimer = null;
    let refreshInFlight = false;
    let refreshFailures = 0;
    startLiveUpdates();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshCurrent();
        }
    });
    window.addEventListener('online', refreshCurrent);
    window.addEventListener('pagehide', stopLiveUpdates);
    window.addEventListener('pageshow', startLiveUpdates);

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
        const sendsEmail = button.value === 'email_callback'
            || (button.value === 'interested' && form.elements.email_requested.checked);
        if (sendsEmail && !form.elements.email_address_confirmed.checked) {
            showMessage('Bitte bestätigen Sie, dass der Kontakt diese einmalige Informationsmail ausdrücklich angefordert hat.', true);
            form.elements.email_address_confirmed.focus();
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
            stopLiveUpdates();
            if (emailFailed && emailResult.retry_allowed) {
                setBusy(form, false);
                delete button.dataset.submitting;
                const retryPanel = form.querySelector('#speedphone-email-retry');
                if (retryPanel) {
                    retryPanel.hidden = false;
                    retryPanel.querySelector('button')?.focus();
                }
                return;
            }
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
        const dialerToggle = event.target.closest('[data-speedphone-dialer-toggle]');
        if (dialerToggle) {
            const panel = document.getElementById('speedphone-dialer-panel');
            if (panel) {
                panel.hidden = !panel.hidden;
                dialerToggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                if (!panel.hidden) {
                    await loadPairingCode();
                    panel.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
            return;
        }

        if (event.target.closest('[data-speedphone-dialer-close]')) {
            const panel = document.getElementById('speedphone-dialer-panel');
            if (panel) {
                panel.hidden = true;
                root.querySelector('[data-speedphone-dialer-toggle]')?.setAttribute('aria-expanded', 'false');
            }
            return;
        }

        if (event.target.closest('[data-speedphone-dialer-refresh]')) {
            await loadPairingCode();
            return;
        }

        const revokeButton = event.target.closest('[data-speedphone-dialer-revoke]');
        if (revokeButton) {
            if (!window.confirm('Dieses Handy wirklich vom Benutzerkonto trennen?')) {
                return;
            }
            const data = new FormData();
            data.set('operation', 'dialer_revoke');
            data.set('device_id', revokeButton.dataset.speedphoneDialerRevoke || '');
            data.set('csrf', root.dataset.csrf);
            revokeButton.disabled = true;
            try {
                const payload = await request(data);
                revokeButton.closest('.dialer-device')?.remove();
                showMessage(payload.data.message, false);
            } catch (error) {
                revokeButton.disabled = false;
                showMessage(error.message || String(error), true);
            }
            return;
        }

        const dialButton = event.target.closest('[data-speedphone-dialer-call]');
        if (dialButton) {
            const form = document.getElementById('speedphone-form');
            if (!form) {
                return;
            }
            const data = new FormData();
            data.set('operation', 'dialer_call');
            data.set('prospect_id', form.elements.prospect_id.value);
            data.set('lock_token', form.elements.lock_token.value);
            data.set('phone_kind', dialButton.dataset.speedphoneDialerCall || 'work');
            data.set('csrf', root.dataset.csrf);
            dialButton.disabled = true;
            const originalText = dialButton.textContent;
            dialButton.textContent = 'Wird gesendet …';
            try {
                const payload = await request(data);
                dialButton.textContent = 'An Handy gesendet';
                showMessage('Anrufauftrag an „' + payload.data.device_name + '“ gesendet.', false);
                await watchDialerCommand(payload.data.command_id, payload.data.platform);
            } catch (error) {
                showMessage(error.message || String(error), true);
            } finally {
                if (document.body.contains(dialButton)) {
                    dialButton.disabled = false;
                    dialButton.textContent = originalText;
                }
                refreshCurrent();
            }
            return;
        }

        const ownedEmailButton = event.target.closest('[data-speedphone-owned-email]');
        if (ownedEmailButton) {
            const contactName = ownedEmailButton.dataset.contactName || 'diesen Kontakt';
            const email = ownedEmailButton.dataset.email || '';
            if (!window.confirm(
                'Hat ' + contactName + ' diese einmalige Informationsmail im aktuellen Gespräch ausdrücklich angefordert und die Adresse ' + email + ' bestätigt?'
            )) {
                return;
            }

            const data = new FormData();
            data.set('operation', 'resend_email');
            data.set('prospect_id', ownedEmailButton.dataset.prospectId || '');
            data.set('new_email', email);
            data.set('email_address_confirmed', '1');
            data.set('csrf', root.dataset.csrf);
            ownedEmailButton.disabled = true;
            try {
                const payload = await request(data);
                ownedEmailButton.textContent = 'Mail versendet';
                showMessage(payload.data.message, false);
            } catch (error) {
                ownedEmailButton.disabled = false;
                showMessage(error.message || String(error), true);
            }
            return;
        }

        const emailRetryButton = event.target.closest('[data-speedphone-email-retry]');
        if (emailRetryButton) {
            const form = emailRetryButton.closest('#speedphone-form');
            if (!form) {
                return;
            }
            if (!form.elements.new_email.value) {
                showMessage('Für den Versand ist eine E-Mail-Adresse erforderlich.', true);
                form.elements.new_email.focus();
                return;
            }
            if (!form.elements.email_address_confirmed.checked) {
                showMessage('Bitte bestätigen Sie die ausdrückliche Anforderung dieser einmaligen Informationsmail.', true);
                form.elements.email_address_confirmed.focus();
                return;
            }

            const data = new FormData(form);
            data.set('operation', 'resend_email');
            data.set('csrf', root.dataset.csrf);
            setBusy(form, true);
            try {
                const payload = await request(data);
                showMessage(payload.data.message, false);
                await loadNextCandidate();
            } catch (error) {
                showMessage(error.message || String(error), true);
                if (document.body.contains(form)) {
                    setBusy(form, false);
                    emailRetryButton.focus();
                }
            }
            return;
        }

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
            if (payload.data.devices) {
                renderDialerDevices(payload.data.devices);
            }
            startLiveUpdates();
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
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            if (response.redirected || !String(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Die Sitzung ist abgelaufen. Bitte SpeedPhone neu laden.');
            }
            throw error;
        }
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Die Anfrage konnte nicht verarbeitet werden.');
        }

        return payload;
    }

    async function loadPairingCode() {
        const qrTarget = root.querySelector('[data-speedphone-dialer-qr]');
        const expiryTarget = root.querySelector('[data-speedphone-dialer-expiry]');
        if (!qrTarget || typeof window.qrcode !== 'function') {
            showMessage('Der QR-Code konnte nicht geladen werden.', true);
            return;
        }
        qrTarget.textContent = 'QR-Code wird geladen …';
        const data = new FormData();
        data.set('operation', 'dialer_pairing');
        data.set('csrf', root.dataset.csrf);
        try {
            const payload = await request(data);
            const qr = window.qrcode(0, 'M');
            qr.addData(payload.data.payload);
            qr.make();
            qrTarget.innerHTML = qr.createSvgTag(5, 4);
            qrTarget.querySelector('svg')?.setAttribute('aria-label', 'QR-Code zum Koppeln der SpeedPhone Dialer App');
            if (expiryTarget) {
                const expires = new Date(String(payload.data.expires_at).replace(' ', 'T') + 'Z');
                expiryTarget.textContent = 'Gültig bis ' + expires.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + ' Uhr';
            }
            renderDialerDevices(payload.data.devices || []);
        } catch (error) {
            qrTarget.textContent = 'QR-Code konnte nicht erzeugt werden.';
            showMessage(error.message || String(error), true);
        }
    }

    function renderDialerDevices(devices) {
        const target = root.querySelector('[data-speedphone-dialer-devices]');
        if (!target) {
            return;
        }
        target.replaceChildren();
        if (!devices.length) {
            const empty = document.createElement('p');
            empty.className = 'dialer-panel__empty';
            empty.textContent = 'Noch kein Gerät gekoppelt.';
            target.appendChild(empty);
            return;
        }
        devices.forEach(function (device) {
            const item = document.createElement('article');
            item.className = 'dialer-device';
            const details = document.createElement('div');
            const name = document.createElement('strong');
            const state = document.createElement('span');
            name.textContent = device.device_name;
            state.textContent = String(device.platform).toUpperCase() + ' · ' + (Number(device.is_ready) === 1 ? 'empfangsbereit' : 'App derzeit nicht aktiv');
            details.append(name, state);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button button--danger button--compact';
            remove.dataset.speedphoneDialerRevoke = device.id;
            remove.textContent = 'Trennen';
            item.append(details, remove);
            target.appendChild(item);
        });
    }

    async function watchDialerCommand(commandId, platform) {
        for (let attempt = 0; attempt < 15; attempt += 1) {
            await new Promise(function (resolve) { window.setTimeout(resolve, 1000); });
            const data = new FormData();
            data.set('operation', 'dialer_command_status');
            data.set('command_id', commandId);
            data.set('csrf', root.dataset.csrf);
            const payload = await request(data);
            if (payload.data.status === 'dialed') {
                showMessage(platform === 'ios' ? 'Anruf am iPhone bestätigt und gestartet.' : 'Anruf auf dem Handy gestartet.', false);
                return;
            }
            if (payload.data.status === 'failed') {
                throw new Error(payload.data.error || 'Das Handy konnte den Anruf nicht starten.');
            }
            if (payload.data.status === 'expired' || payload.data.status === 'cancelled') {
                throw new Error('Der Anrufauftrag wurde nicht rechtzeitig vom Handy übernommen.');
            }
            if (payload.data.status === 'received') {
                showMessage(platform === 'ios' ? 'Auf dem iPhone bitte den Anruf bestätigen.' : 'Das Handy hat den Anruf übernommen.', false);
            }
        }
        showMessage('Das Handy hat noch keine Rückmeldung gegeben. Prüfen Sie, ob die App geöffnet ist.', true);
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

    function startLiveUpdates() {
        stopLiveUpdates();
        liveUpdateTimer = window.setInterval(refreshCurrent, LIVE_UPDATE_INTERVAL_MS);
        refreshCurrent();
    }

    function stopLiveUpdates() {
        if (liveUpdateTimer !== null) {
            window.clearInterval(liveUpdateTimer);
            liveUpdateTimer = null;
        }
    }

    async function refreshCurrent() {
        const form = document.getElementById('speedphone-form');
        if (refreshInFlight || (form && form.classList.contains('is-busy'))) {
            return;
        }

        const prospectId = form?.elements.prospect_id?.value || '';
        const lockToken = form?.elements.lock_token?.value || '';

        const data = new FormData();
        data.set('operation', 'refresh_current');
        data.set('prospect_id', prospectId);
        data.set('lock_token', lockToken);
        data.set('csrf', root.dataset.csrf);

        refreshInFlight = true;
        try {
            const payload = await request(data);
            const currentForm = document.getElementById('speedphone-form');
            if (payload.data.incoming_call && payload.data.workspace_html) {
                storeCurrentDraft(currentForm);
                workspace.innerHTML = payload.data.workspace_html;
                updateStatistics(payload.data.statistics || {});
                renderDialerDevices(payload.data.devices || []);
                updateLiveStatus(payload.data.expires_at);
                restoreDraft(document.getElementById('speedphone-form'));
                showMessage(
                    'Eingehender Rückruf erkannt: '
                    + payload.data.incoming_call.display_name
                    + ' wurde automatisch geöffnet.',
                    false
                );
                refreshFailures = 0;
                return;
            }

            if (!currentForm) {
                updateStatistics(payload.data.statistics || {});
                renderDialerDevices(payload.data.devices || []);
                refreshFailures = 0;
                return;
            }
            if (!currentForm
                || currentForm.elements.prospect_id.value !== prospectId
                || currentForm.elements.lock_token.value !== lockToken
                || payload.data.prospect_id !== prospectId) {
                return;
            }

            updateCurrentCandidate(payload.data.workspace_html);
            updateStatistics(payload.data.statistics || {});
            renderDialerDevices(payload.data.devices || []);
            updateLiveStatus(payload.data.expires_at);
            if (refreshFailures >= 3) {
                showMessage('Live-Aktualisierung und Kontaktreservierung sind wieder verbunden.', false);
            }
            refreshFailures = 0;
        } catch (error) {
            refreshFailures += 1;
            const errorText = error.message || String(error);
            if (isReservationError(errorText)) {
                stopLiveUpdates();
                showMessage(errorText, true);
                if (document.body.contains(form)) {
                    setBusy(form, true);
                }
            } else if (refreshFailures >= 3) {
                showMessage(
                    'Die Live-Aktualisierung ist vorübergehend unterbrochen. '
                    + 'SpeedPhone versucht es automatisch weiter. ' + errorText,
                    true
                );
            }
        } finally {
            refreshInFlight = false;
        }
    }

    function updateCurrentCandidate(workspaceHtml) {
        const currentCandidate = workspace.querySelector('.candidate');
        if (!currentCandidate || typeof workspaceHtml !== 'string') {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = workspaceHtml.trim();
        const incomingCandidate = template.content.querySelector('.candidate');
        const incomingMain = incomingCandidate?.querySelector('.candidate__main');
        const currentMain = currentCandidate.querySelector('.candidate__main');
        if (!incomingCandidate
            || incomingCandidate.dataset.prospectId !== currentCandidate.dataset.prospectId
            || !incomingMain
            || !currentMain) {
            return;
        }

        currentMain.replaceWith(incomingMain);
    }

    function updateLiveStatus(expiresAt) {
        const target = document.querySelector('[data-speedphone-live-status]');
        if (!target) {
            return;
        }

        const expires = new Date(String(expiresAt || '').replace(' ', 'T') + 'Z');
        target.textContent = Number.isNaN(expires.getTime())
            ? 'Live-Aktualisierung aktiv'
            : 'Live · reserviert bis '
                + expires.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'})
                + ' Uhr';
    }

    function storeCurrentDraft(form) {
        if (!form || !form.elements.prospect_id) {
            return;
        }
        const values = {};
        Array.from(form.elements).forEach(function (element) {
            if (!element.name || ['prospect_id', 'lock_token'].includes(element.name)) {
                return;
            }
            values[element.name] = element.type === 'checkbox'
                ? element.checked
                : element.value;
        });
        try {
            window.sessionStorage.setItem(
                'speedphone-draft-' + form.elements.prospect_id.value,
                JSON.stringify(values)
            );
        } catch (_) {
            // Private Browsermodi können Sitzungsspeicher blockieren; der Rückruf muss trotzdem geöffnet werden.
        }
    }

    function restoreDraft(form) {
        if (!form || !form.elements.prospect_id) {
            return;
        }
        let values = null;
        try {
            values = JSON.parse(window.sessionStorage.getItem(
                'speedphone-draft-' + form.elements.prospect_id.value
            ) || 'null');
        } catch (_) {
            return;
        }
        if (!values || typeof values !== 'object') {
            return;
        }
        Object.keys(values).forEach(function (name) {
            const element = form.elements[name];
            if (!element) {
                return;
            }
            if (element.type === 'checkbox') {
                element.checked = Boolean(values[name]);
            } else {
                element.value = String(values[name]);
            }
        });
    }

    function isReservationError(errorText) {
        const normalized = String(errorText).toLocaleLowerCase('de-DE');
        return normalized.includes('nicht mehr für dich reserviert')
            || normalized.includes('kontaktreservierung ist abgelaufen')
            || normalized.includes('reservierte zielkontakt ist nicht mehr')
            || normalized.includes('sitzung ist abgelaufen')
            || normalized.includes('nicht angemeldet');
    }
}());
