<?php

namespace App\Inbox;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Sanitizes the HTML of received messages, server side: no scripts, style sheets, forms, frames, event handlers
 * or dangerous URLs; inline styles kept without anything that loads a resource. The stored version keeps the
 * remote images (http/https), removed on display unless the reader asks for them (withoutRemoteImages()).
 * The front end also renders it in a sandboxed iframe with a restrictive CSP.
 */
final class MessageSanitizer implements AttributeSanitizerInterface
{
    public const MAX_INPUT = 2_000_000;

    private HtmlSanitizer $withImages;
    private HtmlSanitizer $withoutImages;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('valign', '*')
            ->allowAttribute('bgcolor', '*')
            ->allowAttribute('color', '*')
            ->allowAttribute('width', '*')
            ->allowAttribute('height', '*')
            ->allowAttribute('cellpadding', '*')
            ->allowAttribute('cellspacing', '*')
            ->allowAttribute('border', '*')
            ->dropAttribute('background', '*')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('select')
            ->dropElement('textarea')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withAttributeSanitizer($this)
            ->withMaxInputLength(self::MAX_INPUT);

        $this->withImages = new HtmlSanitizer($config->allowMediaSchemes(['http', 'https']));
        $this->withoutImages = new HtmlSanitizer($config->allowMediaSchemes([])->allowMediaHosts([]));
    }

    /** @return array{html: string, hasRemoteImages: bool} */
    public function sanitize(string $html): array
    {
        $clean = $this->withImages->sanitize($html);

        return ['html' => $clean, 'hasRemoteImages' => self::hasRemoteImages($clean)];
    }

    /** The stored (already sanitized) HTML, without its remote images. */
    public function withoutRemoteImages(string $sanitizedHtml): string
    {
        return $this->withoutImages->sanitize($sanitizedHtml);
    }

    public static function hasRemoteImages(string $html): bool
    {
        return 1 === preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*https?:/i', $html);
    }

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    /** Inline styles: declarations that load or execute something are dropped, as well as fixed positioning. */
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];
        foreach (explode(';', html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5)) as $declaration) {
            $declaration = trim($declaration);
            if ('' === $declaration) {
                continue;
            }
            $normalized = strtolower((string) preg_replace('/\s+|\\\\|\/\*.*?\*\//s', '', $declaration));
            if (preg_match('/url\(|image-set\(|expression\(|javascript:|vbscript:|@import|behavior:|-moz-binding|position:(fixed|sticky)/', $normalized)) {
                continue;
            }
            $kept[] = $declaration;
        }

        return [] === $kept ? null : implode('; ', $kept);
    }
}
