<?php

// Isolierter Ende-zu-Ende-Test von Entwurf, Bearbeitung, Versandübergabe und CRM-Protokoll.
define('sugarEntry', true);
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/bootstrap.php';
use Anesda\CRM\SpeedPhone\{Config, EmailService, EmailTemplateBrandService, EmailContentService, ProductFlyerService};

class DBManager
{
    public function quote($value) { return addslashes($value); }
    public function query($sql) { return $sql; }
    public function fetchByAssoc($sql) { return str_contains($sql, 'FROM email_templates') ? ['id'=>'template-test'] : ['opt_out'=>0, 'invalid_email'=>0]; }
}
class User { public string $id = 'befc6200-da8e-47a5-9fc8-3b30e8451018'; }
class Prospect
{
    public string $id = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public string $first_name = 'Antonia';
    public string $last_name = 'Testkontakt';
    public string $account_name = 'Test & <Nord>';
    public object $emailAddress;
    public function __construct() { $this->emailAddress = new class { public function getPrimaryAddress($bean) { return 'test@example.org'; } }; }
}
#[AllowDynamicProperties]
class TestEmailBean
{
    public string $id = '';
    public bool $new_with_id = false;
    public static array $saved = [];
    public function getSystemDefaultEmail() { return ['email'=>'sales@example.org', 'name'=>'Vertrieb']; }
    public function save() { self::$saved[] = clone $this; }
}
class BeanFactory
{
    public static function newBean($module) { return new TestEmailBean(); }
    public static function getBean($module, $id) {
        $template = EmailTemplateBrandService::informationTemplate();
        $template['subject'] = 'Hallo $contact_first_name';
        $template['body_html'] = str_replace('Sehr geehrte Damen und Herren,', 'Hallo $contact_first_name von $account_name,', $template['body_html']);
        return (object) (['id'=>'template-test'] + $template);
    }
}
class TimeDate { public static function getInstance() { return new self(); } public function nowDb() { return '2026-09-18 12:00:00'; } }
function from_html($value) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function create_guid() { return 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'; }
function assertMail($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$class = new ReflectionClass(Config::class);
$config = $class->newInstanceWithoutConstructor();
$class->getProperty('values')->setValue($config, ['email_sending_enabled'=>true,'email_template_name'=>'Test','email_logo_tracking_enabled'=>true,'mail_webhook_secret'=>'synthetischer-testschlüssel']);
$GLOBALS['sugar_config']['site_url'] = 'https://crm.example.org';
$service = new EmailService($config, new DBManager(), new User(), new ProductFlyerService(__DIR__.'/../module/copy/custom/CRM/SpeedPhone/assets/flyers'));
$prospect = new Prospect();
$draft = $service->previewRequestedInformation($prospect, ['kundenportal']);
assertMail(str_contains($draft['body_html'], 'Hallo Antonia von Test &amp; &lt;Nord&gt;'), 'Platzhalter im Entwurf fehlen oder sind ungeschützt.');
assertMail(str_contains($draft['body_html'], 'data-speedphone-footer-logo'), 'Das Logo fehlt im HTML-Entwurf.');
assertMail(!str_contains($draft['body_html'], 'crmSpeedPhoneEmailLogo'), 'Der interne Entwurf darf kein Öffnungstracking auslösen.');
$edited = str_replace('Hallo Antonia', '<strong>Vielen Dank für das nette Gespräch</strong>, Antonia', $draft['body_html']);
chdir(__DIR__.'/fixtures');
$service->sendRequestedInformation($prospect, true, ['kundenportal'], 'Danke $contact_first_name', null, $edited);
$mail = SugarPHPMailer::$messages[0];
$log = TestEmailBean::$saved[0];
assertMail($mail->Subject === 'Danke Antonia', 'Der bearbeitete Betreff löst Platzhalter nicht auf.');
assertMail(str_contains($mail->Body, '<strong>Vielen Dank für das nette Gespräch</strong>'), 'Die HTML-Bearbeitung kommt nicht beim Transport an.');
assertMail(str_contains($mail->Body, 'href="https://anesda-nord.de/kontakt"') && !str_contains($mail->Body, '[Unverbindlich'), 'Die Mail enthält wieder Link-Markierungen.');
assertMail(str_contains($mail->Body, 'entryPoint=crmSpeedPhoneEmailLogo'), 'PDF-Mails erhalten keine Logo-Erfassung.');
assertMail(str_contains($mail->AltBody, 'Unverbindlich Kontakt aufnehmen (https://anesda-nord.de/kontakt)'), 'Textalternative fehlt beim Versand.');
assertMail(count($mail->attachments) === 1, 'Der Produktflyer geht beim HTML-Versand verloren.');
assertMail($log->description_html === $mail->Body && str_contains($log->description, 'SpeedPhone-Mail-ID: '.$log->id), 'CRM-Protokoll und übergebene HTML-Mail unterscheiden sich.');
assertMail($log->parent_id === $prospect->id && $log->new_with_id, 'Die Referenz auf den vorhandenen Kontakt oder die Mail-ID fehlt.');
$service->sendRequestedInformation($prospect, true, [], null, '[Kontakt](https://anesda-nord.de/kontakt)');
assertMail(str_contains(SugarPHPMailer::$messages[1]->Body, '<a href="https://anesda-nord.de/kontakt"'), 'Alte Texteditoren sind nicht mehr kompatibel.');
echo "HTML-Mailübergabe, PDF-Anhang, Textalternative, Tracking und CRM-Protokoll erfolgreich geprüft.\n";
