<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

/**
 * Twig extension exposing the `safe_html` filter to every VVJ template.
 *
 * Marking output as safe-for-HTML lets pattern templates render
 * pre-sanitized markup (e.g., the trigger HTML split out of a Views
 * row) without re-escaping. This filter is consumed by every VVJ
 * pattern's main template — `views-view-vvja.html.twig`,
 * `views-view-vvjs.html.twig`, etc.
 *
 * SECURITY: `safe_html` bypasses Twig's auto-escaping. Apply only to
 * content that has been pre-sanitized by Drupal's renderer or by the
 * VVJ `SvgSanitizer` service. Never apply to raw user input.
 */
class VvjCoreTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   *
   * @return list<\Twig\TwigFilter>
   *   The Twig filters this extension exposes.
   */
  public function getFilters(): array {
    return [
      new TwigFilter('safe_html', $this->safeHtml(...), ['is_safe' => ['html']]),
    ];
  }

  /**
   * Mark a pre-sanitized HTML string as safe for Twig output.
   *
   * @param string $string
   *   Pre-sanitized HTML markup.
   *
   * @return \Twig\Markup
   *   Safe markup wrapped as a Twig Markup object.
   */
  public function safeHtml(string $string): Markup {
    return new Markup($string, 'UTF-8');
  }

}
