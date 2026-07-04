<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\ViewEntityInterface;

/**
 * Audits Views configuration for VVJ style-plugin usage and schema drift.
 *
 * This is deliberately Drush-independent: the scan and drift logic live
 * here (unit-testable), and the `vvj:upgrade` Drush command is a thin
 * presentation wrapper over it. It is a post-upgrade audit tool — after a
 * site updates from v1 to v2, it confirms every installed VVJ view is
 * recognized and matches the current schema. The v2.0 drop-in upgrade
 * preserves every v1 option key and default, so `detectDrift()` reports
 * nothing; it is simply the one place an option-key check would live if a
 * later release ever changed one.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
final class VvjUpgradeAuditor {

  /**
   * VVJ Views style plugin IDs — one per pattern module.
   *
   * @var list<string>
   */
  private const array STYLE_PLUGINS = [
    'views_vvja',
    'views_vvjb',
    'views_vvjc',
    'views_vvjf',
    'views_vvjh',
    'views_vvjl',
    'views_vvjp',
    'views_vvjr',
    'views_vvjs',
    'views_vvjt',
  ];

  /**
   * Constructs a VvjUpgradeAuditor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads View configuration entities.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Find every View that uses a VVJ style plugin.
   *
   * @param list<string> $slugs
   *   Pattern slugs to limit the scan to (e.g. `['vvja']`). Empty scans all.
   *
   * @return array<string, list<string>>
   *   Map of view config ID → sorted VVJ style plugin IDs it uses.
   */
  public function findVvjViews(array $slugs = []): array {
    $allowed = $this->allowedPlugins($slugs);
    /** @var array<string, list<string>> $matches */
    $matches = [];

    $views = $this->entityTypeManager->getStorage('view')->loadMultiple();
    foreach ($views as $view) {
      if (!$view instanceof ViewEntityInterface) {
        continue;
      }
      $displays = $view->get('display');
      if (!is_array($displays)) {
        continue;
      }

      /** @var array<string, string> $used */
      $used = [];
      foreach ($displays as $display) {
        if (!is_array($display)) {
          continue;
        }
        $displayOptions = $display['display_options'] ?? NULL;
        $styleConfig = is_array($displayOptions)
          ? ($displayOptions['style'] ?? NULL)
          : NULL;
        $style = is_array($styleConfig) ? ($styleConfig['type'] ?? NULL) : NULL;
        if (is_string($style) && in_array($style, $allowed, TRUE)) {
          $used[$style] = $style;
        }
      }

      if ($used !== []) {
        $ids = array_values($used);
        sort($ids);
        $matches[(string) $view->id()] = $ids;
      }
    }

    ksort($matches);
    return $matches;
  }

  /**
   * Detect option-key drift for a view against the current VVJ schema.
   *
   * The v2.0 drop-in upgrade preserves every v1 option key and default
   * value, so no existing view reports drift. This is the single place an
   * option-key check would live if a later release ever changed one.
   *
   * @param string $viewId
   *   The view config ID (reserved for future per-view checks).
   * @param list<string> $plugins
   *   VVJ style plugin IDs the view uses (reserved for future checks).
   *
   * @return list<string>
   *   Human-readable drift descriptions; empty means the view is current.
   */
  public function detectDrift(string $viewId, array $plugins): array {
    // v2.0: no breaking renames — every v1 view is already current.
    return [];
  }

  /**
   * Resolve the set of style plugin IDs to scan for.
   *
   * @param list<string> $slugs
   *   Pattern slugs, or empty for all patterns.
   *
   * @return list<string>
   *   VVJ style plugin IDs.
   */
  private function allowedPlugins(array $slugs): array {
    if ($slugs === []) {
      return self::STYLE_PLUGINS;
    }
    /** @var list<string> $allowed */
    $allowed = [];
    foreach (self::STYLE_PLUGINS as $plugin) {
      if (in_array(substr($plugin, strlen('views_')), $slugs, TRUE)) {
        $allowed[] = $plugin;
      }
    }
    return $allowed;
  }

}
