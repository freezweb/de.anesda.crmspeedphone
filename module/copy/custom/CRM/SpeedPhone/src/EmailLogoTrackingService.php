<?php

namespace Anesda\CRM\SpeedPhone;

final class EmailLogoTrackingService
{
    public static function signature(string $secret, string $emailId, string $imageUrl): string
    {
        return hash_hmac('sha256', "speedphone-footer-logo\n" . $emailId . "\n" . $imageUrl, $secret);
    }

    public static function verify(string $secret, string $emailId, string $imageUrl, string $signature): bool
    {
        return preg_match('/^[0-9a-f-]{36}$/', $emailId) === 1
            && strlen($imageUrl) <= 2000 && filter_var($imageUrl, FILTER_VALIDATE_URL) !== false
            && str_starts_with($imageUrl, 'https://')
            && preg_match('/^[a-f0-9]{64}$/', $signature) === 1
            && hash_equals(self::signature($secret, $emailId, $imageUrl), $signature);
    }

    public static function instrument(string $html, string $emailId, string $baseUrl, string $secret): string
    {
        if (!str_starts_with($baseUrl, 'https://') || $secret === '') { return $html; }
        return preg_replace_callback('~<img\b[^>]*data-speedphone-footer-logo[^>]*>~i', static function (array $match) use ($emailId, $baseUrl, $secret): string {
            if (!preg_match('~\bsrc="([^"]+)"~i', $match[0], $source)) { return $match[0]; }
            $imageUrl = html_entity_decode($source[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $signature = self::signature($secret, $emailId, $imageUrl);
            if (!self::verify($secret, $emailId, $imageUrl, $signature)) { return $match[0]; }
            $url = rtrim($baseUrl, '/') . '/index.php?' . http_build_query([
                'entryPoint' => 'crmSpeedPhoneEmailLogo', 'm' => $emailId, 'u' => $imageUrl, 's' => $signature,
            ], '', '&', PHP_QUERY_RFC3986);
            return str_replace($source[0], 'src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $match[0]);
        }, $html) ?? $html;
    }
}
