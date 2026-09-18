<?php

use Anesda\CRM\SpeedPhone\EmailContentService;
use Anesda\CRM\SpeedPhone\EmailService;
use Anesda\CRM\SpeedPhone\EmailTemplateBrandService;

$sample = "Vielen Dank für den netten Austausch.\n[Unverbindlich Kontakt aufnehmen](https://anesda-nord.de/kontakt)\n[Telefon](tel:+4938780579999)";
$template = EmailService::decodeStoredHtml(EmailTemplateBrandService::informationTemplate()['body_html']);
$html = EmailContentService::editableTextToHtml($sample, $template);
check(str_contains($html, '<a href="https://anesda-nord.de/kontakt"'), 'Link-Markierungen werden nicht in HTML-Links umgewandelt.');
check(!str_contains($html, '[Unverbindlich'), 'Link-Markierungen bleiben im HTML sichtbar.');
check(str_contains($html, '<a href="tel:+4938780579999"'), 'Telefonlinks fehlen in bearbeiteten Mails.');
check(substr_count($html, '<img ') === 1, 'Bearbeitete Mails müssen genau ein Footer-Logo enthalten.');
check(str_contains($html, 'https://anesda-nord.de/anesda_logo.png'), 'Das echte Firmenlogo fehlt nach der Bearbeitung.');
check(str_contains(EmailContentService::editableTextToPlain($sample), 'Kontakt aufnehmen (https://'), 'Die Textalternative enthält noch Markdown statt lesbarer Links.');
$unsafe = EmailContentService::editableTextToHtml('<script>alert(1)</script> [X](javascript:alert) [X](data:text/html,evil) [Mail](mailto:sales@example.org)');
check(!str_contains($unsafe, '<script>') && !str_contains($unsafe, 'href="javascript:') && !str_contains($unsafe, 'href="data:'), 'Bearbeitete Mails erlauben aktive Inhalte oder unsichere Links.');
check(str_contains($unsafe, 'href="mailto:sales@example.org"'), 'Sichere E-Mail-Links werden nicht ersetzt.');
check(EmailContentService::footerLogoFromHtml('<img src="https://example.org/hero.png">') === '', 'Ein fremdes Vorlagenbild darf nicht automatisch zum Firmenlogo werden.');
check(EmailContentService::footerLogoFromHtml('<img data-speedphone-footer-logo src="http://example.org/logo.png">') === '', 'Footer-Logos müssen HTTPS verwenden.');
$values = ['$contact_first_name' => 'Antonia & <Nord>', '$first_name' => 'Antonia'];
check(EmailContentService::replaceVariables('Hallo $contact_first_name', $values, true) === 'Hallo Antonia &amp; &lt;Nord&gt;', 'Kundendaten werden im HTML nicht sicher eingesetzt.');
check(EmailContentService::replaceVariables('Hallo $first_name', $values) === 'Hallo Antonia', 'Bearbeitete Texte können keine Platzhalter einsetzen.');
check(!str_contains(EmailContentService::editableTextToHtml($sample), '<img '), 'Neutrale Vorlagen dürfen kein fremdes Firmenlogo bekommen.');

