<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/** A deliberately small HTML dialect shared by the editor, previews and PDF. */
final class IndicatorHtml
{
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'img', 'video', 'audio', 'form', 'input', 'button', 'template', 'link', 'meta', 'noscript', 'base'];

    public function sanitize(string $input): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8"?><div id="indicator-root">'.$input.'</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->getElementById('indicator-root');
        if (! $root) {
            return '';
        }

        return implode('', array_map($this->render(...), iterator_to_array($root->childNodes)));
    }

    public function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return str_replace("\n", '<br>', htmlspecialchars(str_replace(["\r\n", "\r"], "\n", $node->textContent),
                ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        if ($tag === 'br') {
            return '<br>';
        }
        $content = implode('', array_map($this->render(...), iterator_to_array($node->childNodes)));
        if ($tag === 'strong' || $tag === 'b') {
            return '<strong>'.$content.'</strong>';
        }
        if ($tag === 'em' || $tag === 'i') {
            return '<em>'.$content.'</em>';
        }
        if ($tag === 'p' || $tag === 'div') {
            return '<p>'.$content.'</p>';
        }
        if (in_array($tag, ['u', 'ul', 'ol', 'li'], true)) {
            return '<'.$tag.'>'.$content.'</'.$tag.'>';
        }

        return $content;
    }
}
