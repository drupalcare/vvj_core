<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Reads a format's real settings out of its own settings form.
 *
 * Reads only. The view it builds to do so exists in memory and is never saved.
 *
 * @see \Drupal\vvj_core_ai\Service\SettingsReader
 * @see docs/VVJ-AI-BRIEF.md §2
 */
interface SettingsReaderInterface {

  /**
   * Every setting a format exposes.
   *
   * @param string $format
   *   Views plugin id (`views_vvjs`) or module name (`vvjs`).
   *
   * @return list<\Drupal\vvj_core_ai\Model\FormatSetting>|null
   *   The settings, or NULL when this site does not have that format. An
   *   EMPTY array means the format is installed but its form could not be
   *   read — a different answer, and one the caller must not report as
   *   "this format has no settings".
   */
  public function read(string $format): ?array;

}
