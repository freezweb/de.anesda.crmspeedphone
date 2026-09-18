/* Formatierter Mailentwurf ohne externe Bibliotheken; Skripte im Mailinhalt bleiben gesperrt. */
window.SpeedPhoneEmailEditor = class {
    constructor(dialog) {
        this.dialog = dialog;
        this.frame = dialog.querySelector('[data-email-compose-body]');
        this.range = null;
        this.linkElement = null;
        dialog.querySelectorAll('[data-email-editor-command]').forEach(button => {
            button.addEventListener('mousedown', event => event.preventDefault());
            button.addEventListener('click', () => this.command(button.dataset.emailEditorCommand));
        });
        dialog.querySelector('[data-email-editor-link-save]').addEventListener('click', () => {
            const url = dialog.querySelector('[data-email-editor-link-url]').value.trim();
            if (!/^(https?:\/\/[^\s<>]+|mailto:[^\s<>]+|tel:\+?[0-9 ()./-]+)$/i.test(url)) {
                dialog.querySelector('[data-email-editor-link-url]').setCustomValidity('Bitte eine gültige HTTPS-, HTTP-, E-Mail- oder Telefonadresse eingeben.');
                dialog.querySelector('[data-email-editor-link-url]').reportValidity();
                return;
            }
            this.frame.contentWindow.focus();
            const selection = this.frame.contentWindow.getSelection();
            if (this.range) { selection.removeAllRanges(); selection.addRange(this.range); }
            if (this.linkElement && this.range?.collapsed) {
                this.linkElement.setAttribute('href', url);
            } else {
                this.frame.contentDocument.execCommand('createLink', false, url);
            }
            dialog.querySelector('[data-email-editor-link]').hidden = true;
        });
        dialog.querySelector('[data-email-editor-link-url]').addEventListener('input', event => event.target.setCustomValidity(''));
        dialog.querySelector('[data-email-editor-link-cancel]').addEventListener('click', () => { dialog.querySelector('[data-email-editor-link]').hidden = true; });
    }

    setHtml(html) {
        const doc = this.frame.contentDocument;
        doc.open();
        doc.write('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>body{margin:12px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#17202a;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%;box-sizing:border-box}a{cursor:text}</style></head><body></body></html>');
        doc.close();
        doc.body.innerHTML = html;
        doc.body.contentEditable = 'true';
        doc.body.setAttribute('role', 'textbox');
        doc.body.setAttribute('aria-label', 'E-Mail-Nachricht');
        doc.body.setAttribute('aria-multiline', 'true');
        doc.addEventListener('click', event => {
            this.linkElement = event.target.closest('a');
            if (this.linkElement) { event.preventDefault(); }
        });
        doc.addEventListener('keydown', () => { this.linkElement = null; });
        doc.addEventListener('paste', event => {
            event.preventDefault();
            doc.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
        });
        this.dialog.querySelector('[data-email-editor-link]').hidden = true;
        this.range = null;
        this.linkElement = null;
    }

    getHtml() { return this.frame.contentDocument.body.innerHTML.trim(); }
    getText() { return this.frame.contentDocument.body.textContent.trim(); }

    command(command) {
        if (command === 'createLink') {
            const selection = this.frame.contentWindow.getSelection();
            this.range = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
            const anchor = selection.anchorNode?.nodeType === 1 ? selection.anchorNode : selection.anchorNode?.parentElement;
            const parent = anchor?.closest('a') || this.linkElement;
            this.linkElement = parent;
            const field = this.dialog.querySelector('[data-email-editor-link-url]');
            field.value = parent?.getAttribute('href') || '';
            field.setCustomValidity('');
            this.dialog.querySelector('[data-email-editor-link]').hidden = false;
            field.focus();
            return;
        }
        this.frame.contentWindow.focus();
        this.frame.contentDocument.execCommand(command, false, null);
    }
};
