<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Formato enriquecido del Aula: HTML limitado, sin código ni recursos externos incrustados. */
final class AulaHtmlSanitizer
{
    private const TAGS = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'a', 'div', 'span', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    private const REMOVE_WITH_CHILDREN = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'svg', 'math', 'video', 'audio', 'img', 'template', 'noscript', 'base', 'link', 'meta'];
    private const CLASSES = ['aula-rich-banner', 'aula-rich-card', 'aula-rich-note', 'aula-rich-grid'];

    public function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8"?><div id="aula-root">'.$html.'</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->getElementById('aula-root');
        if (! $root) return '';
        foreach (iterator_to_array($root->childNodes) as $node) $this->clean($node);

        $result = '';
        foreach ($root->childNodes as $node) $result .= $document->saveHTML($node);

        return $result;
    }

    private function clean(DOMNode $node): void
    {
        if (! $node instanceof DOMElement) {
            if ($node->nodeType !== XML_TEXT_NODE) $node->parentNode?->removeChild($node);
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::REMOVE_WITH_CHILDREN, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }
        foreach (iterator_to_array($node->childNodes) as $child) $this->clean($child);
        if (! in_array($tag, self::TAGS, true)) {
            while ($node->firstChild) $node->parentNode?->insertBefore($node->firstChild, $node);
            $node->parentNode?->removeChild($node);
            return;
        }
        $href = $tag === 'a' ? $node->getAttribute('href') : '';
        $class = $node->getAttribute('class');
        foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttributeNode($attribute);
        if ($tag === 'a' && preg_match('~^https?://~i', $href)) {
            $node->setAttribute('href', $href);
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        }
        $safeClasses = array_values(array_intersect(preg_split('/\s+/', $class) ?: [], self::CLASSES));
        if ($safeClasses) $node->setAttribute('class', implode(' ', $safeClasses));
    }
}
