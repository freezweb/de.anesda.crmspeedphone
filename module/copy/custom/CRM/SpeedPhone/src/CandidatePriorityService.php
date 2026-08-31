<?php

namespace Anesda\CRM\SpeedPhone;

final class CandidatePriorityService
{
    public const TIER_MEDIUM = 10;
    public const TIER_STANDARD = 50;
    public const TIER_LATE = 90;
    public const TIER_EXCLUDED = 100;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{tier: int, base_score: int, label: string, excluded: bool}
     */
    public function classify(array $candidate): array
    {
        $name = $this->companyName($candidate);
        $text = trim($name . ' ' . (string) ($candidate['description'] ?? ''));

        if ($this->matches('central_retail_name_pattern', $name)) {
            return [
                'tier' => self::TIER_EXCLUDED,
                'base_score' => 0,
                'label' => 'Zentral versorgte Handelskette',
                'excluded' => true,
            ];
        }
        if ($this->matches('school_pattern', $text)) {
            return [
                'tier' => self::TIER_LATE,
                'base_score' => 0,
                'label' => 'Schule – erst nach dem Mittelstand',
                'excluded' => false,
            ];
        }
        if ($this->matches('medium_business_pattern', $text)) {
            return [
                'tier' => self::TIER_MEDIUM,
                'base_score' => 100,
                'label' => 'Mittelständischer Betrieb',
                'excluded' => false,
            ];
        }
        if ($this->matches('small_business_pattern', $text)) {
            return [
                'tier' => self::TIER_LATE,
                'base_score' => 0,
                'label' => 'Kleinstformat – erst nach dem Mittelstand',
                'excluded' => false,
            ];
        }

        return [
            'tier' => self::TIER_STANDARD,
            'base_score' => 50,
            'label' => 'Gewerblicher Betrieb',
            'excluded' => false,
        ];
    }

    public function isExcluded(array $candidate): bool
    {
        return $this->classify($candidate)['excluded'];
    }

    public function sqlTierExpression(string $nameExpression, string $textExpression, callable $quote): string
    {
        $central = $quote($this->pattern('central_retail_name_pattern'));
        $school = $quote($this->pattern('school_pattern'));
        $medium = $quote($this->pattern('medium_business_pattern'));
        $small = $quote($this->pattern('small_business_pattern'));

        return "CASE
                    WHEN {$nameExpression} REGEXP '{$central}' THEN " . self::TIER_EXCLUDED . "
                    WHEN {$textExpression} REGEXP '{$school}' THEN " . self::TIER_LATE . "
                    WHEN {$textExpression} REGEXP '{$medium}' THEN " . self::TIER_MEDIUM . "
                    WHEN {$textExpression} REGEXP '{$small}' THEN " . self::TIER_LATE . "
                    ELSE " . self::TIER_STANDARD . '
                END';
    }

    public function sqlAllowedCondition(string $nameExpression, callable $quote): string
    {
        $central = $quote($this->pattern('central_retail_name_pattern'));

        return "NOT ({$nameExpression} REGEXP '{$central}')";
    }

    private function companyName(array $candidate): string
    {
        $accountName = trim((string) ($candidate['account_name'] ?? ''));
        if ($accountName !== '') {
            return $accountName;
        }

        return trim(implode(' ', [
            (string) ($candidate['first_name'] ?? ''),
            (string) ($candidate['last_name'] ?? ''),
        ]));
    }

    private function matches(string $key, string $value): bool
    {
        return @preg_match('~' . $this->pattern($key) . '~iu', $value) === 1;
    }

    private function pattern(string $key): string
    {
        $pattern = trim((string) $this->config->get($key, ''));
        if ($pattern === '') {
            throw new \RuntimeException(sprintf('Die Prioritätsregel „%s“ fehlt.', $key));
        }

        return $pattern;
    }
}
