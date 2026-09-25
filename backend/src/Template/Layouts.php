<?php

namespace App\Template;

/**
 * Puts a template's content in a layout's {{ content }} slot.
 */
final class Layouts
{
    public const SLOT = 'content';
    private const SLOT_PATTERN = '/\{\{\s*content\s*\}\}/';

    public static function hasSlot(string $layoutHtml): bool
    {
        return 1 === preg_match(self::SLOT_PATTERN, $layoutHtml);
    }

    /**
     * The layout with the content in its slot. A full document as content (GrapesJS exports <body>…</body>)
     * only brings its body: the layout provides the document.
     */
    public static function wrap(string $layoutHtml, string $contentHtml): string
    {
        $content = self::body($contentHtml);

        // preg_replace would interpret "$1" or "\1" in the content: use a callback.
        return preg_replace_callback(self::SLOT_PATTERN, static fn () => $content, $layoutHtml, 1) ?? $layoutHtml;
    }

    public static function body(string $html): string
    {
        if (preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $m)) {
            return trim($m[1]);
        }

        return trim(preg_replace('#</?(?:html|head|body)\b[^>]*>#i', '', preg_replace('#<head\b.*?</head>#is', '', $html) ?? $html) ?? $html);
    }
}
