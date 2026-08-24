<?php

namespace Anesda\CRM\SpeedPhone;

final class EmailService
{
    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db,
        private readonly \User $currentUser
    ) {
    }

    public function sendRequestedInformation(\Prospect $prospect, bool $explicitOneTimeRequest = false): array
    {
        if (!(bool) $this->config->get('email_sending_enabled', false)) {
            return ['sent' => false, 'message' => 'E-Mail-Versand ist in SpeedPhone noch deaktiviert.'];
        }

        $templateName = $this->config->requireString('email_template_name');
        $email = (string) ($prospect->emailAddress?->getPrimaryAddress($prospect) ?? '');
        if ($email === '') {
            throw new \RuntimeException('Der Zielkontakt hat keine primäre E-Mail-Adresse.');
        }
        $suppressionBypassed = $this->assertAddressMayReceiveEmail(
            $prospect->id,
            $email,
            $explicitOneTimeRequest
        );

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
        $subject = strtr((string) $template->subject, $replacements);
        $bodyHtml = self::decodeStoredHtml(strtr((string) $template->body_html, $replacements));
        $bodyText = trim(strip_tags(strtr((string) $template->body, $replacements) ?: $bodyHtml));

        $emailBean = \BeanFactory::newBean('Emails');
        $defaults = $emailBean->getSystemDefaultEmail();
        $mailSubject = from_html($subject);
        $transportReference = null;
        if ((bool) $this->config->get('mail_api_enabled', false)) {
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
            require_once 'include/SugarPHPMailer.php';
            $mail = new \SugarPHPMailer();
            $mail->setMailerForSystem();
            $mail->From = $defaults['email'];
            $mail->FromName = $defaults['name'];
            $mail->ClearAllRecipients();
            $mail->ClearReplyTos();
            $mail->AddAddress($email);
            $mail->Subject = $mailSubject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;
            $mail->isHTML(true);
            $mail->prepForOutbound();
            if (!$mail->Send()) {
                throw new \RuntimeException('Die Informationsmail konnte nicht versendet werden: ' . $mail->ErrorInfo);
            }
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
        if ($suppressionBypassed) {
            $emailBean->description = "Einmaliger Versand auf ausdrückliche telefonische Anforderung.\n\n"
                . $emailBean->description;
        }
        $emailBean->save();

        return [
            'sent' => true,
            'message' => $suppressionBypassed
                ? 'Die ausdrücklich angeforderte Informationsmail wurde einmalig versendet und protokolliert; die globale E-Mail-Sperre bleibt bestehen.'
                : 'Informationsmail wurde versendet und protokolliert.',
            'one_time_override' => $suppressionBypassed,
            'anesda_message_id' => $transportReference,
        ];
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
