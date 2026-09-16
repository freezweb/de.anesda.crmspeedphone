<?php

namespace Anesda\CRM\SpeedPhone;

final class EmailService
{
    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser,
        private readonly ?ProductFlyerService $productFlyers = null
    ) {
    }

    /**
     * @param mixed $selection
     * @return list<string>
     */
    public function validateFlyerSelection(mixed $selection): array
    {
        if ($this->productFlyers === null) {
            if ($selection !== [] && $selection !== null && $selection !== '') {
                throw new \RuntimeException('Die Produktflyer sind in SpeedPhone nicht eingerichtet.');
            }

            return [];
        }

        return $this->productFlyers->validateSelection($selection);
    }

    /**
     * @param list<string> $flyerKeys
     */
    public function previewRequestedInformation(
        \Prospect $prospect,
        array $flyerKeys = [],
        string $recipientOverride = ''
    ): array {
        $draft = $this->composeRequestedInformation($prospect, $flyerKeys, $recipientOverride);

        return [
            'recipient' => $draft['email'],
            'subject' => $draft['subject'],
            'body' => $draft['body_text'],
            'flyers' => $draft['flyer_labels'],
        ];
    }

    /**
     * @param list<string> $flyerKeys
     */
    public function sendRequestedInformation(
        \Prospect $prospect,
        bool $explicitOneTimeRequest = false,
        array $flyerKeys = [],
        ?string $customSubject = null,
        ?string $customBodyText = null
    ): array
    {
        if (!(bool) $this->config->get('email_sending_enabled', false)) {
            return ['sent' => false, 'message' => 'E-Mail-Versand ist in SpeedPhone noch deaktiviert.'];
        }

        $draft = $this->composeRequestedInformation($prospect, $flyerKeys);
        $email = $draft['email'];
        $attachments = $draft['attachments'];
        $flyerLabels = $draft['flyer_labels'];
        $suppressionBypassed = $this->assertAddressMayReceiveEmail(
            $prospect->id,
            $email,
            $explicitOneTimeRequest
        );

        $emailBean = \BeanFactory::newBean('Emails');
        $defaults = $emailBean->getSystemDefaultEmail();
        $mailSubject = $customSubject === null ? $draft['subject'] : trim($customSubject);
        $bodyText = $customBodyText === null
            ? $draft['body_text']
            : trim(str_replace(["\r\n", "\r"], "\n", $customBodyText));
        if ($mailSubject === '' || $bodyText === '') {
            throw new \InvalidArgumentException('Betreff und E-Mail-Text dürfen nicht leer sein.');
        }
        $bodyHtml = $customBodyText === null || $bodyText === $draft['body_text']
            ? $draft['body_html']
            : self::editableTextToHtml($bodyText);
        $transportReference = null;
        if ((bool) $this->config->get('mail_api_enabled', false) && $attachments === []) {
            $transportReference = $this->sendThroughMailApi(
                $prospect,
                $email,
                $mailSubject,
                $bodyHtml,
                $bodyText,
                (string) $defaults['email'],
                (string) $defaults['name'],
                $suppressionBypassed
            );
        } else {
            $this->sendThroughSuiteCrm(
                $email,
                $mailSubject,
                $bodyHtml,
                $bodyText,
                (string) $defaults['email'],
                (string) $defaults['name'],
                $attachments
            );
        }

        $emailBean->to_addrs = $email;
        $emailBean->type = 'out';
        $emailBean->status = 'sent';
        $emailBean->name = $mailSubject;
        $emailBean->description = $bodyText;
        $emailBean->description_html = $bodyHtml;
        $emailBean->from_addr = (string) $defaults['email'];
        $emailBean->from_name = (string) $defaults['name'];
        $emailBean->parent_type = 'Prospects';
        $emailBean->parent_id = $prospect->id;
        $emailBean->assigned_user_id = $this->currentUser->id;
        $emailBean->date_sent_received = \TimeDate::getInstance()->nowDb();
        if ($transportReference !== null) {
            $emailBean->description = "Anesda-Mail-ID: {$transportReference}\n\n" . $emailBean->description;
        }
        if ($flyerLabels !== []) {
            $emailBean->description = 'Angehängte Produktflyer: ' . implode(', ', $flyerLabels)
                . "\n\n" . $emailBean->description;
        }
        if ($suppressionBypassed) {
            $emailBean->description = "Einmaliger Versand auf ausdrückliche telefonische Anforderung.\n\n"
                . $emailBean->description;
        }
        $emailBean->save();

        return [
            'sent' => true,
            'message' => $suppressionBypassed
                ? 'Die ausdrücklich angeforderte Informationsmail wurde einmalig versendet und protokolliert; die globale E-Mail-Sperre bleibt bestehen.'
                : ($flyerLabels === []
                    ? 'Informationsmail wurde versendet und protokolliert.'
                    : 'Produktflyer wurden versendet und im Kontaktverlauf protokolliert.'),
            'one_time_override' => $suppressionBypassed,
            'anesda_message_id' => $transportReference,
            'flyers' => $flyerLabels,
        ];
    }

    /**
     * @param list<string> $flyerKeys
     * @return array{email:string,subject:string,body_html:string,body_text:string,attachments:array,flyer_labels:list<string>}
     */
    private function composeRequestedInformation(
        \Prospect $prospect,
        array $flyerKeys,
        string $recipientOverride = ''
    ): array {
        $flyerKeys = $this->validateFlyerSelection($flyerKeys);
        $attachments = $this->productFlyers?->loadSelected($flyerKeys) ?? [];
        $flyerLabels = array_values(array_map('strval', array_column($attachments, 'label')));
        $email = trim($recipientOverride) !== ''
            ? trim($recipientOverride)
            : (string) ($prospect->emailAddress?->getPrimaryAddress($prospect) ?? '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Der Zielkontakt hat keine gültige primäre E-Mail-Adresse.');
        }

        $templateName = $this->config->requireString('email_template_name');
        $templateSql = "SELECT id FROM email_templates
                        WHERE deleted=0 AND name='" . $this->db->quote($templateName) . "'
                        ORDER BY date_modified DESC LIMIT 1";
        $templateRow = $this->db->fetchByAssoc($this->db->query($templateSql));
        $template = \BeanFactory::getBean('EmailTemplates', (string) ($templateRow['id'] ?? ''));
        if (empty($template->id)) {
            throw new \RuntimeException(sprintf('Die E-Mail-Vorlage „%s“ wurde nicht gefunden.', $templateName));
        }

        $replacements = [
            '$account_name' => (string) ($prospect->account_name ?: $prospect->last_name),
            '$first_name' => (string) $prospect->first_name,
            '$last_name' => (string) $prospect->last_name,
        ];
        $subject = from_html(strtr((string) $template->subject, $replacements));
        $bodyHtml = self::decodeStoredHtml(strtr((string) $template->body_html, $replacements));
        $bodyText = trim(strip_tags(strtr((string) $template->body, $replacements) ?: $bodyHtml));
        if ($flyerLabels !== []) {
            $joinedLabels = implode(', ', $flyerLabels);
            $subject = mb_strlen($joinedLabels, 'UTF-8') <= 120
                ? 'Ihre Unterlagen: ' . $joinedLabels
                : 'Ihre ausgewählten Produktinformationen von Anesda Nord';
            $flyerHtml = '<div style="margin:20px 0;padding:16px;border:1px solid #d8e1e6;'
                . 'border-left:5px solid #087ea4;background:#f5fafb">'
                . '<strong>Wie telefonisch besprochen, erhalten Sie folgende Produktunterlagen:</strong><ul><li>'
                . implode('</li><li>', array_map(
                    static fn (string $label): string => htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $flyerLabels
                ))
                . '</li></ul></div>';
            $bodyHtml = str_contains(strtolower($bodyHtml), '</body>')
                ? preg_replace('~</body>~i', $flyerHtml . '</body>', $bodyHtml, 1) ?? ($bodyHtml . $flyerHtml)
                : $bodyHtml . $flyerHtml;
            $bodyText = "Wie telefonisch besprochen, erhalten Sie folgende Produktunterlagen: "
                . $joinedLabels . ".\n\n" . $bodyText;
        }

        return [
            'email' => $email,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'attachments' => $attachments,
            'flyer_labels' => $flyerLabels,
        ];
    }

    private static function editableTextToHtml(string $bodyText): string
    {
        $escaped = htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;'
            . 'color:#17202a;max-width:720px;margin:0 auto">'
            . nl2br($escaped, false)
            . '</div>';
    }

    /**
     * @param list<array{filename: string, mime: string, content: string}> $attachments
     */
    private function sendThroughSuiteCrm(
        string $email,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        string $fromAddress,
        string $fromName,
        array $attachments
    ): void {
        require_once 'include/SugarPHPMailer.php';
        $mail = new \SugarPHPMailer();
        $mail->setMailerForSystem();
        $mail->From = $fromAddress;
        $mail->FromName = $fromName;
        $mail->ClearAllRecipients();
        $mail->ClearReplyTos();
        $mail->AddAddress($email);
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->isHTML(true);
        foreach ($attachments as $attachment) {
            $mail->AddStringAttachment(
                $attachment['content'],
                $attachment['filename'],
                'base64',
                $attachment['mime']
            );
        }
        $mail->prepForOutbound();
        if (!$mail->Send()) {
            throw new \RuntimeException('Die Informationsmail konnte nicht versendet werden: ' . $mail->ErrorInfo);
        }
    }

    public static function decodeStoredHtml(string $html): string
    {
        if (preg_match('/^\s*&lt;(?:!DOCTYPE|html|table|div|p)\b/i', $html) !== 1) {
            return $html;
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function sendThroughMailApi(
        \Prospect $prospect,
        string $email,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        string $fromAddress,
        string $fromName,
        bool $suppressionBypassed
    ): string {
        $url = $this->config->requireString('mail_api_url');
        $apiKey = $this->config->requireString('mail_api_key');
        $tenantId = (int) $this->config->get('mail_api_tenant_id', 0);
        $accountId = (int) $this->config->get('mail_api_account_id', 0);
        if ($tenantId < 1 || $accountId < 1 || !str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Die Anesda-Mail-API ist unvollständig oder unsicher konfiguriert.');
        }
        $payload = json_encode([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'from_address' => strtolower($fromAddress),
            'from_name' => $fromName,
            'to' => [['email' => strtolower($email)]],
            'subject' => $subject,
            'text' => $bodyText,
            'html' => $bodyHtml,
            'tags' => ['speedphone'],
            'metadata' => [
                'source' => 'suitecrm-speedphone',
                'crm_target_id' => (string) $prospect->id,
                'crm_target_type' => 'Prospects',
                'one_time_override' => $suppressionBypassed,
            ],
            'track_opens' => true,
            'track_clicks' => true,
            'allow_suppressed' => $suppressionBypassed,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response) || $status !== 202) {
            throw new \RuntimeException('Anesda-Mail-API hat den Versand nicht angenommen: '
                . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        $messageId = (string) ($decoded['message_id'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/', $messageId) !== 1) {
            throw new \RuntimeException('Anesda-Mail-API lieferte keine gültige Nachrichten-ID.');
        }
        return $messageId;
    }

    private function assertAddressMayReceiveEmail(
        string $prospectId,
        string $email,
        bool $explicitOneTimeRequest
    ): bool
    {
        $sql = "SELECT ea.opt_out, ea.invalid_email
                FROM email_addresses ea
                INNER JOIN email_addr_bean_rel er ON er.email_address_id=ea.id
                WHERE er.deleted=0 AND ea.deleted=0
                  AND er.bean_module='Prospects'
                  AND er.bean_id='" . $this->db->quote($prospectId) . "'
                  AND LOWER(ea.email_address)=LOWER('" . $this->db->quote($email) . "')
                LIMIT 1";
        $result = $this->db->query($sql);
        $row = $this->db->fetchByAssoc($result);
        if (!$row) {
            throw new \RuntimeException('Die primäre E-Mail-Adresse ist nicht mit dem Zielkontakt verknüpft.');
        }
        if ((int) $row['opt_out'] === 1 || (int) $row['invalid_email'] === 1) {
            if (!$explicitOneTimeRequest) {
                throw new \RuntimeException(
                    'Die E-Mail-Adresse ist abgemeldet oder als ungültig markiert. '
                    . 'Ein einmaliger Versand ist nur nach ausdrücklicher Anforderung im aktuellen Gespräch möglich.'
                );
            }
            $GLOBALS['log']->warn(sprintf(
                'CRM SpeedPhone: Einmaliger ausdrücklich angeforderter Versand an gesperrte Adresse für Prospect %s durch Benutzer %s.',
                $prospectId,
                (string) $this->currentUser->id
            ));
            return true;
        }

        return false;
    }
}
