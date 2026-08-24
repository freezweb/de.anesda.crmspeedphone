<?php

namespace Anesda\CRM\SpeedPhone;

final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $baseDirectory): self
    {
        $defaults = require $baseDirectory . '/config.php';
        $localFile = $baseDirectory . '/config.local.php';
        $local = is_file($localFile) ? require $localFile : [];
        $mailLocalFile = $baseDirectory . '/mail.local.php';
        $mailLocal = is_file($mailLocalFile) ? require $mailLocalFile : [];

        if (!is_array($defaults) || !is_array($local) || !is_array($mailLocal)) {
            throw new \RuntimeException('Die SpeedPhone-Konfiguration ist ungültig.');
        }

        return new self(array_replace_recursive($defaults, $local, $mailLocal));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function requireString(string $key): string
    {
        $value = trim((string) $this->get($key, ''));
        if ($value === '') {
            throw new \RuntimeException(sprintf('Die Konfiguration „%s“ fehlt.', $key));
        }

        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
