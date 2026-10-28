<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Whether an SVG fetched from an outside website is a drawing and nothing
 * more. Such files are served from our own address, where a script inside
 * one would run as the person who opens it (security audit, 28 Oct 2026).
 * The file is parsed, so a prefixed `<x:script>` or an entity-encoded
 * `javascript:` is seen for what it is, and only drawing elements pass.
 */
final class SafeSvg
{
    private const SVG = 'http://www.w3.org/2000/svg';

    /** Namespaces whose elements a browser would act on inside an SVG. */
    private const ACTIVE_NAMESPACES = ['http://www.w3.org/1999/xhtml', 'http://www.w3.org/1998/Math/MathML'];

    private const DRAWING = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath',
        'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'marker', 'filter', 'image',
    ];

    public static function isDrawing(string $svg): bool
    {
        // Entities and XSLT stylesheets are refused before anything is parsed.
        if ($svg === '' || preg_match('~<!DOCTYPE|<!ENTITY|<\?xml-stylesheet~i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->namespaceURI !== self::SVG || mb_strtolower($root->localName ?? '') !== 'svg') {
            return false;
        }

        $xpath = new DOMXPath($document);

        $instructions = $xpath->query('//processing-instruction()');
        $elements = $xpath->query('//*');

        if ($instructions === false || $instructions->length > 0 || $elements === false) {
            return false;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement || ! self::allowedElement($element)) {
                return false;
            }

            foreach ($element->attributes as $attribute) {
                if (! self::allowedAttribute($element, $attribute)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function allowedElement(DOMElement $element): bool
    {
        $name = mb_strtolower($element->localName ?? '');

        if ($name === 'script' || in_array($element->namespaceURI, self::ACTIVE_NAMESPACES, true)) {
            return false;
        }

        // Editors' own elements (Inkscape, Illustrator, RDF metadata) are ignored by browsers.
        if ($element->namespaceURI !== self::SVG) {
            return $element->namespaceURI !== null;
        }

        return in_array($name, self::DRAWING, true) || str_starts_with($name, 'fe');
    }

    private static function allowedAttribute(DOMElement $element, DOMAttr $attribute): bool
    {
        $name = mb_strtolower($attribute->localName ?? '');

        if (str_starts_with($name, 'on')) {
            return false;
        }

        if ($name !== 'href') {
            return true;
        }

        // The parser has decoded entities already; browsers also skip spaces and control characters.
        $value = mb_strtolower((string) preg_replace('~[\x00-\x20]+~', '', $attribute->value));

        return str_starts_with($value, '#')
            || (mb_strtolower($element->localName ?? '') === 'image' && (bool) preg_match('~^data:image/(png|jpe?g|gif|webp);~', $value));
    }
}
