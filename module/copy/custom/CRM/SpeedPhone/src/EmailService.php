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

    public function sendRequestedInformation(\Prospect $prospect): array
    {
        if (!(bool) $this->config->get('email_sending_enabled', false)) {
            return ['sent' => false, 'message' => 'E-Mail-Versand ist in SpeedPhone noch deaktiviert.'];
        }

        $templateName = $this->config->requireString('email_template_name');
        $email = (string) ($prospect->emailAddress?->getPrimaryAddress($prospect) ?? '');
        if ($email === '') {
            throw new \RuntimeException('Der Zielkontakt hat keine primäre E-Mail-Adresse.');
        }
        $this->assertAddressMayReceiveEmail($prospect->id, $email);

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
        $bodyHtml = strtr((string) $template->body_html, $replacements);
        $bodyText = trim(strip_tags(strtr((string) $template->body, $replacements) ?: $bodyHtml));

        require_once 'include/SugarPHPMailer.php';
        $emailBean = \BeanFactory::newBean('Emails');
        $defaults = $emailBean->getSystemDefaultEmail();
        $mail = new \SugarPHPMailer();
        $mail->setMailerForSystem();
        $mail->From = $defaults['email'];
        $mail->FromName = $defaults['name'];
        $mail->ClearAllRecipients();
        $mail->ClearReplyTos();
        $mail->AddAddress($email);
        $mail->Subject = from_html($subject);
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->isHTML(true);
        $mail->prepForOutbound();

        if (!$mail->Send()) {
            throw new \RuntimeException('Die Informationsmail konnte nicht versendet werden: ' . $mail->ErrorInfo);
        }

        $emailBean->to_addrs = $email;
        $emailBean->type = 'out';
        $emailBean->status = 'sent';
        $emailBean->name = $mail->Subject;
        $emailBean->description = $mail->AltBody;
        $emailBean->description_html = $mail->Body;
        $emailBean->from_addr = $mail->From;
        $emailBean->from_name = $mail->FromName;
        $emailBean->parent_type = 'Prospects';
        $emailBean->parent_id = $prospect->id;
        $emailBean->assigned_user_id = $this->currentUser->id;
        $emailBean->date_sent_received = \TimeDate::getInstance()->nowDb();
        $emailBean->save();

        return ['sent' => true, 'message' => 'Informationsmail wurde versendet und protokolliert.'];
    }

    private function assertAddressMayReceiveEmail(string $prospectId, string $email): void
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
            throw new \RuntimeException('Die E-Mail-Adresse ist abgemeldet oder als ungültig markiert.');
        }
    }
}
