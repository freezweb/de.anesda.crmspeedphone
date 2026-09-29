<?php /** @var array $callbacks */ ?>
<div class="call-history__summary"><strong><?= (int) $callbacks['total'] ?> <?= (int) $callbacks['total'] === 1 ? 'fälliger Rückruf' : 'fällige Rückrufe' ?></strong><span>Älteste zuerst · Seite <?= (int) $callbacks['page'] ?> von <?= (int) $callbacks['pages'] ?></span></div>
<?php if ($callbacks['rows'] === []): ?>
    <p>Keine fälligen Rückrufe für diese Auswahl gefunden.</p>
<?php else: ?>
    <div class="team-report__table-scroll"><table class="call-history__table"><thead><tr><th>Rückruf am</th><th>Kontakt / Telefon</th><th>Zuständig</th><th>Letzte Gesprächsnotiz</th><th></th></tr></thead><tbody>
    <?php foreach ($callbacks['rows'] as $row): ?>
        <?php $date = speedPhoneDateTime($row['next_call'], $userTimezone); ?>
        <tr><td><?= speedPhoneEscape(str_ends_with($date, ' 00:00') ? substr($date, 0, 10) . ' · Tagesliste' : $date . ' Uhr') ?></td>
            <td><strong><?= speedPhoneEscape($row['name'] ?: 'Unbenannter Zielkontakt') ?></strong><span><?= speedPhoneEscape($row['phone']) ?></span></td>
            <td><?= speedPhoneEscape($row['owner']) ?><?= $row['is_mine'] ? ' · ich' : '' ?></td>
            <td><?php if ($row['note'] !== ''): ?><details data-callback-note="<?= speedPhoneEscape($row['prospect_id']) ?>"><summary>Gesprächsnotiz</summary><p><?= nl2br(speedPhoneEscape($row['note'])) ?></p></details><?php else: ?>–<?php endif; ?></td>
            <td><button type="button" class="button button--info button--compact" data-speedphone-callback-open="<?= speedPhoneEscape($row['prospect_id']) ?>" <?= !$row['can_open'] ? 'disabled' : '' ?>>In SpeedPhone öffnen</button><?php if ($row['unavailable_reason']): ?><small><?= speedPhoneEscape($row['unavailable_reason']) ?></small><?php endif; ?></td>
        </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<nav class="call-history__pagination" aria-label="Seiten der Rückrufliste"><button type="button" class="button button--secondary" data-callback-page="<?= max(1, $callbacks['page'] - 1) ?>" <?= $callbacks['page'] <= 1 ? 'disabled' : '' ?>>Zurück</button><span>Seite <?= (int) $callbacks['page'] ?> / <?= (int) $callbacks['pages'] ?></span><button type="button" class="button button--secondary" data-callback-page="<?= min($callbacks['pages'], $callbacks['page'] + 1) ?>" <?= $callbacks['page'] >= $callbacks['pages'] ? 'disabled' : '' ?>>Weiter</button></nav>
