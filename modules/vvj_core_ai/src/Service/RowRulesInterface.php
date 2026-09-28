<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Says whether a format can show whole items in a view mode, or only fields.
 *
 * Maintainer, 2026-09-27: *"in views there is some thing different than pb
 * where you can use fields or view mode we must count for that, and we must
 * know when we can field or view modes"*.
 *
 * THE FORMAT IS ASKED, NOT A LIST. VvjStylePluginBase::requiresFieldsRow()
 * is TRUE, and the base FORCES the row back to fields unless a format opts
 * out — measured 2026-09-27: Basic Carousel and 3D Carousel opt out always,
 * Slideshow only outside hero mode. A copied list would go stale the day a
 * format changes its mind; asking the plugin cannot.
 */
interface RowRulesInterface {

  /**
   * Whether the format keeps a view-mode row rather than forcing fields.
   *
   * @param string $format
   *   Views style plugin id.
   * @param array<string, mixed> $options
   *   The format options the view will have — Slideshow's answer depends on
   *   `hero_slideshow`.
   *
   * @return bool
   *   TRUE when a rendered-entity row in a view mode is kept.
   */
  public function allowsViewMode(string $format, array $options = []): bool;

}
