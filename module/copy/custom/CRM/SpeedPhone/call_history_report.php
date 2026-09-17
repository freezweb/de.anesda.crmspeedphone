<?php /** @var array $history */ ?>
<div class="call-history__summary"><strong><?= (int) $history['total'] ?> <?= $history['total'] === 1 ? 'protokollierter Anruf' : 'protokollierte Anrufe' ?></strong><span>Neueste zuerst · Seite <?= (int) $history['page'] ?> von <?= (int) $history['pages'] ?></span></div>
<?php if ($history['rows'] === []): ?>
    <p>Keine Anrufe für diese Auswahl gefunden.</p>
<?php else: ?>
    <p class="call-history__hint">Die Tabelle kann auf kleinen Bildschirmen seitlich verschoben werden.</p>
    <div class="team-report__table-scroll"><table class="call-history__table"><thead><tr><th>Zeitpunkt</th><th>Kontakt / Telefon</th><th>Telefonierer</th><th>Anrufergebnis</th><th>Notiz</th><th></th></tr></thead><tbody>
    <?php foreach ($history['rows'] as $row): ?>
        <tr><td><?= speedPhoneEscape(speedPhoneDateTime($row['date_start'], $userTimezone)) ?></td>
            <td><strong><?= speedPhoneEscape($row['name']) ?></strong><span><?= speedPhoneEscape($row['phone'] ?: '–') ?></span><small>Aktuell: <?= speedPhoneEscape(speedPhoneStatusLabel($row['current_status'])) ?></small></td>
            <td><?= speedPhoneEscape($row['caller']) ?></td>
            <td><?= speedPhoneEscape($row['result'] === 'legacy' ? 'Altprotokoll' : speedPhoneResultLabel($row['result'])) ?><?= $row['result'] === 'no_interest' ? ' (historisch)' : '' ?></td>
            <td><?php if ($row['note'] !== ''): ?><details data-history-note="<?= speedPhoneEscape($row['call_id']) ?>"><summary>Gesprächsnotiz</summary><p><?= nl2br(speedPhoneEscape($row['note'])) ?></p></details><?php else: ?>–<?php endif; ?></td>
            <td><button type="button" class="button button--info button--compact" data-speedphone-history-open="<?= speedPhoneEscape($row['call_id']) ?>" <?= !$row['can_open'] ? 'disabled' : '' ?>>In SpeedPhone öffnen</button><?php if ($row['unavailable_reason']): ?><small><?= speedPhoneEscape($row['unavailable_reason']) ?></small><?php endif; ?></td>
        </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<nav class="call-history__pagination" aria-label="Seiten der Anrufliste"><button type="button" class="button button--secondary" data-history-page="<?= max(1, $history['page'] - 1) ?>" <?= $history['page'] <= 1 ? 'disabled' : '' ?>>Zurück</button><span>Seite <?= (int) $history['page'] ?> / <?= (int) $history['pages'] ?></span><button type="button" class="button button--secondary" data-history-page="<?= min($history['pages'], $history['page'] + 1) ?>" <?= $history['page'] >= $history['pages'] ? 'disabled' : '' ?>>Weiter</button></nav>
