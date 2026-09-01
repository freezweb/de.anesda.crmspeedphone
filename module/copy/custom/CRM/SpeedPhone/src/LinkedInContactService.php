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
    public function discover(
        string $prospectId,
        string $company,
        string $city = '',
        string $website = ''
    ): array
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

        $query = self::buildDiscoveryQuery($company, $city);
        $limit = max(1, min(10, (int) $this->config->get('linkedin_discovery_max_results', 5)));
        $contacts = [];
        $errors = [];
        $completedSource = false;
        try {
            try {
                $html = $this->fetchSearchHtml($query);
                $contacts = self::parseSearchHtml($html, $company, $city, $limit);
                $completedSource = true;
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }

            if (count($contacts) < $limit && trim($website) !== '') {
                try {
                    $websiteContacts = $this->discoverFromCompanyWebsite(
                        $website,
                        $company,
                        $limit - count($contacts)
                    );
                    $contacts = self::mergeContacts($contacts, $websiteContacts, $limit);
                    $completedSource = true;
                } catch (\Throwable $error) {
                    $errors[] = $error->getMessage();
                }
            }

            if (!$completedSource) {
                throw new \RuntimeException(implode(' ', array_filter($errors)) ?: 'Keine Suchquelle erreichbar.');
            }
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

    /**
     * Liest Ansprechpartner ausschließlich von der angegebenen öffentlichen
     * Firmenwebsite. Direkte LinkedIn-Profile werden bevorzugt. Wird dort nur
     * die Geschäftsführung genannt, entsteht ein präziser LinkedIn-Suchlink.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseCompanyWebsiteHtml(
        string $html,
        string $company,
        string $pageUrl,
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

        $contacts = [];
        $seen = [];
        $xpath = new \DOMXPath($document);
        $anchors = $xpath->query("//a[contains(translate(@href, 'LINKEDIN', 'linkedin'), 'linkedin.com/in/')]");
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (!$anchor instanceof \DOMElement) {
                    continue;
                }
                $profileUrl = self::extractLinkedInProfileUrl($anchor->getAttribute('href'));
                if ($profileUrl === '' || isset($seen[$profileUrl])) {
                    continue;
                }
                $contextNode = $anchor->parentNode?->parentNode ?? $anchor->parentNode ?? $anchor;
                $context = self::cleanText($contextNode->textContent ?? '');
                $personName = self::extractPersonName(self::cleanText($anchor->textContent));
                if ($personName === '') {
                    $personName = self::extractPersonName($context);
                }
                if ($personName === '') {
                    continue;
                }
                $role = self::extractRole($context);
                $seen[$profileUrl] = true;
                $seen[self::normalize($personName)] = true;
                $contacts[] = [
                    'person_name' => $personName,
                    'role' => $role !== '' ? $role : 'Funktion im LinkedIn-Profil prüfen',
                    'company_name' => $company,
                    'profile_url' => $profileUrl,
                    'confidence' => $role !== '' ? 90 : 80,
                ];
                if (count($contacts) >= $limit) {
                    return $contacts;
                }
            }
        }

        $blocks = $xpath->query('//p | //li | //address | //td | //div[string-length(normalize-space(.)) < 500]');
        if ($blocks === false) {
            return $contacts;
        }
        foreach ($blocks as $block) {
            $text = self::cleanText($block->textContent ?? '');
            if (
                $text === ''
                || preg_match('/geschäftsführ|geschäftsleitung|inhaber|vertreten durch|vorstand/iu', $text) !== 1
            ) {
                continue;
            }
            $role = self::extractRole($text);
            foreach (self::extractDecisionMakerNames($text) as $personName) {
                $key = self::normalize($personName);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $contacts[] = [
                    'person_name' => $personName,
                    'role' => $role !== '' ? $role : 'Geschäftsleitung laut Firmenwebsite',
                    'company_name' => $company,
                    'profile_url' => self::buildLinkedInPeopleSearchUrl($personName . ' ' . $company),
                    'confidence' => 65,
                    'evidence_url' => $pageUrl,
                ];
                if (count($contacts) >= $limit) {
                    return $contacts;
                }
            }
        }

        return $contacts;
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

    /** @return array<int, array<string, mixed>> */
    private function discoverFromCompanyWebsite(string $website, string $company, int $limit): array
    {
        $website = $this->normalizePublicWebsiteUrl($website);
        $homepage = $this->fetchPublicWebsiteUrl($website);
        $contacts = self::parseCompanyWebsiteHtml($homepage, $company, $website, $limit);
        if (count($contacts) >= $limit) {
            return $contacts;
        }

        foreach (self::extractRelevantWebsiteLinks($homepage, $website) as $pageUrl) {
            try {
                $html = $this->fetchPublicWebsiteUrl($pageUrl);
                $contacts = self::mergeContacts(
                    $contacts,
                    self::parseCompanyWebsiteHtml($html, $company, $pageUrl, $limit),
                    $limit
                );
            } catch (\Throwable) {
                continue;
            }
            if (count($contacts) >= $limit) {
                break;
            }
        }

        return $contacts;
    }

    /** @return array<int, array<string, mixed>> */
    private static function mergeContacts(array $first, array $second, int $limit): array
    {
        $merged = [];
        $seen = [];
        foreach (array_merge($first, $second) as $contact) {
            $key = self::normalize((string) ($contact['person_name'] ?? ''));
            $profile = strtolower((string) ($contact['profile_url'] ?? ''));
            if ($key === '' || isset($seen[$key]) || ($profile !== '' && isset($seen[$profile]))) {
                continue;
            }
            $seen[$key] = true;
            if ($profile !== '') {
                $seen[$profile] = true;
            }
            $merged[] = $contact;
            if (count($merged) >= $limit) {
                break;
            }
        }

        usort($merged, static fn (array $a, array $b): int => (int) $b['confidence'] <=> (int) $a['confidence']);

        return $merged;
    }

    /** @return array<int, string> */
    private static function extractRelevantWebsiteLinks(string $html, string $baseUrl): array
    {
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

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            return [];
        }
        $baseHost = strtolower((string) $base['host']);
        $baseScheme = (string) ($base['scheme'] ?? 'https');
        $links = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = html_entity_decode(trim($anchor->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = self::normalize($anchor->textContent . ' ' . $href);
            if (preg_match('/impressum|imprint|ueber uns|uber uns|about|team|mitarbeiter|ansprechpartner/u', $label) !== 1) {
                continue;
            }
            if (str_starts_with($href, '/')) {
                $href = $baseScheme . '://' . $baseHost . $href;
            } elseif (!preg_match('#^https?://#i', $href)) {
                $directory = rtrim(str_replace('\\', '/', dirname((string) ($base['path'] ?? '/'))), '/');
                $href = $baseScheme . '://' . $baseHost . ($directory !== '' ? $directory : '') . '/' . ltrim($href, '/');
            }
            $parts = parse_url($href);
            if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== $baseHost) {
                continue;
            }
            $clean = ($parts['scheme'] ?? $baseScheme) . '://' . $baseHost . ($parts['path'] ?? '/');
            if (!isset($links[$clean])) {
                $links[$clean] = true;
            }
            if (count($links) >= 3) {
                break;
            }
        }

        return array_keys($links);
    }

    private function normalizePublicWebsiteUrl(string $website): string
    {
        $website = trim($website);
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        $parts = parse_url($website);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new \RuntimeException('Die Firmenwebsite besitzt keine gültige öffentliche Adresse.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || !self::hostResolvesPublicly($host)) {
            throw new \RuntimeException('Die Firmenwebsite zeigt nicht auf ein öffentliches Ziel.');
        }

        return $website;
    }

    private static function hostResolvesPublicly(string $host): bool
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }

        return true;
    }

    private function fetchPublicWebsiteUrl(string $url): string
    {
        $timeout = max(2, min(6, (int) $this->config->get('linkedin_website_timeout_seconds', 4)));
        for ($redirects = 0; $redirects <= 2; ++$redirects) {
            $url = $this->normalizePublicWebsiteUrl($url);
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException('Die Firmenwebsite konnte nicht initialisiert werden.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CRM-SpeedPhone/1.10; +https://anesda-nord.de)',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
                CURLOPT_HEADER => true,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            curl_close($handle);
            if (!is_string($response)) {
                throw new \RuntimeException('Die Firmenwebsite ist vorübergehend nicht erreichbar.');
            }
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(.+)$/mi', $headers, $match) === 1) {
                $location = trim($match[1]);
                if (str_starts_with($location, '/')) {
                    $parts = parse_url($url);
                    $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
                }
                $url = $location;
                continue;
            }
            if ($status !== 200 || $body === '' || strlen($body) > 2_000_000) {
                throw new \RuntimeException('Die Firmenwebsite liefert derzeit keine auswertbare Seite.');
            }

            return $body;
        }

        throw new \RuntimeException('Die Firmenwebsite leitet zu oft weiter.');
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

    private static function extractPersonName(string $text): string
    {
        $text = preg_replace('/\b(linkedin|profil|profile|kontakt|mehr erfahren)\b/iu', ' ', $text) ?? $text;
        $text = self::cleanText($text);
        if (preg_match(
            '/\b((?:(?:Prof\.|Dr\.)\s+){0,2}[\p{Lu}][\p{L}\'’.-]+(?:\s+[\p{Lu}][\p{L}\'’.-]+){1,3})\b/u',
            $text,
            $match
        ) !== 1) {
            return '';
        }
        $name = self::cleanText($match[1]);
        if (preg_match('/^(LinkedIn|Geschäftsführung|Geschäftsführer|Inhaber|Kontakt)/iu', $name) === 1) {
            return '';
        }

        return $name;
    }

    /** @return array<int, string> */
    private static function extractDecisionMakerNames(string $text): array
    {
        $names = [];
        $rolePattern = '(?:Geschäftsführer(?:in|innen)?|Geschäftsführung|Geschäftsleitung|Inhaber(?:in)?|Vorstand|Vertreten\s+durch)';
        $namePattern = '((?:(?:Prof\.|Dr\.)\s+){0,2}[\p{Lu}][\p{L}\'’.-]+(?:\s+[\p{Lu}][\p{L}\'’.-]+){1,3})';
        preg_match_all(
            '/' . $rolePattern
            . '(?:\s+(?:ist|sind|wird\s+vertreten\s+durch|den\s+Geschäftsführer|die\s+Geschäftsführerin))?'
            . '\s*[:\-–,]?\s*(?:Herrn?|Frau)?\s*' . $namePattern . '/u',
            $text,
            $matches
        );
        foreach ((array) ($matches[1] ?? []) as $name) {
            $name = self::cleanText((string) $name);
            if (
                $name !== ''
                && preg_match('/^(Geschäftsführer|Geschäftsführung|Geschäftsleitung|Vertreten|Inhaber)/iu', $name) !== 1
            ) {
                $names[self::normalize($name)] = $name;
            }
        }

        return array_values($names);
    }

    private static function extractRole(string $text): string
    {
        $roles = [
            '/geschäftsführerin/iu' => 'Geschäftsführerin',
            '/geschäftsführer/iu' => 'Geschäftsführer',
            '/geschäftsleitung/iu' => 'Geschäftsleitung',
            '/inhaberin/iu' => 'Inhaberin',
            '/inhaber/iu' => 'Inhaber',
            '/\bvorstand\b/iu' => 'Vorstand',
            '/it[- ]?leiterin/iu' => 'IT-Leiterin',
            '/it[- ]?leiter/iu' => 'IT-Leiter',
            '/betriebsleiterin/iu' => 'Betriebsleiterin',
            '/betriebsleiter/iu' => 'Betriebsleiter',
        ];
        foreach ($roles as $pattern => $label) {
            if (preg_match($pattern, $text) === 1) {
                return $label . ' laut Firmenwebsite';
            }
        }

        return '';
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
