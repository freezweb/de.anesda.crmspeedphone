<?php

use Anesda\CRM\SpeedPhone\IndustryFilter;

class User
{
    private array $preferences = [];
    public int $saves = 0;
    public function getPreference($name, $category = 'global') { return $this->preferences[$category][$name] ?? null; }
    public function setPreference($name, $value, $unused = 0, $category = 'global') { $this->preferences[$category][$name] = $value; }
    public function savePreferencesToDB() { $this->saves++; }
}

$firstUser = new User();
$secondUser = new User();
check(IndustryFilter::selected($firstUser) === '', 'Ohne Auswahl müssen alle Branchen verfügbar sein.');
IndustryFilter::save($firstUser, 'craft');
check(IndustryFilter::selected($firstUser) === 'craft', 'Persönlicher Branchenfilter wurde nicht gespeichert.');
check(IndustryFilter::selected($secondUser) === '', 'Branchenfilter darf andere Mitarbeiter nicht beeinflussen.');
IndustryFilter::save($firstUser, 'health');
check(IndustryFilter::selected($firstUser) === 'health', 'Branchenwechsel muss jederzeit möglich sein.');
IndustryFilter::save($firstUser, '');
check(IndustryFilter::selected($firstUser) === '' && $firstUser->saves === 3, 'Alle Branchen muss die persönliche Einschränkung aufheben.');
try {
    IndustryFilter::save($firstUser, "craft' OR 1=1 --");
    check(false, 'Fremde Filterwerte wurden angenommen.');
} catch (InvalidArgumentException) {
    check($firstUser->saves === 3, 'Ungültiger Filter darf nicht gespeichert werden.');
}
foreach ([
    ['Hotel Muster', '', 'hospitality'],
    ['Fahrschule Muster', '', 'automotive'],
    ['Tischlerei Muster', '', 'craft'],
    ['Maschinenbau Muster', '', 'manufacturing'],
    ['Pflegedienst Muster', '', 'health'],
    ['Spedition Muster', '', 'logistics'],
    ['Agrar Muster', '', 'agriculture'],
    ['Grundschule Muster', '', 'education'],
    ['Buchhandlung Muster', '', 'retail'],
    ['Steuerberatung Muster', '', 'services'],
    ['Muster GmbH', '', 'unknown'],
    ['Hotel Muster', 'retail', 'retail'],
    ['Hotel Muster', 'unknown', 'unknown'],
] as [$name, $manual, $expected]) {
    check(IndustryFilter::classify($name, $manual) === $expected, 'Branchenzuordnung falsch für Testbetrieb ' . $name);
}
check(IndustryFilter::allowedSql('', 'addslashes') === '1=1', 'Alle Branchen darf keine Kontakte ausschließen.');