$formatted = '<p><strong>Vielen Dank</strong> für das Gespräch.</p><ul><li>Support</li></ul><a href="https://anesda-nord.de/kontakt">Kontakt</a>' . EmailContentService::footerLogoFromHtml($template);
$clean = EmailContentService::sanitizeHtml($formatted);
check(str_contains($clean, '<strong>Vielen Dank</strong>') && str_contains($clean, '<ul><li>Support</li></ul>'), 'Formatierungen und Aufzählungen gehen beim Versand verloren.');
check(str_contains($clean, 'anesda_logo.png') && str_contains($clean, 'href="https://anesda-nord.de/kontakt"'), 'Logo oder Link gehen beim WYSIWYG-Versand verloren.');
$attack = EmailContentService::sanitizeHtml('<p onclick="evil()" style="color:red;background:url(https://evil.org);position:fixed">Text<script>evil()</script><iframe src="https://evil.org"></iframe><a href="javascript:evil()">X</a><img src="data:text/html,evil" onerror="evil()"></p>');
check(!preg_match('/onclick|onerror|<script|<iframe|javascript:|data:|url\(|position:/i', $attack), 'Der HTML-Editor lässt aktive Inhalte beim Versand zu.');
check(str_contains($attack, 'color:red'), 'Sichere Inline-Formatierung wird unnötig entfernt.');
check(str_contains(EmailContentService::htmlToPlain($formatted), 'Kontakt (https://anesda-nord.de/kontakt)'), 'Die HTML-Nachricht hat keine brauchbare Textalternative.');
check(str_contains(EmailContentService::sanitizeHtml($template), 'data-speedphone-footer-logo'), 'Das Logo fehlt bereits im HTML-Entwurf.');
check(substr_count(EmailContentService::sanitizeHtml($template), 'background-color:#ffffff !important') === 2, 'Kopf- und Footer-Logo verlieren ihren weißen Hintergrund bei der HTML-Bereinigung.');
check(str_contains(EmailContentService::footerLogoFromHtml($template), 'background-color:#ffffff !important'), 'Das Logo alter Textentwürfe hat keinen weißen Hintergrund.');
check(!str_contains(EmailContentService::sanitizeHtml('<p style="background:url(https://evil.org) !important">Text</p>'), 'url('), 'Important darf keine unsicheren CSS-Werte freischalten.');
try { (new Anesda\CRM\SpeedPhone\InputValidator())->emailBodyHtml('<p><br></p><script>evil()</script>'); check(false, 'Eine leere HTML-Nachricht darf nicht versendet werden.'); } catch (InvalidArgumentException) {}
$pageSource = file_get_contents(__DIR__ . '/../module/copy/custom/CRM/SpeedPhone/page.php');
check(str_contains($pageSource, 'sandbox="allow-same-origin"'), 'Der Mailentwurf muss ohne Skriptausführung dargestellt werden.');
check(str_contains($pageSource, 'data-email-editor-command="bold"') && str_contains($pageSource, 'data-email-editor-command="createLink"'), 'Die WYSIWYG-Bedienleiste fehlt.');

$id = 'befc6200-da8e-47a5-9fc8-3b30e8451018';
$imageUrl = 'https://anesda-nord.de/anesda_logo.png';
$secret = 'nur-ein-synthetischer-testschlüssel';
$signature = Anesda\CRM\SpeedPhone\EmailLogoTrackingService::signature($secret, $id, $imageUrl);
check(Anesda\CRM\SpeedPhone\EmailLogoTrackingService::verify($secret, $id, $imageUrl, $signature), 'Ein gültiger signierter Logo-Abruf wird abgelehnt.');
check(!Anesda\CRM\SpeedPhone\EmailLogoTrackingService::verify($secret, $id, 'https://evil.org/logo', $signature), 'Das Ziel eines signierten Logo-Abrufs ist manipulierbar.');
check(!Anesda\CRM\SpeedPhone\EmailLogoTrackingService::verify($secret, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $imageUrl, $signature), 'Ein Logo-Token ist für andere Mails wiederverwendbar.');
check(!Anesda\CRM\SpeedPhone\EmailLogoTrackingService::verify($secret, $id, $imageUrl, str_repeat('0', 64)), 'Ein ungültiges Logo-Token wird akzeptiert.');
$instrumented = Anesda\CRM\SpeedPhone\EmailLogoTrackingService::instrument(EmailContentService::sanitizeHtml($template), $id, 'https://crm.example.org/legacy', $secret);
check(str_contains($instrumented, 'entryPoint=crmSpeedPhoneEmailLogo') && str_contains($instrumented, 'm=' . $id), 'Das Footer-Logo erhält keinen mailbezogenen Tracking-Link.');
check(substr_count($instrumented, 'entryPoint=crmSpeedPhoneEmailLogo') === 1, 'Nur das Footer-Logo darf instrumentiert werden.');
check(!str_contains(Anesda\CRM\SpeedPhone\EmailLogoTrackingService::instrument($template, $id, 'http://crm.example.org', $secret), 'crmSpeedPhoneEmailLogo'), 'Tracking darf keine unsicheren URLs erzeugen.');
