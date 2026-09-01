<?php

namespace Anesda\CRM\SpeedPhone;

final class LinkedInContactService
{
    public function __construct(
        private readonly Config $config,
        private readonly \DBManager $db
    ) {
    }

    /**
     * @return array{
     *   contacts: array<int, array<string, mixed>>,
     *   search_url: string,
     *   status: string,
     *   searched_at: string
     * }
     */
    public function discover(string $prospectId, string $company, string $city = ''): array
    {
        $company = trim($company);
        $city = trim($city);
        $searchUrl = self::buildLinkedInPeopleSearchUrl($company, $city);
        if ($company === '' || !(bool) $this->config->get('linkedin_discovery_enabled', true)) {
            return [
                'contacts' => [],
                'search_url' => $searchUrl,
                'status' => 'disabled',
                'searched_at' => '',
            ];
        }

        $cached = $this->loadSearch($prospectId);
        $contacts = $this->loadContacts($prospectId);
        if ($this->isFresh($cached)) {
            return [
                'contacts' => $contacts,
                'search_url' => $searchUrl,
                'status' => (string) ($cached['status'] ?? 'cached'),
                'searched_at' => (string) ($cached['searched_at'] ?? ''),
            ];
        }

        try {
            $query = self::buildDiscoveryQuery($company, $city);
            $html = $this->fetchSearchHtml($query);
            $contacts = self::parseSearchHtml(
                $html,
                $company,
                $city,
                max(1, min(10, (int) $this->config->get('linkedin_discovery_max_results', 5)))
            );
            $this->storeSuccessfulSearch($prospectId, $query, $contacts);

            return [
                'contacts' => $contacts,
                'search_url' => $searchUrl,
                'status' => $contacts === [] ? 'no_results' : 'found',
                'searched_at' => gmdate('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $error) {
            $this->storeFailedSearch($prospectId, $error->getMessage());

            return [
                'contacts' => $contacts,
                'search_url' => $searchUrl,
                'status' => $contacts === [] ? 'error' : 'stale',
                'searched_at' => (string) ($cached['searched_at'] ?? ''),
            ];
        }
    }

    public static function buildLinkedInPeopleSearchUrl(string $company, string $city = ''): string
    {
        $keywords = trim(implode(' ', array_filter([$company, $city])));

        return 'https://www.linkedin.com/search/results/people/?keywords=' . rawurlencode($keywords);
    }

    public static function buildDiscoveryQuery(string $company, string $city = ''): string
    {
        $parts = [
            'site:linkedin.com/in/',
            '"' . str_replace('"', '', $company) . '"',
            '(Geschäftsführer OR Geschäftsführerin OR Inhaber OR Inhaberin OR IT-Leiter OR CIO OR CTO OR Betriebsleiter)',
        ];
        if ($city !== '') {
            $parts[] = '"' . str_replace('"', '', $city) . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, array{
     *   person_name: string,
     *   role: string,
     *   company_name: string,
     *   profile_url: string,
     *   confidence: int
     * }>
     */
    public static function parseSearchHtml(
        string $html,
        string $company,
        string $city = '',
        int $limit = 5
    ): array {
        if ($html === '' || strlen($html) > 2_000_000) {
            return [];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            )) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $anchors = $xpath->query(
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')] | //a[.//h3]"
        );
        if ($anchors === false) {
            return [];
        }

        $contacts = [];
        $seen = [];
        foreach ($anchors as $anchor) {
            if (!$anchor instanceof \DOMElement) {
                continue;
            }
            $profileUrl = self::extractLinkedInProfileUrl($anchor->getAttribute('href'));
            if ($profileUrl === '' || isset($seen[$profileUrl])) {
                continue;
            }

            $title = self::cleanText($anchor->textContent);
            $contextNode = $anchor->parentNode?->parentNode ?? $anchor->parentNode ?? $anchor;
            $context = self::cleanText($contextNode->textContent ?? $title);
            if (!self::matchesCompany($company, $context)) {
                continue;
            }

            [$personName, $role] = self::splitProfileTitle($title);
            if ($personName === '') {
                continue;
            }

            $confidence = 60;
            if (preg_match('/geschäftsführ|inhaber|it[- ]?leiter|\\bcio\\b|\\bcto\\b|betriebsleiter|prokurist|geschäftsleitung/iu', $context) === 1) {
                $confidence += 30;
            }
            if ($city !== '' && self::normalizedContains($context, $city)) {
                $confidence += 10;
            }

            $seen[$profileUrl] = true;
            $contacts[] = [
                'person_name' => $personName,
                'role' => $role !== '' ? $role : 'Funktion im LinkedIn-Profil prüfen',
                'company_name' => $company,
                'profile_url' => $profileUrl,
                'confidence' => min(100, $confidence),
            ];
            if (count($contacts) >= $limit) {
                break;
            }
        }

        usort(
            $contacts,
            static fn (array $left, array $right): int => $right['confidence'] <=> $left['confidence']
        );

        return $contacts;
    }

    private function fetchSearchHtml(string $query): string
    {
        $endpoint = trim((string) $this->config->get(
            'linkedin_discovery_search_endpoint',
            'https://html.duckduckgo.com/html/'
        ));
        $endpointParts = parse_url($endpoint);
        $allowedHosts = ['html.duckduckgo.com', 'www.google.com'];
        if (
            !is_array($endpointParts)
            || ($endpointParts['scheme'] ?? '') !== 'https'
            || !in_array((string) ($endpointParts['host'] ?? ''), $allowedHosts, true)
        ) {
            throw new \RuntimeException('Die LinkedIn-Suchquelle ist nicht freigegeben.');
        }

        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'q=' . rawurlencode($query);
        if (($endpointParts['host'] ?? '') === 'www.google.com') {
            $url .= '&num=10&hl=de&filter=0';
        }
        $timeout = max(2, min(10, (int) $this->config->get('linkedin_discovery_timeout_seconds', 5)));
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Die öffentliche Profilsuche konnte nicht initialisiert werden.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CRM-SpeedPhone/1.9; +https://anesda-nord.de)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException(
                'Die öffentliche Profilsuche ist vorübergehend nicht erreichbar.'
                . ($error !== '' ? ' ' . $error : '')
            );
        }

        return $body;
    }

    private function isFresh(?array $search): bool
    {
        if ($search === null || empty($search['searched_at'])) {
            return false;
        }
        $days = (string) ($search['status'] ?? '') === 'error'
            ? 1
            : max(1, min(90, (int) $this->config->get('linkedin_discovery_cache_days', 30)));
        $timestamp = strtotime((string) $search['searched_at'] . ' UTC');

        return $timestamp !== false && $timestamp >= time() - ($days * 86400);
    }

    private function loadSearch(string $prospectId): ?array
    {
        $row = $this->db->fetchByAssoc($this->db->query(
            "SELECT status, searched_at, result_count
             FROM crm_speedphone_linkedin_searches
             WHERE prospect_id='" . $this->db->quote($prospectId) . "'
             LIMIT 1"
        ));

        return is_array($row) ? $row : null;
    }

    private function loadContacts(string $prospectId): array
    {
        $result = $this->db->query(
            "SELECT person_name, role_name role, company_name, profile_url, confidence, last_verified_at
             FROM crm_speedphone_linkedin_contacts
             WHERE prospect_id='" . $this->db->quote($prospectId) . "'
             ORDER BY confidence DESC, person_name
             LIMIT 10"
        );
        $contacts = [];
        while ($row = $this->db->fetchByAssoc($result)) {
            $contacts[] = $row;
        }

        return $contacts;
    }

    private function storeSuccessfulSearch(string $prospectId, string $query, array $contacts): void
    {
        $quotedProspectId = $this->db->quote($prospectId);
        $this->db->query('START TRANSACTION');
        try {
            $this->db->query(
                "DELETE FROM crm_speedphone_linkedin_contacts
                 WHERE prospect_id='{$quotedProspectId}'"
            );
            foreach ($contacts as $contact) {
                $id = function_exists('create_guid') ? create_guid() : self::createGuid();
                $this->db->query(
                    "INSERT INTO crm_speedphone_linkedin_contacts
                     (id, prospect_id, person_name, role_name, company_name, profile_url,
                      confidence, found_at, last_verified_at)
                     VALUES (
                        '" . $this->db->quote($id) . "',
                        '{$quotedProspectId}',
                        '" . $this->db->quote((string) $contact['person_name']) . "',
                        '" . $this->db->quote((string) $contact['role']) . "',
                        '" . $this->db->quote((string) $contact['company_name']) . "',
                        '" . $this->db->quote((string) $contact['profile_url']) . "',
                        " . (int) $contact['confidence'] . ",
                        UTC_TIMESTAMP(), UTC_TIMESTAMP()
                     )"
                );
            }
            $status = $contacts === [] ? 'no_results' : 'found';
            $this->db->query(
                "INSERT INTO crm_speedphone_linkedin_searches
                 (prospect_id, status, search_query, result_count, searched_at, last_error)
                 VALUES (
                    '{$quotedProspectId}',
                    '" . $this->db->quote($status) . "',
                    '" . $this->db->quote($query) . "',
                    " . count($contacts) . ",
                    UTC_TIMESTAMP(), NULL
                 )
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    search_query=VALUES(search_query),
                    result_count=VALUES(result_count),
                    searched_at=VALUES(searched_at),
                    last_error=NULL"
            );
            $this->db->query('COMMIT');
        } catch (\Throwable $error) {
            $this->db->query('ROLLBACK');
            throw $error;
        }
    }

    private function storeFailedSearch(string $prospectId, string $error): void
    {
        $this->db->query(
            "INSERT INTO crm_speedphone_linkedin_searches
             (prospect_id, status, search_query, result_count, searched_at, last_error)
             VALUES (
                '" . $this->db->quote($prospectId) . "',
                'error', '', 0, UTC_TIMESTAMP(),
                '" . $this->db->quote(mb_substr($error, 0, 1000)) . "'
             )
             ON DUPLICATE KEY UPDATE
                status='error',
                searched_at=VALUES(searched_at),
                last_error=VALUES(last_error)"
        );
    }

    private static function extractLinkedInProfileUrl(string $href): string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (str_starts_with($href, '/url?')) {
            $href = 'https://www.google.com' . $href;
        }
        $parts = parse_url($href);
        if (is_array($parts) && str_ends_with((string) ($parts['host'] ?? ''), 'duckduckgo.com')) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $href = (string) ($query['uddg'] ?? '');
        }
        if (
            is_array($parts)
            && str_ends_with((string) ($parts['host'] ?? ''), 'google.com')
            && ($parts['path'] ?? '') === '/url'
        ) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $href = (string) ($query['q'] ?? '');
        }

        $parts = parse_url($href);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (
            ($parts['scheme'] ?? '') !== 'https'
            || !($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com'))
            || !str_starts_with($path, '/in/')
        ) {
            return '';
        }

        return 'https://' . $host . rtrim($path, '/') . '/';
    }

    /** @return array{0: string, 1: string} */
    private static function splitProfileTitle(string $title): array
    {
        $title = preg_replace('/[|·]\\s*LinkedIn.*$/iu', '', $title) ?? $title;
        $parts = preg_split('/\\s+[–—-]\\s+/u', $title, 2);

        return [
            self::cleanText((string) ($parts[0] ?? '')),
            self::cleanText((string) ($parts[1] ?? '')),
        ];
    }

    private static function matchesCompany(string $company, string $context): bool
    {
        $tokens = preg_split('/\\s+/u', self::normalize($company), -1, PREG_SPLIT_NO_EMPTY);
        $ignored = [
            'gmbh', 'mbh', 'haftungsbeschränkt', 'haftungsbeschraenkt', 'gesellschaft',
            'ug', 'kg', 'ohg', 'ag', 'co', 'und', 'deutschland', 'gruppe', 'group',
            'nord', 'sued', 'service', 'services', 'technik', 'hotel', 'restaurant',
            'praxis', 'zentrum',
        ];
        $tokens = array_values(array_filter(
            $tokens ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 4 && !in_array($token, $ignored, true)
        ));
        if ($tokens === []) {
            return false;
        }
        $normalizedContext = self::normalize($context);

        foreach ($tokens as $token) {
            if (str_contains($normalizedContext, $token)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizedContains(string $haystack, string $needle): bool
    {
        return str_contains(self::normalize($haystack), self::normalize($needle));
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(self::cleanText($value), 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '');
    }

    private static function cleanText(string $value): string
    {
        return trim(preg_replace('/\\s+/u', ' ', html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )) ?? '');
    }

    private static function createGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
