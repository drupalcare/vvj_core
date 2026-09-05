<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\vvj_core_ai\Model\VvjFormat;

/**
 * Finds the VVJ formats this site actually has.
 *
 * Reads only. Nothing behind this interface saves anything.
 *
 * @see \Drupal\vvj_core_ai\Service\FormatRegistry
 * @see docs/VVJ-AI-BRIEF.md §2
 */
interface FormatRegistryInterface {

  /**
   * Every VVJ format installed on this site.
   *
   * @return array<string, \Drupal\vvj_core_ai\Model\VvjFormat>
   *   Plugin id => format, sorted by id so the answer reads the same twice.
   *   Empty when vvj_core is installed but no format module is — a real state,
   *   and one the caller must report rather than treat as a failure.
   */
  public function all(): array;

  /**
   * One format by plugin id or by module name.
   *
   * @param string $pluginId
   *   Either the Views plugin id (`views_vvjs`) or the module name (`vvjs`).
   *   People say the second; Views wants the first.
   *
   * @return \Drupal\vvj_core_ai\Model\VvjFormat|null
   *   The format, or NULL when this site does not have it.
   */
  public function get(string $pluginId): ?VvjFormat;

}
