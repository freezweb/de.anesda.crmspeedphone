<?php

namespace Anesda\CRM\SpeedPhone;

final class ProductFlyerService
{
    private const MAX_TOTAL_BYTES = 10_485_760;

    private const CATALOG = [
        'profipos' => [
            'label' => 'ProfiPOS',
            'filename' => 'Anesda-Nord-ProfiPOS-Produktbroschuere.pdf',
            'description' => 'Kasse, Service, Küche und Geräte',
        ],
        'reservierfix' => [
            'label' => 'ReservierFix',
            'filename' => 'Anesda-Nord-ReservierFix-Produktbroschuere.pdf',
            'description' => 'Reservierung und Tischplanung',
        ],
        'transparent_laden' => [
            'label' => 'Transparent Laden',
            'filename' => 'Anesda-Nord-Transparent-Laden-Produktbroschuere.pdf',
            'description' => 'Ladepunkte, Tarife und Abrechnung',
        ],
        'druckfluss' => [
            'label' => 'Druckfluss',
            'filename' => 'Anesda-Nord-Druckfluss-Produktbroschuere.pdf',
            'description' => '3D-Druck-Auftrag und Produktionssteuerung',
        ],
        'produktionsbuddy' => [
            'label' => 'ProduktionsBuddy',
            'filename' => 'Anesda-Nord-ProduktionsBuddy-Produktbroschuere.pdf',
            'description' => 'Produktion, Lager und Offlinebuchungen',
        ],
        'kundenportal' => [
            'label' => 'Kundenportal',
            'filename' => 'Anesda-Nord-Kundenportal-Produktbroschuere.pdf',
            'description' => 'Verträge, Kontingente und Belege',
        ],
        'systemservice' => [
            'label' => 'SystemService vor Ort',
            'filename' => 'Anesda-Nord-SystemService-vor-Ort-Produktbroschuere.pdf',
            'description' => 'Systemwartung, Bestandsaufnahme und Störungsbehebung vor Ort',
        ],
        'individualentwicklung' => [
            'label' => 'Individuelle Software- und Hardwareentwicklung',
            'filename' => 'Anesda-Nord-Individuelle-Software-und-Hardwareentwicklung-Produktbroschuere.pdf',
            'description' => 'Passgenaue Software, Geräteanbindung und technische Prototypen',
        ],
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<array{key: string, label: string, filename: string, description: string}>
     */
    public function available(): array
    {
        $available = [];
        foreach (self::CATALOG as $key => $item) {
            $path = $this->safePath($item['filename']);
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $available[] = ['key' => $key, ...$item];
        }

        return $available;
    }

    /**
     * @param mixed $input
     * @return list<string>
     */
    public function validateSelection(mixed $input): array
    {
        $values = is_array($input) ? $input : ($input === null || $input === '' ? [] : [$input]);
        $keys = [];
        foreach ($values as $value) {
            $key = strtolower(trim((string) $value));
            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }
            if (!isset(self::CATALOG[$key])) {
                throw new \InvalidArgumentException('Es wurde ein unbekannter Produktflyer ausgewählt.');
            }
            $keys[] = $key;
        }
        if (count($keys) > count(self::CATALOG)) {
            throw new \InvalidArgumentException('Es wurden zu viele Produktflyer ausgewählt.');
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<array{key: string, label: string, filename: string, mime: string, content: string}>
     */
    public function loadSelected(array $keys): array
    {
        $keys = $this->validateSelection($keys);
        $attachments = [];
        $totalBytes = 0;
        foreach ($keys as $key) {
            $item = self::CATALOG[$key];
            $path = $this->safePath($item['filename']);
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException(sprintf('Der Produktflyer „%s“ ist nicht verfügbar.', $item['label']));
            }
            $size = filesize($path);
            if ($size === false || $size < 5 || $size > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException(sprintf('Der Produktflyer „%s“ hat eine ungültige Dateigröße.', $item['label']));
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('Die ausgewählten Produktflyer sind zusammen zu groß für den Versand.');
            }
            $content = file_get_contents($path);
            if (!is_string($content) || !str_starts_with($content, '%PDF-')) {
                throw new \RuntimeException(sprintf('Der Produktflyer „%s“ ist keine gültige PDF-Datei.', $item['label']));
            }
            $attachments[] = [
                'key' => $key,
                'label' => $item['label'],
                'filename' => $item['filename'],
                'mime' => 'application/pdf',
                'content' => $content,
            ];
        }

        return $attachments;
    }

    private function safePath(string $filename): string
    {
        if (basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.pdf')) {
            throw new \RuntimeException('Die Flyer-Konfiguration enthält einen unsicheren Dateinamen.');
        }

        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }
}
