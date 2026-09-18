<?php

// Isolierte Zählprüfung: keine echten Kontakte, Reservierungen oder Datenbankzugriffe.
define('sugarEntry', true);
require __DIR__ . '/../module/copy/custom/CRM/SpeedPhone/bootstrap.php';
use Anesda\CRM\SpeedPhone\{Config, QueueService, LockService, UserAccessService, AssignmentService, IndustryFilter};
class CountResult { public function __construct(public array $rows) {} }
class DBManager
{
    public array $queries = [];
    public string $role = 'internal';
    public array $rows = [];
    public function quote(string $value): string { return addslashes($value); }
    public function query(string $sql): CountResult {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) { throw new RuntimeException('Zählung darf nichts schreiben.'); }
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM crm_speedphone_user_settings')) { return new CountResult([['user_type'=>$this->role,'commission_percent'=>20,'can_receive_unassigned'=>1,'can_manage'=>0]]); }
        if (str_contains($sql, 'FROM prospect_lists')) { return new CountResult([['id'=>'existing-list']]); }
        if (str_contains($sql, 'FROM crm_speedphone_options')) { return new CountResult([]); }
        return new CountResult($this->rows);
    }
    public function fetchByAssoc(CountResult $result): array|false { return array_shift($result->rows) ?? false; }
}
class User {
    public string $id = 'existing-user';
    public bool $is_admin = false;
    public function getPreference($key, $category = '') { return 'craft'; }
}
function assertCount(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$class=new ReflectionClass(Config::class);$config=$class->newInstanceWithoutConstructor();
$defaults=require __DIR__.'/../module/copy/custom/CRM/SpeedPhone/config.php';
$class->getProperty('values')->setValue($config,array_replace($defaults,['source_list_name'=>'Test','exclude_patterns'=>['/ausschließen/iu'],'travel_filter_enabled'=>true,'travel_max_minutes'=>90,'travel_origin_label'=>'Testort']));
$db=new DBManager();$user=new User();
$db->rows=[
    ['id'=>'a','account_name'=>'Hotel Muster','speedphone_effective_industry'=>'hospitality'],
    ['id'=>'a','account_name'=>'Hotel Muster','speedphone_effective_industry'=>'hospitality'],
    ['id'=>'b','account_name'=>'Handwerk Muster','speedphone_effective_industry'=>'craft'],
    ['id'=>'c','account_name'=>'Muster GmbH','speedphone_effective_industry'=>'unknown'],
    ['id'=>'d','account_name'=>'Ausschließen GmbH','speedphone_effective_industry'=>'craft'],
    ['id'=>'e','account_name'=>'REWE Markt','speedphone_effective_industry'=>'retail'],
];
$access=new UserAccessService($db,$user);$assignments=new AssignmentService($config,$db,$user,$access);
$queue=new QueueService($config,$db,$user,new LockService($config,$db,$user),$access,$assignments);
$counts=$queue->getIndustryCounts();
assertCount($counts['']===3 && $counts['hospitality']===1 && $counts['craft']===1 && $counts['unknown']===1 && $counts['retail']===0,'UUID-Deduplizierung, Branchen oder Ausschlüsse werden falsch gezählt.');
assertCount(count($counts)===count(IndustryFilter::OPTIONS) && array_sum(array_slice($counts,1))===$counts[''],'Leere Kategorien oder Gesamtsumme fehlen.');
$sql=end($db->queries);
assertCount(!str_contains($sql,'FROM campaign_log') && !str_contains($sql,'speedphone_priority_tier'),'Die regelmäßige Zählung darf keine unnötige Kampagnenaggregation oder Prioritätsberechnung ausführen.');
foreach (["p.do_not_call=0", "p.deleted=0", "speedphone_next_call_c<=UTC_TIMESTAMP()", "'interested', 'no_interest', 'invalid_phone', 'blocked', 'paused'", 'BETWEEN 0 AND 90', "industry_lock.user_id<>'existing-user'", 'industry_lock.expires_at>UTC_TIMESTAMP()', "plp.prospect_list_id='existing-list'", "spa.owner_user_id='existing-user'"] as $fragment) {
    assertCount(str_contains($sql,$fragment),'Zählung ignoriert Auswahlregel: '.$fragment);
}
assertCount(!str_contains($sql,")='craft'"),'Der ausgewählte Branchenfilter darf die anderen Kategorien nicht leeren.');
$db->rows=[];assertCount(array_sum($queue->getIndustryCounts())===0,'Eine leere Warteschlange muss alle Kategorien mit null liefern.');
$db->role='external';$externalAccess=new UserAccessService($db,$user);
$externalQueue=new QueueService($config,$db,$user,new LockService($config,$db,$user),$externalAccess,new AssignmentService($config,$db,$user,$externalAccess));
$externalQueue->getIndustryCounts();$externalSql=end($db->queries);
assertCount(!str_contains(substr($externalSql,strrpos($externalSql,'WHERE p.deleted=0')),"spa.owner_type='disabled'"),'Externe dürfen keine interne Eskalationssicht bekommen.');
$db->role='disabled';$disabledAccess=new UserAccessService($db,$user);
$disabledQueue=new QueueService($config,$db,$user,new LockService($config,$db,$user),$disabledAccess,new AssignmentService($config,$db,$user,$disabledAccess));
try { $disabledQueue->getIndustryCounts(); throw new LogicException('Gesperrte Benutzer dürfen keine Zählung erhalten.'); } catch (RuntimeException $error) { assertCount(str_contains($error->getMessage(),'nicht freigeschaltet'),'Falscher Sperrfehler.'); }
echo "Branchenzähler: Rechte, Fälligkeit, Regionalfilter, Ausschlüsse und UUID-Deduplizierung erfolgreich geprüft.\n";
