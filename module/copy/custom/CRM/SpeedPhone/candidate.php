<?php if ($candidate === null): ?>
    <section class="empty">
        <h2>Die aktuelle Warteschlange ist abgearbeitet</h2>
        <p>Es gibt momentan keinen fälligen, freigegebenen Zielkontakt mit Telefonnummer.</p>
    </section>
<?php else: ?>
    <section class="candidate" data-prospect-id="<?= speedPhoneEscape($candidate['id']) ?>">
        <div class="candidate__main">
            <div class="candidate__title">
                <div>
                    <p class="speedphone__eyebrow">Nächster Kontakt · Priorität <?= (int) $candidate['score'] ?></p>
                    <h2 class="candidate-name" tabindex="-1"><?= speedPhoneEscape($candidate['name']) ?></h2>
                    <p><?= speedPhoneEscape(trim(($candidate['primary_address_postalcode'] ?? '') . ' ' . ($candidate['primary_address_city'] ?? ''))) ?></p>
                </div>
                <a class="record-link" href="index.php?module=Prospects&amp;action=DetailView&amp;record=<?= speedPhoneEscape($candidate['id']) ?>" target="_blank" rel="noopener">CRM-Datensatz öffnen</a>
            </div>

            <?php if (!empty($candidate['assignment'])): ?>
                <?php $assignment = $candidate['assignment']; ?>
                <aside class="assignment-banner<?= !empty($assignment['is_escalated']) ? ' assignment-banner--escalated' : '' ?>">
                    <div>
                        <span>SpeedPhone-Betreuung</span>
                        <strong><?= speedPhoneEscape($assignment['owner_name']) ?></strong>
                    </div>
                    <div>
                        <span>Rolle</span>
                        <strong><?= $assignment['owner_type'] === 'external' ? 'Extern' : 'Intern' ?></strong>
                    </div>
                    <?php if ($assignment['owner_type'] === 'external'): ?>
                        <div>
                            <span>Provisionssatz</span>
                            <strong><?= speedPhoneEscape(speedPhonePercent($assignment['owner_commission_percent'])) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($assignment['is_escalated'])): ?>
                        <p>Extern nicht rechtzeitig bearbeitet · jetzt für das interne Team freigegeben</p>
                    <?php elseif (($assignment['owner_user_id'] ?? '') === ($candidate['current_profile']['user_id'] ?? '')): ?>
                        <p>Exklusiv dir zugeordnet · andere Mitarbeiter erhalten diesen Kontakt nicht.</p>
                    <?php endif; ?>
                    <?php if (!empty($assignment['won_by_user_id'])): ?>
                        <p>Gewonnen durch <?= speedPhoneEscape($assignment['won_by_name']) ?> · <?= speedPhoneEscape(speedPhonePercent($assignment['won_commission_percent'])) ?></p>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>

            <div class="contact-grid">
                <?php if (!empty($candidate['phone_work'])): ?>
                    <a class="contact-card contact-card--phone" href="tel:<?= speedPhoneEscape(preg_replace('/[^+0-9]/', '', $candidate['phone_work'])) ?>">
                        <span>Telefon</span><strong><?= speedPhoneEscape($candidate['phone_work']) ?></strong>
                    </a>
                <?php endif; ?>
                <?php if (!empty($candidate['phone_mobile'])): ?>
                    <a class="contact-card contact-card--phone" href="tel:<?= speedPhoneEscape(preg_replace('/[^+0-9]/', '', $candidate['phone_mobile'])) ?>">
                        <span>Mobil</span><strong><?= speedPhoneEscape($candidate['phone_mobile']) ?></strong>
                    </a>
                <?php endif; ?>
                <?php if (!empty($candidate['email'])): ?>
                    <a class="contact-card" href="mailto:<?= speedPhoneEscape($candidate['email']) ?>">
                        <span>E-Mail</span><strong><?= speedPhoneEscape($candidate['email']) ?></strong>
                    </a>
                <?php endif; ?>
                <?php if (!empty($candidate['website'])): ?>
                    <a class="contact-card" href="<?= speedPhoneEscape($candidate['website']) ?>" target="_blank" rel="noopener">
                        <span>Website</span><strong>Website öffnen</strong>
                    </a>
                <?php endif; ?>
            </div>

            <?php
                $linkedIn = $candidate['linkedin'] ?? [];
                $linkedInContacts = (array) ($linkedIn['contacts'] ?? []);
                $linkedInSearchUrl = (string) ($linkedIn['search_url'] ?? (
                    'https://www.linkedin.com/search/results/people/?keywords='
                    . rawurlencode((string) $candidate['name'])
                ));
                $linkedInStatus = (string) ($linkedIn['status'] ?? 'not_loaded');
            ?>
            <section class="linkedin-contacts" aria-label="LinkedIn-Ansprechpartner">
                <div class="linkedin-contacts__heading">
                    <div>
                        <h3>LinkedIn-Ansprechpartner</h3>
                        <span>Automatisch öffentlich gefundene Firmenkontakte</span>
                    </div>
                    <strong><?= count($linkedInContacts) ?></strong>
                </div>
                <?php if ($linkedInContacts !== []): ?>
                    <div class="linkedin-contacts__list">
                        <?php foreach ($linkedInContacts as $linkedInContact): ?>
                            <a
                                class="linkedin-contact"
                                href="<?= speedPhoneEscape($linkedInContact['profile_url']) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <span>
                                    <strong><?= speedPhoneEscape($linkedInContact['person_name']) ?></strong>
                                    <small><?= speedPhoneEscape($linkedInContact['role']) ?></small>
                                </span>
                                <em><?= (int) $linkedInContact['confidence'] ?> % Treffer</em>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($linkedInStatus === 'error'): ?>
                    <p class="linkedin-contacts__empty">Die automatische Profilsuche war gerade nicht erreichbar.</p>
                <?php else: ?>
                    <p class="linkedin-contacts__empty">Noch kein eindeutig zur Firma passendes öffentliches Profil gefunden.</p>
                <?php endif; ?>
                <div class="linkedin-contacts__footer">
                    <a href="<?= speedPhoneEscape($linkedInSearchUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Weitere Personen bei LinkedIn suchen
                    </a>
                    <small>Berufliche Profildaten aus öffentlicher Suche · Zuordnung vor einer Ansprache prüfen.</small>
                </div>
            </section>

            <?php if (!empty($candidate['phone_work']) || !empty($candidate['phone_mobile'])): ?>
                <section class="call-launcher" aria-label="Anruf starten">
                    <div class="call-launcher__method call-launcher__method--mobile" data-speedphone-dialer-ready="<?= $dialerReady ? '1' : '0' ?>">
                        <div class="call-launcher__heading">
                            <strong>Handy</strong>
                            <span><?= $dialerReady ? 'App ist empfangsbereit.' : 'App öffnen oder zuerst koppeln.' ?></span>
                        </div>
                        <div class="call-launcher__buttons">
                            <?php if (!empty($candidate['phone_work'])): ?>
                                <button type="button" class="button button--dialer button--compact" data-speedphone-dialer-call="work"<?= $dialerReady ? '' : ' disabled' ?>>Telefon</button>
                            <?php endif; ?>
                            <?php if (!empty($candidate['phone_mobile'])): ?>
                                <button type="button" class="button button--dialer button--compact" data-speedphone-dialer-call="mobile"<?= $dialerReady ? '' : ' disabled' ?>>Mobil</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="call-launcher__method call-launcher__method--pbx" data-speedphone-pbx-ready="<?= $pbxReady ? '1' : '0' ?>">
                        <div class="call-launcher__heading">
                            <strong>Festnetz<?= $pbxExtension !== '' ? ' · Durchwahl ' . speedPhoneEscape($pbxExtension) : '' ?></strong>
                            <span><?= speedPhoneEscape($pbxMessage) ?></span>
                        </div>
                        <div class="call-launcher__buttons">
                            <?php if (!empty($candidate['phone_work'])): ?>
                                <button type="button" class="button button--pbx button--compact" data-speedphone-pbx-call="work"<?= $pbxReady ? '' : ' disabled' ?>>Telefon</button>
                            <?php endif; ?>
                            <?php if (!empty($candidate['phone_mobile'])): ?>
                                <button type="button" class="button button--pbx button--compact" data-speedphone-pbx-call="mobile"<?= $pbxReady ? '' : ' disabled' ?>>Mobil</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <div class="reasons">
                <?php if (isset($candidate['travel_minutes'])): ?>
                    <span title="<?= speedPhoneEscape((string) ($candidate['travel_note'] ?? '')) ?>">Anfahrt ca. <?= (int) $candidate['travel_minutes'] ?> Min. · PLZ/Ort</span>
                <?php endif; ?>
                <?php foreach ($candidate['reasons'] as $reason): ?>
                    <span><?= speedPhoneEscape($reason) ?></span>
                <?php endforeach; ?>
                <span><?= (int) $candidate['speedphone_attempts'] ?> bisherige SpeedPhone-Versuche</span>
            </div>

            <section class="email-history" aria-label="Gesendete E-Mails">
                <div class="email-history__heading">
                    <h3>Gesendete E-Mails</h3>
                    <span><?= count($candidate['sent_emails']) ?> protokolliert</span>
                </div>
                <?php if (empty($candidate['sent_emails'])): ?>
                    <p class="email-history__empty">Für diesen Kontakt ist noch keine gesendete E-Mail protokolliert.</p>
                <?php else: ?>
                    <?php foreach ($candidate['sent_emails'] as $sentEmail): ?>
                        <article class="email-history__item">
                            <div>
                                <strong><?= speedPhoneEscape($sentEmail['subject']) ?></strong>
                                <span>An: <?= speedPhoneEscape($sentEmail['recipient']) ?></span>
                            </div>
                            <div class="email-history__meta">
                                <time datetime="<?= speedPhoneEscape($sentEmail['sent_at']) ?>"><?= speedPhoneEscape(speedPhoneDateTime($sentEmail['sent_at'], $userTimezone)) ?> Uhr</time>
                                <span><?= speedPhoneEscape($sentEmail['source']) ?></span>
                                <?php if ((int) $sentEmail['opened'] === 1): ?><span class="email-history__signal">geöffnet</span><?php endif; ?>
                                <?php if ((int) $sentEmail['clicked'] === 1): ?><span class="email-history__signal">Link geklickt</span><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="history" aria-label="Kontaktverlauf">
                <div class="history__heading">
                    <h3>Kontaktverlauf</h3>
                    <span><?= count($candidate['recent_calls']) ?> letzte Einträge</span>
                </div>
                <?php if (!empty($candidate['recent_calls'])): ?>
                    <?php foreach ($candidate['recent_calls'] as $call): ?>
                        <article>
                            <div>
                                <strong><?= speedPhoneEscape(speedPhoneResultLabel($call['speedphone_result'] ?: $call['name'])) ?></strong>
                                <span>durch <?= speedPhoneEscape(trim((string) $call['caller_name']) ?: $call['caller_username']) ?></span>
                            </div>
                            <time datetime="<?= speedPhoneEscape($call['date_start']) ?>"><?= speedPhoneEscape(speedPhoneDateTime($call['date_start'], $userTimezone)) ?> Uhr</time>
                            <?php if (!empty($call['description'])): ?><p><?= nl2br(speedPhoneEscape($call['description'])) ?></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="history__empty">Noch kein Anruf protokolliert.</p>
                <?php endif; ?>
            </section>
        </div>

        <form id="speedphone-form" class="quick-form">
            <input type="hidden" name="prospect_id" value="<?= speedPhoneEscape($candidate['id']) ?>">
            <input type="hidden" name="lock_token" value="<?= speedPhoneEscape($candidate['lock_token']) ?>">
            <h3>Anruf schnell eintragen</h3>
            <p class="lock-note">
                Für dich reserviert · andere Telefonierer erhalten inzwischen einen anderen Kontakt.
                <span data-speedphone-live-status>Live-Aktualisierung aktiv</span>
            </p>

            <label for="speedphone-note">Kurze Notiz</label>
            <textarea id="speedphone-note" name="note" rows="4" placeholder="Gespräch, Ansprechpartner oder Grund der Wiedervorlage"></textarea>

            <fieldset class="follow-up-fields">
                <legend>Wiedervorlage oder E-Mail</legend>
                <div class="field-row">
                    <div>
                        <label for="speedphone-callback">Wieder anrufen am</label>
                        <input id="speedphone-callback" type="date" name="callback_date" min="<?= speedPhoneEscape($todayDate) ?>" value="<?= speedPhoneEscape($defaultCallbackDate) ?>" aria-describedby="speedphone-callback-hint">
                    </div>
                    <div>
                        <label for="speedphone-callback-time">Fester Termin <span class="optional">optional</span></label>
                        <input id="speedphone-callback-time" type="time" name="callback_time" aria-describedby="speedphone-callback-hint">
                    </div>
                </div>
                <p id="speedphone-callback-hint" class="field-hint"><strong>Ohne Uhrzeit:</strong> Tagesliste. <strong>Mit Uhrzeit:</strong> fest vereinbarter CRM-Rückruftermin.</p>

                <label for="speedphone-email">Neue/bestätigte E-Mail-Adresse</label>
                <input id="speedphone-email" type="email" name="new_email" value="<?= speedPhoneEscape($candidate['email']) ?>">

                <label class="check-row check-row--confirmation">
                    <input type="checkbox" name="email_address_confirmed" value="1">
                    <span>Der Kontakt hat diese einmalige Informationsmail im aktuellen Gespräch ausdrücklich angefordert und die E-Mail-Adresse bestätigt.</span>
                </label>

                <label class="check-row">
                    <input type="checkbox" name="email_requested" value="1">
                    <span>Beim Klick auf „Erreicht · Interesse“ jetzt eine Informationsmail senden</span>
                </label>
            </fieldset>

            <p class="action-help"><strong>Mail gewünscht, aber noch kein Interesse?</strong> „E-Mail jetzt senden + wieder anrufen“ verwenden.</p>

            <div id="speedphone-email-retry" class="email-retry" hidden>
                <button type="button" class="button button--mail" data-speedphone-email-retry>Informationsmail erneut versuchen</button>
                <small>Dabei wird kein weiterer Anruf protokolliert.</small>
            </div>

            <div class="actions">
                <button type="submit" name="result" value="not_reached" class="button button--warning" title="Anruf protokollieren und automatisch weiter hinten erneut einplanen">Nicht erreicht</button>
                <button type="submit" name="result" value="callback" class="button button--info" title="Am gewählten Tag erneut anrufen; eine Uhrzeit ist nur bei einem festen Termin nötig">Am Datum wieder anrufen</button>
                <button type="submit" name="result" value="email_callback" class="button button--mail" title="E-Mail jetzt senden und den Kontakt offen zur Wiedervorlage halten">E-Mail jetzt senden + wieder anrufen</button>
                <button type="submit" name="result" value="interested" class="button button--success">Erreicht · Interesse</button>
                <button type="submit" name="result" value="no_interest" class="button button--muted">Erreicht · kein Interesse</button>
                <button type="submit" name="result" value="wrong_number" class="button button--danger">Falsche Nummer</button>
                <button type="submit" name="result" value="blocked" class="button button--danger">Dauerhaft nicht mehr kontaktieren</button>
                <button type="submit" name="result" value="later" class="button button--secondary">Ohne Anruf später</button>
            </div>
        </form>
    </section>
<?php endif; ?>
