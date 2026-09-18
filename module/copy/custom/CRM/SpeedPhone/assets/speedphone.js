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
    let pendingEmailSubmission = null;
    let teamStatisticsInFlight = false;
    let teamStatisticsRequest = 0;
    let teamStatisticsTimer = null;
    let callHistoryPage = 1;
    let callHistoryInFlight = false;
    let callHistoryRequest = 0;
    let historyOpenInFlight = false;
    startTeamStatisticsUpdates();
    window.addEventListener('pagehide', stopTeamStatisticsUpdates);
    window.addEventListener('pageshow', startTeamStatisticsUpdates);
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
        if (event.target.id === 'speedphone-call-history-filter') {
            event.preventDefault();
            callHistoryPage = 1;
            await refreshCallHistory(true);
            return;
        }
        if (event.target.id === 'team-statistics-filter') {
            event.preventDefault();
            await refreshTeamStatistics(true);
            return;
        }
        const industryForm = event.target.closest('#speedphone-industry-filter, [data-speedphone-contact-industry]');
        if (industryForm) {
            event.preventDefault();
            const data = new FormData(industryForm);
            data.set('operation', industryForm.id === 'speedphone-industry-filter' ? 'set_industry_filter' : 'set_contact_industry');
            data.set('csrf', root.dataset.csrf);
            setBusy(industryForm, true);
            try {
                const payload = await request(data);
                updateIndustryCounts(payload.data.industry_counts);
                showMessage(payload.data.message, false);
                if (industryForm.id === 'speedphone-industry-filter' && !document.getElementById('speedphone-form')) {
                    await loadNextCandidate();
                }
                if (payload.data.industry_label && industryForm.querySelector('small')) {
                    industryForm.querySelector('small').textContent = payload.data.industry_label;
                }
            } catch (error) {
                showMessage(error.message || String(error), true);
            } finally {
                setBusy(industryForm, false);
            }
            return;
        }
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
        if ((button.value === 'email_callback' || button.value === 'send_flyers') && !form.elements.new_email.value) {
            showMessage('Für den Versand der Produktflyer ist eine E-Mail-Adresse erforderlich.', true);
            form.elements.new_email.focus();
            return;
        }
        const sendsEmail = button.value === 'email_callback'
            || button.value === 'send_flyers'
            || (button.value === 'interested' && form.elements.email_requested.checked);
        if (sendsEmail && form.querySelectorAll('input[name="flyers[]"]:checked').length === 0) {
            showMessage('Bitte wählen Sie mindestens einen passenden Produktflyer aus.', true);
            form.querySelector('input[name="flyers[]"]')?.focus();
            return;
        }
        if (sendsEmail && !form.elements.email_address_confirmed.checked) {
            showMessage('Bitte bestätigen Sie, dass der Kontakt diese einmalige Informationsmail ausdrücklich angefordert hat.', true);
            form.elements.email_address_confirmed.focus();
            return;
        }

        if (sendsEmail) {
            await openEmailComposer(form, button);
            return;
        }

        await submitSpeedPhoneAction(form, button);
    });

    root.addEventListener('click', async function (event) {
        const historyToggle = event.target.closest('[data-call-history-toggle]');
        if (historyToggle) {
            const panel = document.getElementById('speedphone-call-history');
            panel.hidden = !panel.hidden;
            historyToggle.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) { await refreshCallHistory(true); panel.scrollIntoView({behavior:'smooth',block:'start'}); }
            return;
        }
        const historyPageButton = event.target.closest('[data-history-page]');
        if (historyPageButton) {
            callHistoryPage = Number(historyPageButton.dataset.historyPage) || 1;
            await refreshCallHistory(true);
            return;
        }
        const historyOpen = event.target.closest('[data-speedphone-history-open]');
        if (historyOpen) {
            if (historyOpenInFlight) { return; }
            const currentForm = document.getElementById('speedphone-form');
            if (currentForm?.classList.contains('is-busy')) { return; }
            const data = new FormData();
            data.set('operation','open_call_history');
            data.set('call_id',historyOpen.dataset.speedphoneHistoryOpen);
            data.set('csrf',root.dataset.csrf);
            historyOpenInFlight = true;
            historyOpen.disabled = true;
            storeCurrentDraft(currentForm);
            if (currentForm) { setBusy(currentForm,true); }
            try {
                const payload = await request(data);
                workspace.innerHTML = payload.data.workspace_html;
                restoreDraft(document.getElementById('speedphone-form'));
                updateStatistics(payload.data.statistics || {});
                updateLiveStatus(payload.data.expires_at);
                startLiveUpdates();
                workspace.scrollIntoView({behavior:'smooth',block:'start'});
                showMessage((payload.data.display_name || 'Kontakt') + ' wurde wieder in SpeedPhone geöffnet. Es wurde noch kein Anruf gestartet.',false);
                refreshCallHistory(true);
            } catch (error) {
                showMessage(error.message || String(error),true);
            } finally {
                historyOpenInFlight = false;
                historyOpen.disabled = false;
                if (currentForm && document.body.contains(currentForm)) { setBusy(currentForm,false); }
            }
            return;
        }
        const statisticsToggle = event.target.closest('[data-team-statistics-toggle]');
        if (statisticsToggle) {
            const panel = document.getElementById('speedphone-team-statistics');
            panel.hidden = !panel.hidden;
            statisticsToggle.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) {
                await refreshTeamStatistics(true);
                panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
            return;
        }
        if (event.target.closest('[data-email-compose-cancel]')) {
            closeEmailComposer();
            return;
        }

        const composeSendButton = event.target.closest('[data-email-compose-send]');
        if (composeSendButton) {
            const dialog = document.getElementById('speedphone-email-compose-dialog');
            const subject = dialog?.querySelector('[data-email-compose-subject]')?.value.trim() || '';
            const body = dialog?.speedPhoneEditor?.getHtml() || '';
            if (!pendingEmailSubmission || !subject || !dialog?.speedPhoneEditor?.getText()) {
                showMessage('Betreff und E-Mail-Text dürfen nicht leer sein.', true);
                return;
            }
            const pending = pendingEmailSubmission;
            composeSendButton.disabled = true;
            composeSendButton.textContent = 'Wird versendet …';
            const completed = await pending.execute({subject: subject, body: body});
            if (completed) {
                closeEmailComposer();
            } else {
                composeSendButton.disabled = false;
                composeSendButton.textContent = 'E-Mail jetzt versenden';
            }
            return;
        }

        const incomingMatch = event.target.closest('[data-incoming-prospect]');
        if (incomingMatch) {
            const dialog = document.getElementById('speedphone-incoming-dialog');
            const currentForm = document.getElementById('speedphone-form');
            const data = new FormData();
            data.set('operation', 'open_incoming_pbx');
            data.set('event_id', dialog?.dataset.eventId || '');
            data.set('prospect_id', incomingMatch.dataset.incomingProspect || '');
            data.set('csrf', root.dataset.csrf);
            incomingMatch.disabled = true;
            try {
                storeCurrentDraft(currentForm);
                const payload = await request(data);
                workspace.innerHTML = payload.data.workspace_html;
                updateStatistics(payload.data.statistics || {});
                updateLiveStatus(payload.data.expires_at);
                dialog?.close();
                showMessage('Eingehender Anruf: ' + (payload.data.display_name || 'Kontakt') + ' wurde in SpeedPhone geöffnet.', false);
            } catch (error) {
                incomingMatch.disabled = false;
                showMessage(error.message || String(error), true);
            }
            return;
        }

        if (event.target.closest('[data-incoming-dismiss]')) {
            await dismissIncomingPbx();
            return;
        }

        const emailPreviewButton = event.target.closest('[data-speedphone-email-preview]');
        if (emailPreviewButton) {
            const form = document.getElementById('speedphone-form');
            const dialog = document.getElementById('speedphone-email-dialog');
            if (!form || !dialog) {
                return;
            }
            const title = dialog.querySelector('#speedphone-email-dialog-title');
            const recipient = dialog.querySelector('[data-email-preview-recipient]');
            const sentAt = dialog.querySelector('[data-email-preview-date]');
            const note = dialog.querySelector('[data-email-preview-note]');
            const body = dialog.querySelector('[data-email-preview-body]');
            const interactionList = dialog.querySelector('[data-email-preview-interactions]');
            const interactionEmpty = dialog.querySelector('[data-email-preview-activity-empty]');
            const interactionTotal = dialog.querySelector('[data-email-preview-activity-total]');
            const openCount = dialog.querySelector('[data-email-preview-open-count]');
            const clickCount = dialog.querySelector('[data-email-preview-click-count]');
            const lastOpen = dialog.querySelector('[data-email-preview-last-open]');
            const lastClick = dialog.querySelector('[data-email-preview-last-click]');
            title.textContent = 'E-Mail wird geladen …';
            recipient.textContent = '–';
            sentAt.textContent = '–';
            body.textContent = 'Inhalt wird geladen …';
            note.hidden = true;
            note.textContent = '';
            interactionList.replaceChildren();
            interactionEmpty.hidden = true;
            interactionTotal.textContent = 'Wird geladen …';
            openCount.textContent = '0';
            clickCount.textContent = '0';
            lastOpen.textContent = 'zuletzt: –';
            lastClick.textContent = 'zuletzt: –';
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
            const data = new FormData();
            data.set('operation', 'email_preview');
            data.set('prospect_id', form.elements.prospect_id.value);
            data.set('lock_token', form.elements.lock_token.value);
            data.set('email_id', emailPreviewButton.dataset.emailId || '');
            data.set('email_kind', emailPreviewButton.dataset.emailKind || '');
            data.set('csrf', root.dataset.csrf);
            try {
                const payload = await request(data);
                title.textContent = payload.data.subject || 'E-Mail ohne Betreff';
                recipient.textContent = payload.data.recipient || 'Adresse nicht protokolliert';
                sentAt.textContent = formatEmailPreviewDate(payload.data.sent_at);
                note.textContent = payload.data.content_note || '';
                note.hidden = !note.textContent;
                body.textContent = payload.data.body || 'Für diese E-Mail ist kein Inhalt protokolliert.';
                const interactions = Array.isArray(payload.data.interactions) ? payload.data.interactions : [];
                const summary = payload.data.interaction_summary || {};
                openCount.textContent = String(Number(summary.open_count) || 0);
                clickCount.textContent = String(Number(summary.click_count) || 0);
                lastOpen.textContent = 'zuletzt: ' + (summary.last_opened_at ? formatEmailPreviewDate(summary.last_opened_at) : '–');
                lastClick.textContent = 'zuletzt: ' + (summary.last_clicked_at ? formatEmailPreviewDate(summary.last_clicked_at) : '–');
                interactionTotal.textContent = interactions.length === 1 ? '1 Ereignis' : interactions.length + ' Ereignisse';
                interactionEmpty.hidden = interactions.length !== 0;
                interactions.forEach(function (interaction) {
                    const item = document.createElement('li');
                    const heading = document.createElement('div');
                    const label = document.createElement('strong');
                    const time = document.createElement('time');
                    const type = interaction.type === 'clicked' ? 'clicked' : 'opened';
                    item.className = 'email-preview__event email-preview__event--' + type;
                    label.textContent = type === 'clicked' ? 'Link geklickt' : 'E-Mail geöffnet';
                    time.dateTime = interaction.occurred_at || '';
                    time.textContent = formatEmailPreviewDate(interaction.occurred_at);
                    heading.append(label, time);
                    item.append(heading);
                    if (interaction.detail) {
                        const detail = document.createElement('span');
                        detail.textContent = interaction.detail;
                        detail.title = interaction.detail;
                        item.append(detail);
                    }
                    interactionList.append(item);
                });
            } catch (error) {
                body.textContent = error.message || String(error);
                interactionTotal.textContent = 'Nicht verfügbar';
                interactionEmpty.hidden = false;
                showMessage(body.textContent, true);
            }
            return;
        }

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

        const pbxButton = event.target.closest('[data-speedphone-pbx-call]');
        if (pbxButton) {
            const form = document.getElementById('speedphone-form');
            if (!form) {
                return;
            }
            const data = new FormData();
            data.set('operation', 'pbx_call');
            data.set('prospect_id', form.elements.prospect_id.value);
            data.set('lock_token', form.elements.lock_token.value);
            data.set('phone_kind', pbxButton.dataset.speedphonePbxCall || 'work');
            data.set('csrf', root.dataset.csrf);
            const pbxButtons = Array.from(root.querySelectorAll('[data-speedphone-pbx-call]'));
            pbxButtons.forEach(function (button) { button.disabled = true; });
            const originalText = pbxButton.textContent;
            pbxButton.textContent = 'Wird aufgebaut …';
            try {
                const payload = await request(data);
                pbxButton.textContent = 'Durchwahl klingelt';
                showMessage(payload.data.message, false);
            } catch (error) {
                showMessage(error.message || String(error), true);
            } finally {
                if (document.body.contains(pbxButton)) {
                    pbxButtons.forEach(function (button) { button.disabled = false; });
                    pbxButton.textContent = originalText;
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

            await openEmailComposer(
                null,
                ownedEmailButton,
                function (draft) {
                    return submitOwnedEmail(ownedEmailButton, email, draft);
                },
                {
                    prospect_id: ownedEmailButton.dataset.prospectId || '',
                    new_email: email,
                }
            );
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
            if (form.querySelectorAll('input[name="flyers[]"]:checked').length === 0) {
                showMessage('Bitte wählen Sie mindestens einen passenden Produktflyer aus.', true);
                form.querySelector('input[name="flyers[]"]')?.focus();
                return;
            }

            await openEmailComposer(
                form,
                emailRetryButton,
                function (draft) {
                    return submitResendEmail(form, emailRetryButton, draft);
                }
            );
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

        const skipButton = event.target.closest('[data-speedphone-skip]');
        if (skipButton) {
            const form = document.getElementById('speedphone-form');
            if (!form) {
                return;
            }
            const data = new FormData();
            data.set('operation', 'skip_current');
            data.set('prospect_id', form.elements.prospect_id.value);
            data.set('lock_token', form.elements.lock_token.value);
            data.set('csrf', root.dataset.csrf);
            const originalText = skipButton.textContent;
            storeCurrentDraft(form);
            stopLiveUpdates();
            setBusy(form, true);
            skipButton.textContent = 'Wechsle …';
            try {
                const payload = await request(data);
                workspace.innerHTML = payload.data.workspace_html;
                updateStatistics(payload.data.statistics || {});
                renderDialerDevices(payload.data.devices || []);
                updateLiveStatus(payload.data.expires_at);
                restoreDraft(document.getElementById('speedphone-form'));
                startLiveUpdates();
                showMessage(payload.data.message, false);
                workspace.querySelector('.candidate-name')?.focus({preventScroll: true});
            } catch (error) {
                if (document.body.contains(form)) {
                    setBusy(form, false);
                    skipButton.textContent = originalText;
                    skipButton.focus();
                }
                startLiveUpdates();
                showMessage(error.message || String(error), true);
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

    async function openEmailComposer(form, button, execute = null, composeValues = {}) {
        const dialog = document.getElementById('speedphone-email-compose-dialog');
        const recipient = dialog?.querySelector('[data-email-compose-recipient]');
        const subject = dialog?.querySelector('[data-email-compose-subject]');
        const body = dialog?.querySelector('[data-email-compose-body]');
        const attachments = dialog?.querySelector('[data-email-compose-attachments]');
        if (!dialog || !recipient || !subject || !body || !attachments) {
            showMessage('Die E-Mail-Vorschau konnte nicht geöffnet werden.', true);
            return;
        }

        pendingEmailSubmission = {
            execute: execute || function (draft) {
                return submitSpeedPhoneAction(form, button, draft);
            },
        };
        recipient.textContent = composeValues.new_email || form?.elements.new_email?.value || 'Wird geladen …';
        subject.value = '';
        subject.placeholder = 'Entwurf wird geladen …';
        if (!dialog.speedPhoneEditor) { dialog.speedPhoneEditor = new window.SpeedPhoneEmailEditor(dialog); }
        dialog.speedPhoneEditor.setHtml('<p>Entwurf wird geladen …</p>');
        const sendButton = dialog.querySelector('[data-email-compose-send]');
        sendButton.disabled = true;
        attachments.hidden = true;
        attachments.textContent = '';
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        const data = form ? new FormData(form) : new FormData();
        Object.entries(composeValues).forEach(function ([key, value]) {
            data.set(key, value);
        });
        data.set('operation', 'compose_email');
        data.set('csrf', root.dataset.csrf);
        try {
            const payload = await request(data);
            recipient.textContent = payload.data.recipient || 'Keine Empfängeradresse';
            subject.value = payload.data.subject || '';
            dialog.speedPhoneEditor.setHtml(payload.data.body_html || '');
            subject.placeholder = '';
            sendButton.disabled = false;
            const flyers = Array.isArray(payload.data.flyers) ? payload.data.flyers : [];
            if (flyers.length > 0) {
                attachments.textContent = 'Anhänge: ' + flyers.join(', ');
                attachments.hidden = false;
            }
            subject.focus();
        } catch (error) {
            closeEmailComposer();
            showMessage(error.message || String(error), true);
        }
    }

    function closeEmailComposer(clearPending = true) {
        const dialog = document.getElementById('speedphone-email-compose-dialog');
        const sendButton = dialog?.querySelector('[data-email-compose-send]');
        if (dialog?.open && typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog?.removeAttribute('open');
        }
        if (sendButton) {
            sendButton.disabled = false;
            sendButton.textContent = 'E-Mail jetzt versenden';
        }
        if (clearPending) {
            pendingEmailSubmission = null;
        }
    }

    async function submitSpeedPhoneAction(form, button, emailDraft = null) {
        const data = new FormData(form);
        data.set('result', button.value);
        data.set('csrf', root.dataset.csrf);
        if (emailDraft) {
            data.set('email_subject', emailDraft.subject);
            data.set('email_body_html', emailDraft.body);
        }
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
                }
                return false;
            }
            await loadNextCandidate();
            return true;
        } catch (error) {
            showMessage(error.message || String(error), true);
            if (document.body.contains(form)) {
                setBusy(form, false);
                delete button.dataset.submitting;
            }
            return false;
        }
    }

    async function submitResendEmail(form, button, emailDraft) {
        const data = new FormData(form);
        data.set('operation', 'resend_email');
        data.set('email_subject', emailDraft.subject);
        data.set('email_body_html', emailDraft.body);
        data.set('csrf', root.dataset.csrf);
        setBusy(form, true);
        try {
            const payload = await request(data);
            showMessage(payload.data.message, false);
            await loadNextCandidate();
            return true;
        } catch (error) {
            showMessage(error.message || String(error), true);
            if (document.body.contains(form)) {
                setBusy(form, false);
                button.focus();
            }
            return false;
        }
    }

    async function submitOwnedEmail(button, email, emailDraft) {
        const data = new FormData();
        data.set('operation', 'resend_email');
        data.set('prospect_id', button.dataset.prospectId || '');
        data.set('new_email', email);
        data.set('email_address_confirmed', '1');
        data.set('email_subject', emailDraft.subject);
        data.set('email_body_html', emailDraft.body);
        data.set('csrf', root.dataset.csrf);
        button.disabled = true;
        try {
            const payload = await request(data);
            button.textContent = 'Mail versendet';
            showMessage(payload.data.message, false);
            return true;
        } catch (error) {
            button.disabled = false;
            showMessage(error.message || String(error), true);
            return false;
        }
    }

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

    function formatEmailPreviewDate(value) {
        const date = new Date(String(value || '').replace(' ', 'T') + 'Z');
        if (Number.isNaN(date.getTime())) {
            return value || 'Nicht protokolliert';
        }
        return date.toLocaleString('de-DE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        }) + ' Uhr';
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
        updateIndustryCounts(statistics.industry_counts);
        Object.keys(statistics).forEach(function (key) {
            const target = root.querySelector('[data-stat="' + key + '"]');
            if (target) {
                target.textContent = String(statistics[key]);
            }
        });
    }

    function updateIndustryCounts(counts) {
        if (!counts || typeof counts !== 'object' || Array.isArray(counts)) { return; }
        const select = document.getElementById('speedphone-industry');
        if (!select) { return; }
        // Bestehende Optionen erhalten: Auswahl und Fokus bleiben bei AJAX-Updates unangetastet.
        Array.from(select.options).forEach(function (option) {
            if (!Object.prototype.hasOwnProperty.call(counts, option.value)) { return; }
            const count = Number(counts[option.value]);
            if (!Number.isSafeInteger(count) || count < 0 || !option.dataset.industryLabel) { return; }
            const label = option.dataset.industryLabel + ' (' + count + ')';
            if (option.textContent !== label) { option.textContent = label; }
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
            if (payload.data.incoming_call?.source === 'pbx') {
                showIncomingPbx(payload.data.incoming_call);
            }
            if (payload.data.incoming_call?.source !== 'pbx'
                && payload.data.incoming_call
                && payload.data.workspace_html) {
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

    root.addEventListener('change', function (event) {
        const historyFilter = event.target.closest('#speedphone-call-history-filter');
        if (historyFilter) {
            if (event.target.name === 'search') { return; }
            if (event.target.name === 'start' || event.target.name === 'end') { historyFilter.elements.period.value = 'custom'; }
            callHistoryPage = 1;
            refreshCallHistory(true);
            return;
        }
        const filter = event.target.closest('#team-statistics-filter');
        if (!filter) { return; }
        if (event.target.name === 'start' || event.target.name === 'end') {
            filter.elements.period.value = 'custom';
            filter.elements.user_id.value = '';
        } else if (event.target.name === 'period') {
            filter.elements.user_id.value = '';
        }
        refreshTeamStatistics(true);
    });

    function stopTeamStatisticsUpdates() {
        if (teamStatisticsTimer !== null) { window.clearInterval(teamStatisticsTimer); }
        teamStatisticsTimer = null;
    }

    function startTeamStatisticsUpdates() {
        if (teamStatisticsTimer !== null) { return; }
        teamStatisticsTimer = window.setInterval(function () {
            if (!document.body.contains(root)) { stopTeamStatisticsUpdates(); return; }
            refreshTeamStatistics();
            refreshCallHistory();
        }, 30000);
    }

    async function refreshCallHistory(force = false) {
        const panel = document.getElementById('speedphone-call-history');
        const filter = document.getElementById('speedphone-call-history-filter');
        if (!panel || panel.hidden || !filter || (!force && (callHistoryInFlight || historyOpenInFlight || filter.contains(document.activeElement)))) { return; }
        const sequence = ++callHistoryRequest;
        const status = panel.querySelector('[data-call-history-status]');
        const data = new FormData(filter);
        data.set('operation','call_history'); data.set('page',String(callHistoryPage)); data.set('csrf',root.dataset.csrf);
        if (data.get('period') === 'custom' && (!data.get('start') || !data.get('end'))) {
            callHistoryInFlight = false;
            panel.setAttribute('aria-busy','false');
            status.textContent = 'Bitte Beginn und Ende des Zeitraums angeben.'; return;
        }
        callHistoryInFlight = true;
        panel.setAttribute('aria-busy','true');
        status.textContent = 'Anrufliste wird geladen …';
        try {
            const payload = await request(data);
            if (sequence !== callHistoryRequest) { return; }
            const target = panel.querySelector('[data-call-history-report]');
            const openNotes = Array.from(target.querySelectorAll('details[open]'),element=>element.dataset.historyNote);
            const scroll = target.querySelector('.team-report__table-scroll')?.scrollLeft || 0;
            target.innerHTML = payload.data.report_html;
            target.querySelectorAll('details').forEach(function (element) { element.open = openNotes.includes(element.dataset.historyNote); });
            const tableScroll = target.querySelector('.team-report__table-scroll');
            if (tableScroll) { tableScroll.scrollLeft = scroll; }
            callHistoryPage = payload.data.page;
            status.textContent = 'Aktuell · automatische Aktualisierung alle 30 Sekunden.';
            status.classList.remove('team-statistics__error');
        } catch (error) {
            if (sequence !== callHistoryRequest) { return; }
            status.textContent = 'Anrufliste nicht aktualisiert: ' + (error.message || String(error));
            status.classList.add('team-statistics__error');
        } finally {
            if (sequence === callHistoryRequest) { callHistoryInFlight = false; panel.setAttribute('aria-busy','false'); }
        }
    }

    async function refreshTeamStatistics(force = false) {
        const panel = document.getElementById('speedphone-team-statistics');
        const filter = document.getElementById('team-statistics-filter');
        if (!panel || panel.hidden || !filter || (!force && teamStatisticsInFlight)) { return; }
        const status = panel.querySelector('[data-team-statistics-status]');
        if (filter.elements.period.value === 'custom' && (!filter.elements.start.value || !filter.elements.end.value)) {
            teamStatisticsRequest++;
            teamStatisticsInFlight = false;
            panel.setAttribute('aria-busy', 'false');
            status.textContent = 'Bitte Beginn und Ende des Zeitraums angeben.';
            return;
        }
        const sequence = ++teamStatisticsRequest;
        const data = new FormData(filter);
        data.set('operation', 'team_statistics');
        data.set('csrf', root.dataset.csrf);
        teamStatisticsInFlight = true;
        panel.setAttribute('aria-busy', 'true');
        status.textContent = 'Teamstatistik wird aktualisiert …';
        try {
            const payload = await request(data);
            if (sequence !== teamStatisticsRequest) { return; }
            const reportTarget = panel.querySelector('[data-team-statistics-report]');
            const detailsOpen = reportTarget.querySelector('details')?.open || false;
            const scrollPositions = Array.from(reportTarget.querySelectorAll('.team-report__table-scroll, .team-report__timeline-scroll'), element => element.scrollLeft);
            reportTarget.innerHTML = payload.data.report_html;
            const details = reportTarget.querySelector('details');
            if (details) { details.open = detailsOpen; }
            reportTarget.querySelectorAll('.team-report__table-scroll, .team-report__timeline-scroll').forEach(function (element, index) {
                element.scrollLeft = scrollPositions[index] || 0;
            });
            filter.elements.start.value = payload.data.range.start;
            filter.elements.end.value = payload.data.range.end;
            const selected = data.get('user_id') || '';
            filter.elements.user_id.replaceChildren(new Option('Alle Mitarbeiter', ''));
            (payload.data.users || []).forEach(function (user) {
                filter.elements.user_id.add(new Option(user.name, user.id));
            });
            filter.elements.user_id.value = selected;
            status.textContent = 'Für alle SpeedPhone-Mitarbeiter sichtbar · Aktualisierung alle 30 Sekunden.';
            status.classList.remove('team-statistics__error');
        } catch (error) {
            if (sequence !== teamStatisticsRequest) { return; }
            status.textContent = 'Statistik nicht aktualisiert: ' + (error.message || String(error));
            status.classList.add('team-statistics__error');
        } finally {
            if (sequence === teamStatisticsRequest) {
                teamStatisticsInFlight = false;
                panel.setAttribute('aria-busy', 'false');
            }
        }
    }

    function showIncomingPbx(incoming) {
        const dialog = document.getElementById('speedphone-incoming-dialog');
        const phone = dialog?.querySelector('[data-incoming-phone]');
        const title = dialog?.querySelector('[data-incoming-title]');
        const hint = dialog?.querySelector('[data-incoming-hint]');
        const matchesTarget = dialog?.querySelector('[data-incoming-matches]');
        if (!dialog || !phone || !title || !hint || !matchesTarget || !incoming.event_id) {
            return;
        }
        if (dialog.open && dialog.dataset.eventId === incoming.event_id) {
            return;
        }
        dialog.dataset.eventId = incoming.event_id;
        phone.textContent = 'Anrufer: ' + (incoming.caller_phone || 'Nummer nicht übermittelt');
        matchesTarget.replaceChildren();
        const matches = Array.isArray(incoming.matches) ? incoming.matches : [];
        title.textContent = matches.length > 0 ? 'Passenden Kontakt auswählen' : 'Nummer nicht im CRM gefunden';
        hint.textContent = matches.length > 0
            ? 'Die Rufnummer passt zu folgenden Zielkontakten. Wähle den richtigen Betrieb, um ihn reserviert in SpeedPhone zu öffnen.'
            : 'Der Anruf wurde erkannt, aber diese Rufnummer ist bei keinem freigegebenen CRM-Kontakt hinterlegt.';
        matches.forEach(function (match) {
            const button = document.createElement('button');
            const title = document.createElement('strong');
            const details = document.createElement('span');
            const reason = document.createElement('small');
            button.type = 'button';
            button.className = 'incoming-call__match';
            button.dataset.incomingProspect = match.prospect_id || '';
            title.textContent = match.display_name || 'Kontakt ohne Namen';
            details.textContent = [match.city, match.phone_work || match.phone_mobile].filter(Boolean).join(' · ');
            reason.textContent = match.match_label || 'Möglicher Rufnummerntreffer';
            button.append(title, details, reason);
            matchesTarget.append(button);
        });
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    async function dismissIncomingPbx() {
        const dialog = document.getElementById('speedphone-incoming-dialog');
        if (!dialog?.dataset.eventId) {
            dialog?.close();
            return;
        }
        const data = new FormData();
        data.set('operation', 'dismiss_incoming_pbx');
        data.set('event_id', dialog.dataset.eventId);
        data.set('csrf', root.dataset.csrf);
        try {
            await request(data);
            dialog.close();
            dialog.dataset.eventId = '';
        } catch (error) {
            showMessage(error.message || String(error), true);
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

        const currentIndustry = currentMain.querySelector('[data-speedphone-contact-industry]');
        const incomingIndustry = incomingMain.querySelector('[data-speedphone-contact-industry]');
        if (currentIndustry && incomingIndustry) {
            incomingIndustry.replaceWith(currentIndustry);
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
