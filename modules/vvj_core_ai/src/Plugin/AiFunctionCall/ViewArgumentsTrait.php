<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

/**
 * Reads the view-building arguments the two writing tools share.
 *
 * WHY THE LIST READ IS DEFENSIVE. `drupal/ai`'s ListConverter turns a CSV
 * string into an indexed array, so `fields` normally arrives as one. It is not
 * guaranteed to: the converter runs only when a converter plugin manager is
 * present and the value is not already an array, and a tool called directly —
 * from a test, from another module, from drush — bypasses it entirely. Reading
 * the value as an array without checking would then fail on a plain string,
 * which is exactly the kind of break that only shows up in production.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
trait ViewArgumentsTrait {

  /**
   * A context value as a trimmed string.
   *
   * @param string $name
   *   The context name.
   *
   * @return string
   *   The value, or '' when the model omitted it.
   */
  protected function stringArgument(string $name): string {
    $value = $this->contextValue($name);

    return is_string($value) ? trim($value) : '';
  }

  /**
   * A context value as a list of trimmed, non-empty strings.
   *
   * @param string $name
   *   The context name.
   *
   * @return list<string>
   *   The values, in the order given.
   */
  protected function listArgument(string $name): array {
    $value = $this->contextValue($name);

    if (is_string($value)) {
      // The converter did not run. Split the way it would have.
      $value = str_getcsv($value, ',', '"', '\\');
    }

    if (!is_array($value)) {
      return [];
    }

    $out = [];

    foreach ($value as $item) {
      if (!is_string($item)) {
        continue;
      }

      $item = trim($item);

      if ($item !== '') {
        $out[] = $item;
      }
    }

    return $out;
  }

}
