<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

/**
 * Sanitizes SVG markup by whitelisting safe elements + attributes.
 *
 * Pattern modules that accept admin-pasted SVG icons (vvja for the
 * accordion +/- icons; vvjs/vvjb for navigation arrows; etc.) consume
 * this service via DI. Centralizing the parser means a security fix
 * lands once and applies to every pattern.
 *
 * Defense-in-depth strategy:
 *   1. Reject markup whose root element isn't `<svg>`.
 *   2. Pattern-block dangerous tags (`<script>`, `<foreignObject>`, etc.)
 *      and dangerous URI schemes (`javascript:`, `data:`, `vbscript:`).
 *   3. Parse via DOMDocument; recursively strip non-whitelisted elements
 *      and attributes; preserve only fragment-reference hrefs.
 */
final class SvgSanitizer {

  /**
   * Allowed SVG element names.
   *
   * @var list<string>
   */
  private const array ALLOWED_ELEMENTS = [
    'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
    'ellipse', 'g', 'defs', 'use', 'symbol', 'clippath', 'mask',
    'lineargradient', 'radialgradient', 'stop', 'title', 'desc',
  ];

  /**
   * Allowed attribute names (lowercased).
   *
   * @var list<string>
   */
  private const array ALLOWED_ATTRIBUTES = [
    // Core.
    'id', 'class', 'xmlns', 'xmlns:xlink', 'xml:space',
    // Dimensions / positioning.
    'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
    'cx', 'cy', 'r', 'rx', 'ry', 'dx', 'dy',
    // Path / shape.
    'd', 'points',
    // Presentation.
    'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
    'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
    'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity',
    'transform', 'display', 'visibility', 'color',
    // Gradient.
    'offset', 'stop-color', 'stop-opacity', 'gradientunits',
    'gradienttransform', 'spreadmethod', 'fx', 'fy',
    // Clip / mask.
    'clip-path', 'clippathunits', 'mask', 'maskunits',
    // Reference (validated separately for safe values).
    'href', 'xlink:href',
  ];

  /**
   * Dangerous substring patterns blocked before parsing.
   *
   * @var list<string>
   */
  private const array DANGEROUS_PATTERNS = [
    '<script', '<foreignobject', '<iframe', '<object', '<embed', '<applet',
    'javascript:', 'data:text/', 'data:application/', 'vbscript:',
  ];

  /**
   * Sanitize SVG markup; return safe-to-render string or empty on failure.
   *
   * @param string $svg
   *   The raw SVG markup.
   *
   * @return string
   *   Sanitized SVG markup, or empty string if the input fails any
   *   safety check.
   */
  public function sanitize(string $svg): string {
    $svg = trim($svg);
    if ($svg === '' || stripos($svg, '<svg') !== 0) {
      return '';
    }

    if ($this->containsDangerousPatterns($svg)) {
      return '';
    }

    $dom = new \DOMDocument();
    $dom->formatOutput = FALSE;
    $dom->preserveWhiteSpace = FALSE;

    libxml_use_internal_errors(TRUE);
    $loaded = $dom->loadXML($svg);
    libxml_clear_errors();

    if (!$loaded) {
      return '';
    }

    $root = $dom->documentElement;
    if (!$root || strtolower($root->nodeName) !== 'svg') {
      return '';
    }

    $this->sanitizeNode($root);

    $result = $dom->saveXML($root);
    return $result !== FALSE ? $result : '';
  }

  /**
   * Validate SVG markup without modifying it. Returns errors as strings.
   *
   * @param string $svg
   *   The SVG markup to validate.
   *
   * @return list<string>
   *   Array of human-readable error messages. Empty if SVG is valid.
   */
  public function validate(string $svg): array {
    $errors = [];
    $svg = trim($svg);

    if ($svg === '') {
      return $errors;
    }

    if (stripos($svg, '<svg') !== 0) {
      $errors[] = 'SVG markup must start with an &lt;svg&gt; element.';
      return $errors;
    }

    if ($this->containsDangerousPatterns($svg)) {
      $errors[] = 'SVG contains potentially dangerous content (scripts, event handlers, or external references).';
      return $errors;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $loaded = $dom->loadXML($svg);
    $xml_errors = libxml_get_errors();
    libxml_clear_errors();

    if (!$loaded || !empty($xml_errors)) {
      $errors[] = 'SVG markup is not valid XML.';
      return $errors;
    }

    $root = $dom->documentElement;
    if (!$root || strtolower($root->nodeName) !== 'svg') {
      $errors[] = 'Root element must be &lt;svg&gt;.';
      return $errors;
    }

    $disallowed = $this->findDisallowedElements($root);
    if (!empty($disallowed)) {
      $errors[] = 'SVG contains disallowed elements: ' . implode(', ', array_unique($disallowed)) . '.';
    }

    return $errors;
  }

  /**
   * Substring + regex check for dangerous patterns.
   */
  private function containsDangerousPatterns(string $svg): bool {
    $lower = strtolower($svg);

    foreach (self::DANGEROUS_PATTERNS as $pattern) {
      if (str_contains($lower, $pattern)) {
        return TRUE;
      }
    }

    // Block on* event-handler attributes (onclick, onload, etc.).
    return (bool) preg_match('/\bon[a-z]+\s*=/i', $svg);
  }

  /**
   * Recursively sanitize a DOM node — strip non-whitelisted children.
   */
  private function sanitizeNode(\DOMNode $node): void {
    /** @var array<\DOMNode> $remove */
    $remove = [];

    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $tag = strtolower($child->nodeName);
        if (!in_array($tag, self::ALLOWED_ELEMENTS, TRUE)) {
          $remove[] = $child;
          continue;
        }
        $this->sanitizeAttributes($child);
        $this->sanitizeNode($child);
      }
      elseif ($child instanceof \DOMProcessingInstruction || $child instanceof \DOMComment) {
        $remove[] = $child;
      }
    }

    foreach ($remove as $child) {
      $node->removeChild($child);
    }

    if ($node instanceof \DOMElement) {
      $this->sanitizeAttributes($node);
    }
  }

  /**
   * Strip non-whitelisted attributes + validate href values.
   */
  private function sanitizeAttributes(\DOMElement $element): void {
    /** @var list<string> $remove */
    $remove = [];

    foreach ($element->attributes as $attr) {
      $name = strtolower($attr->nodeName);

      if (str_starts_with($name, 'on')) {
        $remove[] = $attr->nodeName;
        continue;
      }

      if (!in_array($name, self::ALLOWED_ATTRIBUTES, TRUE)) {
        $remove[] = $attr->nodeName;
        continue;
      }

      if ($name === 'href' || $name === 'xlink:href') {
        $val = strtolower(trim($attr->value));
        if (
          str_starts_with($val, 'javascript:')
          || str_starts_with($val, 'data:')
          || str_starts_with($val, 'vbscript:')
          || !str_starts_with($val, '#')
        ) {
          $remove[] = $attr->nodeName;
        }
      }
    }

    foreach ($remove as $name) {
      $element->removeAttribute($name);
    }
  }

  /**
   * Walk the tree to collect disallowed element names for the validator.
   *
   * @return list<string>
   *   Disallowed element names found, formatted with HTML entities.
   */
  private function findDisallowedElements(\DOMNode $node): array {
    $disallowed = [];

    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $tag = strtolower($child->nodeName);
        if (!in_array($tag, self::ALLOWED_ELEMENTS, TRUE)) {
          $disallowed[] = '&lt;' . $tag . '&gt;';
        }
        $disallowed = array_merge($disallowed, $this->findDisallowedElements($child));
      }
    }

    return $disallowed;
  }

}
