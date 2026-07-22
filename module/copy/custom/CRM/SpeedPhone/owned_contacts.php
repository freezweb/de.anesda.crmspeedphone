<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

?>
<section id="speedphone-owned-contacts" class="owned-contacts" hidden>
    <div class="owned-contacts__heading">
        <div>
            <p class="speedphone__eyebrow">Exklusive Betreuung</p>
            <h2>Meine Kontakte</h2>
            <p>Hier stehen vorhandene Zielkontakte aus deiner SpeedPhone-Betreuung sowie von dir selbst angelegte CRM-Interessenten. Es werden keine Kopien angelegt.</p>
        </div>
        <button type="button" class="button button--secondary" data-speedphone-owned-toggle aria-expanded="true">Schließen</button>
    </div>
    <?php if ($ownedContacts === []): ?>
        <p class="owned-contacts__empty">Dir ist aktuell noch kein Kontakt exklusiv zugeordnet.</p>
    <?php else: ?>
        <div class="owned-contacts__table-wrap">
            <table class="owned-contacts__table">
                <thead>
                    <tr>
                        <th>Kontakt</th>
                        <th>Telefon</th>
                        <th>E-Mail</th>
                        <th>Status</th>
                        <th>Zuordnung</th>
                        <th>Wiedervorlage</th>
                        <th>Letzter Kontakt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ownedContacts as $contact): ?>
                    <tr>
                        <td><strong><?= speedPhoneEscape($contact['name'] ?: 'Unbenannter Zielkontakt') ?></strong></td>
                        <td><?= speedPhoneEscape($contact['phone_work'] ?: $contact['phone_mobile']) ?></td>
                        <td><?= speedPhoneEscape($contact['email'] ?: '–') ?></td>
                        <td><?= speedPhoneEscape($contact['record_module'] === 'Prospects' ? speedPhoneStatusLabel($contact['speedphone_status']) : ($contact['speedphone_status'] ?: 'Offen')) ?></td>
                        <td><?= speedPhoneEscape($contact['ownership_source']) ?></td>
                        <td><?= !empty($contact['speedphone_next_call']) ? speedPhoneEscape(speedPhoneDateTime($contact['speedphone_next_call'], $userTimezone)) : '–' ?></td>
                        <td><?= !empty($contact['last_contact_at']) ? speedPhoneEscape(speedPhoneDateTime($contact['last_contact_at'], $userTimezone)) : 'Noch kein Gespräch' ?></td>
                        <td class="owned-contacts__actions">
                            <?php if ($contact['record_module'] === 'Prospects' && !empty($contact['email'])): ?>
                                <button
                                    type="button"
                                    class="button button--mail button--compact"
                                    data-speedphone-owned-email
                                    data-prospect-id="<?= speedPhoneEscape($contact['id']) ?>"
                                    data-email="<?= speedPhoneEscape($contact['email']) ?>"
                                    data-contact-name="<?= speedPhoneEscape($contact['name']) ?>"
                                >Info-Mail senden</button>
                            <?php endif; ?>
                            <a class="record-link" href="index.php?module=<?= speedPhoneEscape($contact['record_module']) ?>&amp;action=DetailView&amp;record=<?= speedPhoneEscape($contact['id']) ?>">CRM öffnen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
