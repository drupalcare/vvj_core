<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * The format settings a new view is created with, and how to name them.
 *
 * Maintainer, 2026-09-27: *"we must activate most of the features in each
 * plugins and tell the user they can disable it"*.
 */
interface ViewFeaturesInterface {

  /**
   * The brief's features, held to the format's own settings form.
   *
   * A value is kept only when the setting exists and can hold it: a choice
   * the select offers, on or off for a checkbox, a number for a number. A
   * colour, a text box or anything else is left at the format's default.
   *
   * @param string $format
   *   Views style plugin id.
   *
   * @return array<string, bool|int|float|string>
   *   Setting name => value, ready for the view's style options.
   */
  public function valuesFor(string $format): array;

  /**
   * Each feature as a person reads it: the setting's label, then its value.
   *
   * @param string $format
   *   Views style plugin id.
   * @param array<string, bool|int|float|string> $values
   *   What valuesFor() returned.
   *
   * @return list<string>
   *   For example `Show play/pause button: on`.
   */
  public function describe(string $format, array $values): array;

}
