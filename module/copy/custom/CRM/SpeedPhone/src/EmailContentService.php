<?php

namespace Anesda\CRM\SpeedPhone;

final class EmailContentService
{
    public static function sanitizeHtml(string $html): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
            $allowed = ['div','p','br','span','strong','b','em','i','u','s','ul','ol','li','a','img','table','tbody','thead','tfoot','tr','td','th','h1','h2','h3','h4','blockquote','hr'];
            $blocked = ['script','style','iframe','object','embed','form','input','button','textarea','select','svg','math','link','meta','base'];
            $clean = function (\DOMNode $node) use (&$clean, $allowed, $blocked): void {
                foreach (iterator_to_array($node->childNodes) as $child) {
                    if ($child instanceof \DOMComment) {
                        $node->removeChild($child);
                        continue;
                    }
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $tag = strtolower($child->tagName);
                    if (in_array($tag, $blocked, true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    $clean($child);
                    if (!in_array($tag, $allowed, true)) {
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                        continue;
                    }
                    foreach (iterator_to_array($child->attributes) as $attribute) {
                        $name = strtolower($attribute->name);
                        $value = $attribute->value;
                        $keep = in_array($name, ['alt','title','align','valign','role','data-speedphone-footer-logo'], true);
                        if (in_array($name, ['width','height','cellpadding','cellspacing','border','colspan','rowspan'], true)) {
                            $keep = preg_match('/^\d{1,4}%?$/', $value) === 1;
                        }
                        if ($name === 'href') {
                            $keep = $tag === 'a' && self::safeLink($value);
                        }
                        if ($name === 'src') {
                            $keep = $tag === 'img' && str_starts_with($value, 'https://') && self::safeLink($value);
                        }
                        if ($name === 'style') {
                            $styles = [];
                            foreach (explode(';', $value) as $declaration) {
                                $parts = explode(':', $declaration, 2);
                                if (count($parts) !== 2) { continue; }
                                [$property, $setting] = array_map('trim', $parts);
                                if (preg_match('/^(?:color|background(?:-color)?|font-(?:family|size|weight|style)|line-height|text-(?:align|decoration|transform)|letter-spacing|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|border(?:-(?:top|right|bottom|left|radius|collapse))?|(?:max-|min-)?(?:width|height)|display|overflow)$/i', $property)
                                    && preg_match('/^[a-zA-Z0-9#.,%\s()\x27"+\/-]+$/', preg_replace('/\s*!important\s*$/i', '', $setting) ?? $setting)
                                    && !preg_match('/url|expression|javascript|behavior|binding/i', $setting)) {
                                    $styles[] = $property . ':' . $setting;
                                }
                            }
                            $child->setAttribute('style', implode(';', $styles));
                            $keep = true;
                        }
                        if (!$keep) { $child->removeAttribute($name); }
                    }
                }
            };
            $body = $document->getElementsByTagName('body')->item(0);
            if (!$body) { return ''; }
            $clean($body);
            $result = '';
            foreach ($body->childNodes as $child) { $result .= $document->saveHTML($child); }
            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function htmlToPlain(string $html): string
    {
        $html = self::sanitizeHtml($html);
        $html = preg_replace_callback('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~is', static fn (array $match): string =>
            strip_tags($match[2]) . ' (' . $match[1] . ')', $html) ?? $html;
        $html = preg_replace('~<(?:br\b[^>]*|/p|/div|/li|/tr|/h[1-4])>~i', "\n", $html) ?? $html;
        return trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/', "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeLink(string $url): bool
    {
        return preg_match('~^(?:https?://[^\s<>]+|mailto:[^\s<>]+|tel:\+?[0-9 ()./-]+)$~i', $url) === 1;
    }

    public static function editableTextToHtml(string $text, string $templateHtml = ''): string
    {
        // Nur Link-Markierungen aus dem Texteditor interpretieren, niemals eingegebenes HTML.
        $pattern = '~\[([^\]\r\n]+)\]\(([^\s<>]+)\)~u';
        $offset = 0;
        $html = '';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $html .= self::escape(substr($text, $offset, $match[0][1] - $offset));
            $label = $match[1][0];
            $url = $match[2][0];
            $html .= self::safeLink($url)
                ? '<a href="' . self::escape($url) . '" style="color:#087ea4;text-decoration:underline">'
                    . self::escape($label) . '</a>'
                : self::escape($match[0][0]);
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        $html .= self::escape(substr($text, $offset));

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;'
            . 'color:#17202a;max-width:720px;margin:0 auto;padding:24px">'
            . nl2br($html, false) . self::footerLogoFromHtml($templateHtml) . '</div>';
    }

    public static function editableTextToPlain(string $text): string
    {
        return preg_replace_callback('~\[([^\]\r\n]+)\]\(([^\s<>]+)\)~u', static function (array $match): string {
            return self::safeLink($match[2]) ? $match[1] . ' (' . $match[2] . ')' : $match[0];
        }, $text) ?? $text;
    }

    public static function footerLogoFromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $logo = $xpath->query('//img[@data-speedphone-footer-logo]')->item(0);
            if (!$logo instanceof \DOMElement) {
                return '';
            }
            $url = $logo->getAttribute('src');
            if (!str_starts_with($url, 'https://') || !self::safeLink($url)) {
                return '';
            }

            return '<div style="margin-top:24px;padding-top:20px;border-top:1px solid #d8e1e6">'
                . '<img data-speedphone-footer-logo="1" src="' . self::escape($url) . '" alt="' . self::escape($logo->getAttribute('alt'))
                . '" width="190" style="display:block;max-width:190px;height:auto;background-color:#ffffff !important;border-radius:8px;padding:8px" /></div>';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function replaceVariables(string $text, array $values, bool $html = false): string
    {
        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements[$key] = $html ? self::escape((string) $value) : (string) $value;
        }
        return strtr($text, $replacements);
    }
}
