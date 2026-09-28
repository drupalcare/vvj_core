<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Reads the per-format briefs: advice, example prompts, count and features.
 *
 * One file, `vvj_core_ai.briefs`, the same pattern as paragraphs_bundles_ai —
 * the maintainer, 2026-09-27: "do the same thing as you did with pb".
 */
interface BriefsInterface {

  /**
   * One format's brief, with every key present.
   *
   * @param string $format
   *   Views style plugin id.
   *
   * @return array{good_for: string, practices: string, count: int, prompts: array<string, string>, features: array<string, string>}|null
   *   The brief, or NULL when the format has none.
   */
  public function forFormat(string $format): ?array;

}
