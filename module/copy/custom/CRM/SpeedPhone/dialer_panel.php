<section id="speedphone-dialer-panel" class="dialer-panel" hidden>
    <div class="dialer-panel__heading">
        <div>
            <p class="speedphone__eyebrow">SpeedPhone Dialer App</p>
            <h2>Handy koppeln</h2>
            <p>App öffnen, „QR-Code scannen“ wählen und den Code hier erfassen. Der Code gilt einmalig für zehn Minuten.</p>
        </div>
        <button type="button" class="button button--secondary button--compact" data-speedphone-dialer-close>Schließen</button>
    </div>
    <div class="dialer-panel__body">
        <div class="dialer-panel__qr-wrap">
            <div class="dialer-panel__qr" data-speedphone-dialer-qr aria-live="polite">
                <span>QR-Code wird geladen …</span>
            </div>
            <p data-speedphone-dialer-expiry></p>
            <button type="button" class="button button--secondary button--compact" data-speedphone-dialer-refresh>Neuen QR-Code erzeugen</button>
        </div>
        <div>
            <h3>Gekoppelte Geräte</h3>
            <div data-speedphone-dialer-devices>
                <?php if (empty($dialerDevices)): ?>
                    <p class="dialer-panel__empty">Noch kein Gerät gekoppelt.</p>
                <?php else: ?>
                    <?php foreach ($dialerDevices as $device): ?>
                        <article class="dialer-device">
                            <div>
                                <strong><?= speedPhoneEscape($device['device_name']) ?></strong>
                                <span><?= speedPhoneEscape(ucfirst((string) $device['platform'])) ?> · <?= (int) $device['is_ready'] === 1 ? 'empfangsbereit' : 'App derzeit nicht aktiv' ?></span>
                            </div>
                            <button type="button" class="button button--danger button--compact" data-speedphone-dialer-revoke="<?= speedPhoneEscape($device['id']) ?>">Trennen</button>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <p class="dialer-panel__security"><strong>Sicher gekoppelt:</strong> Der QR-Code enthält nur Serveradresse und einen kurzlebigen Einmalcode. CRM-Passwort und Kontakte werden nicht auf das Handy übertragen.</p>
</section>
