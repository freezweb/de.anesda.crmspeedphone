<section id="speedphone-team-settings" class="team-settings" hidden>
    <div class="team-settings__heading">
        <div>
            <p class="speedphone__eyebrow">Administration</p>
            <h2>Team, Rechte und Provision</h2>
            <p>Externe sehen eigene und noch freie Kontakte aus dem gemeinsamen Pool. Erst ein erreichtes Gespräch ordnet einen freien Kontakt exklusiv zu; erfolglose Versuche tun das nicht. Die Festnetz-Durchwahl bestimmt, welcher Apparat beim Click-to-Call zuerst klingelt.</p>
        </div>
        <button type="button" class="button button--secondary" data-speedphone-team-toggle>Schließen</button>
    </div>

    <form id="speedphone-team-form">
        <div class="team-settings__table-wrap">
            <table class="team-settings__table">
                <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th>SpeedPhone-Rolle</th>
                        <th>Provision</th>
                        <th>Festnetz-Durchwahl</th>
                        <th>Freie Kontakte</th>
                        <th>Team verwalten</th>
                        <th>Betreut / gewonnen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($teamUsers as $teamUser): ?>
                    <tr>
                        <td>
                            <strong><?= speedPhoneEscape($teamUser['name']) ?></strong>
                            <span><?= speedPhoneEscape($teamUser['user_name']) ?><?= $teamUser['is_admin'] ? ' · CRM-Admin' : '' ?></span>
                        </td>
                        <td>
                            <select name="user_type[<?= speedPhoneEscape($teamUser['id']) ?>]" data-speedphone-role>
                                <option value="internal"<?= $teamUser['user_type'] === 'internal' ? ' selected' : '' ?>>Intern</option>
                                <option value="external"<?= $teamUser['user_type'] === 'external' ? ' selected' : '' ?>>Extern</option>
                                <option value="disabled"<?= $teamUser['user_type'] === 'disabled' ? ' selected' : '' ?>>Kein Zugriff</option>
                            </select>
                        </td>
                        <td>
                            <label class="team-settings__commission">
                                <input type="number" min="0" max="100" step="0.01" name="commission_percent[<?= speedPhoneEscape($teamUser['id']) ?>]" value="<?= speedPhoneEscape(number_format((float) $teamUser['commission_percent'], 2, '.', '')) ?>">
                                <span>%</span>
                            </label>
                        </td>
                        <td>
                            <input
                                class="team-settings__extension"
                                type="text"
                                inputmode="numeric"
                                pattern="[1-9][0-9]{2,7}"
                                maxlength="8"
                                name="pbx_extension[<?= speedPhoneEscape($teamUser['id']) ?>]"
                                value="<?= speedPhoneEscape($teamUser['pbx_extension']) ?>"
                                placeholder="z. B. 6010"
                                aria-label="Festnetz-Durchwahl von <?= speedPhoneEscape($teamUser['name']) ?>"
                            >
                        </td>
                        <td class="team-settings__check">
                            <input type="checkbox" name="can_receive_unassigned[<?= speedPhoneEscape($teamUser['id']) ?>]" value="1"<?= $teamUser['can_receive_unassigned'] ? ' checked' : '' ?> aria-label="Darf freie Kontakte erhalten">
                        </td>
                        <td class="team-settings__check">
                            <input type="checkbox" name="can_manage[<?= speedPhoneEscape($teamUser['id']) ?>]" value="1"<?= $teamUser['can_manage'] ? ' checked' : '' ?> aria-label="Darf Team verwalten">
                        </td>
                        <td><?= (int) $teamUser['assigned_count'] ?> / <?= (int) $teamUser['won_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="team-settings__rules">
            <label>
                Rückruf intern freigeben nach
                <span><input type="number" name="callback_escalation_days" min="0" max="30" value="<?= (int) $escalationOptions['callback_escalation_days'] ?>"> Tagen Überfälligkeit</span>
            </label>
            <label>
                Extern betreuten Kontakt intern freigeben nach
                <span><input type="number" name="external_stale_days" min="1" max="180" value="<?= (int) $escalationOptions['external_stale_days'] ?>"> Tagen ohne erreichtes Gespräch</span>
            </label>
        </div>

        <div class="team-settings__footer">
            <p><strong>Zuordnung:</strong> Selbst angelegte Kontakte gehören sofort dem externen Ersteller. Freie Kontakte gehören ab dem ersten erreichten Gespräch dem Telefonierer. Bei „Erreicht · Interesse“ wird dessen aktueller Provisionssatz dauerhaft gespeichert.</p>
            <button type="submit" class="button">Team-Einstellungen speichern</button>
        </div>
    </form>
</section>
