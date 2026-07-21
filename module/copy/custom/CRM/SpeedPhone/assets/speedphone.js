(function () {
    'use strict';

    const root = document.querySelector('.speedphone');
    const form = document.getElementById('speedphone-form');
    const message = document.getElementById('speedphone-message');
    if (!root || !form || !message) {
        return;
    }

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
}());
