(function () {
    'use strict';

    const root = document.querySelector('.speedphone');
    const form = document.getElementById('speedphone-form');
    const message = document.getElementById('speedphone-message');
    if (!root || !form || !message) {
        return;
    }

    const heartbeatTimer = window.setInterval(refreshLock, 60000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshLock();
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = event.submitter;
        if (!button || !button.value) {
            return;
        }

        if (button.value === 'callback' && !form.elements.callback_at.value) {
            showMessage('Für den Rückruf müssen Datum und Uhrzeit eingetragen werden.', true);
            form.elements.callback_at.focus();
            return;
        }

        const data = new FormData(form);
        data.set('result', button.value);
        data.set('csrf', root.dataset.csrf);
        setBusy(true);

        try {
            const response = await fetch(root.dataset.apiUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || 'Das Ergebnis konnte nicht gespeichert werden.');
            }
            const emailResult = payload.data.email;
            const emailMessage = emailResult && emailResult.message
                ? ' ' + payload.data.email.message
                : '';
            const emailFailed = emailResult && emailResult.sent === false;
            showMessage(payload.data.message + emailMessage, emailFailed);
            window.clearInterval(heartbeatTimer);
            window.setTimeout(function () { window.location.reload(); }, emailFailed ? 3500 : 650);
        } catch (error) {
            showMessage(error.message || String(error), true);
            setBusy(false);
        }
    });

    function setBusy(busy) {
        Array.from(form.querySelectorAll('button, input, textarea')).forEach(function (element) {
            element.disabled = busy;
        });
        form.classList.toggle('is-busy', busy);
    }

    function showMessage(text, isError) {
        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('message--error', isError);
        message.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    async function refreshLock() {
        if (!form.elements.prospect_id || !form.elements.lock_token) {
            return;
        }

        const data = new FormData();
        data.set('operation', 'heartbeat');
        data.set('prospect_id', form.elements.prospect_id.value);
        data.set('lock_token', form.elements.lock_token.value);
        data.set('csrf', root.dataset.csrf);

        try {
            const response = await fetch(root.dataset.apiUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            if (!response.ok) {
                const payload = await response.json();
                window.clearInterval(heartbeatTimer);
                showMessage(payload.error || 'Die Kontaktreservierung ist abgelaufen. Bitte neu laden.', true);
                setBusy(true);
            }
        } catch (error) {
            // Ein kurzer Netzausfall darf ein laufendes Gespräch nicht unterbrechen.
        }
    }
}());
