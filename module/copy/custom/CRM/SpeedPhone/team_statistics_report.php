<?php
/** @var array $report */
$visibleUsers = array_values(array_filter($report['users'], static fn (array $user): bool => $report['selected_user_id'] === '' || $user['id'] === $report['selected_user_id']));
$maxCalls = max(1, 0, ...array_map(static fn (array $user): int => $user['metrics']['processed'], $visibleUsers));
$maxDay = max(1, ...array_column($report['days'], 'processed'));
$total = $report['totals'];
?>
<div class="team-report__meta"><strong><?= speedPhoneEscape((new DateTimeImmutable($report['range']['start']))->format('d.m.Y')) ?> – <?= speedPhoneEscape((new DateTimeImmutable($report['range']['end']))->format('d.m.Y')) ?></strong><span>Stand <?= speedPhoneEscape($report['generated_at']) ?> · Europe/Berlin</span></div>
<div class="team-report__totals">
    <?php foreach (['processed', 'reached', 'not_reached', 'interested', 'email_requested'] as $key): ?>
        <article><strong><?= (int) $total[$key] ?></strong><span><?= speedPhoneEscape(Anesda\CRM\SpeedPhone\TeamStatisticsService::METRICS[$key]) ?></span></article>
    <?php endforeach; ?>
    <article><strong><?= $total['processed'] ? number_format(100 * $total['reached'] / $total['processed'], 1, ',', '.') : '0,0' ?> %</strong><span>Erreichungsquote</span></article>
</div>
<?php if ($total['processed'] === 0): ?>
    <p>Im gewählten Zeitraum sind keine SpeedPhone-Anrufe dokumentiert.</p>
<?php else: ?>
    <div class="team-report__charts">
        <section aria-label="Anrufe nach Mitarbeiter"><h3>Anrufe je Mitarbeiter</h3><p class="team-report__legend"><span>Anrufe gesamt</span><span>Davon erreicht</span></p>
            <?php foreach ($visibleUsers as $user): $metrics = $user['metrics']; ?>
                <div class="team-report__member"><div><strong><?= speedPhoneEscape($user['name']) ?></strong><span><?= (int) $metrics['processed'] ?> Anrufe · <?= (int) $metrics['reached'] ?> erreicht</span></div>
                    <div class="team-report__track" role="img" aria-label="<?= speedPhoneEscape($user['name']) ?>: <?= (int) $metrics['processed'] ?> Anrufe, <?= (int) $metrics['reached'] ?> erreicht"><div class="team-report__bar" style="width:<?= 100 * $metrics['processed'] / $maxCalls ?>%"><div style="width:<?= $metrics['processed'] ? 100 * $metrics['reached'] / $metrics['processed'] : 0 ?>%"></div></div></div>
                </div>
            <?php endforeach; ?>
        </section>
        <section aria-label="Tagesverlauf der Anrufe"><h3>Anrufe im Tagesverlauf</h3><p class="team-report__legend"><span>Anrufe gesamt</span><span>Davon erreicht</span></p>
            <div class="team-report__timeline-scroll"><div class="team-report__timeline" style="min-width:<?= max(260, count($report['days']) * 40) ?>px">
                <?php foreach ($report['days'] as $date => $metrics): ?>
                    <div class="team-report__day" role="img" aria-label="<?= speedPhoneEscape($date) ?>: <?= (int) $metrics['processed'] ?> Anrufe, <?= (int) $metrics['reached'] ?> erreicht" title="<?= speedPhoneEscape((new DateTimeImmutable($date))->format('d.m.Y')) ?>: <?= (int) $metrics['processed'] ?> Anrufe, <?= (int) $metrics['reached'] ?> erreicht">
                        <strong><?= (int) $metrics['processed'] ?></strong><div class="team-report__day-track"><div style="height:<?= 100 * $metrics['processed'] / $maxDay ?>%"><div style="height:<?= $metrics['processed'] ? 100 * $metrics['reached'] / $metrics['processed'] : 0 ?>%"></div></div></div><span><?= speedPhoneEscape((new DateTimeImmutable($date))->format('d.m.')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div></div>
        </section>
    </div>
    <div class="team-report__table-scroll"><table class="team-report__table">
        <caption>Ergebnisse nach Mitarbeiter · Anzahl protokollierter Anrufe</caption>
        <thead><tr><th scope="col">Mitarbeiter</th><?php foreach (Anesda\CRM\SpeedPhone\TeamStatisticsService::METRICS as $label): ?><th scope="col"><?= speedPhoneEscape($label) ?></th><?php endforeach; ?><th scope="col">Erreichungsquote</th></tr></thead>
        <tbody><?php foreach ($visibleUsers as $user): ?><tr><th scope="row"><?= speedPhoneEscape($user['name']) ?></th><?php foreach ($user['metrics'] as $count): ?><td><?= (int) $count ?></td><?php endforeach; ?><td><?= $user['metrics']['processed'] ? number_format(100 * $user['metrics']['reached'] / $user['metrics']['processed'], 1, ',', '.') : '0,0' ?> %</td></tr><?php endforeach; ?></tbody>
        <tfoot><tr><th scope="row">Gesamt</th><?php foreach ($total as $count): ?><td><?= (int) $count ?></td><?php endforeach; ?><td><?= number_format(100 * $total['reached'] / $total['processed'], 1, ',', '.') ?> %</td></tr></tfoot>
    </table></div>
    <details class="team-report__daily-detail"><summary>Tagesdetails: Wer hat wann wie viel bearbeitet?</summary>
        <div class="team-report__table-scroll"><table class="team-report__table"><thead><tr><th scope="col">Tag</th><th scope="col">Mitarbeiter</th><th scope="col">Anrufe</th><th scope="col">Erreicht</th><th scope="col">Nicht erreicht</th><th scope="col">Interesse</th><th scope="col">Mail angefordert</th></tr></thead>
            <tbody><?php foreach (array_reverse(array_keys($report['days'])) as $date): foreach ($visibleUsers as $user): $metrics = $user['days'][$date] ?? null; if (!$metrics || !$metrics['processed']) continue; ?>
                <tr><td><?= speedPhoneEscape((new DateTimeImmutable($date))->format('d.m.Y')) ?></td><th scope="row"><?= speedPhoneEscape($user['name']) ?></th><?php foreach (['processed', 'reached', 'not_reached', 'interested', 'email_requested'] as $key): ?><td><?= (int) $metrics[$key] ?></td><?php endforeach; ?></tr>
            <?php endforeach; endforeach; ?></tbody>
        </table></div>
    </details>
<?php endif; ?>
<p class="team-report__explanation">Gezählt werden dokumentierte ausgehende SpeedPhone-Anrufe, einschließlich wiederholter Versuche. Geplante Rückrufe und Überspringen zählen nicht. „Erreicht“: Rückruf vereinbart, Mail angefordert, Interesse oder kein Interesse. Kontaktsperren und nicht eindeutig zuordenbare Altprotokolle zählen nicht automatisch als erreicht. „Mail angefordert“ bedeutet nicht automatisch erfolgreich versendet. Ergebnisse können sich überschneiden (z. B. erreicht + Rückruf + Mail). Die Auswertung ist teamweit und unabhängig vom persönlichen Branchen-, Regional- und Zuständigkeitsfilter.</p>
