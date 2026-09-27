<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;

/**
 * Audits Views configuration for VVJ style-plugin usage and schema drift.
 *
 * This is deliberately Drush-independent: the scan and drift logic live
 * here (unit-testable), and the `vvj:upgrade` Drush command is a thin
 * presentation wrapper over it. It also CONVERTS: normalize() is what each
 * pattern module's update hook calls, so `drush updb` and `drush vvj:upgrade`
 * share one implementation.
 *
 * 🚨 CORRECTED 2026-09-26. This docblock used to say the v2 upgrade
 * "preserves every v1 option key and default, so detectDrift() reports
 * nothing", and detectDrift() was a stub returning []. Both were false. A
 * real v1 database (solo.local) carried: option values stored as strings or
 * integers where the v2 schema declares integer/boolean/float, across most
 * pattern modules;
 * vvja's `first_toggle`, replaced in late 1.x by `expand_default` with no
 * conversion, so "first panel open" silently stopped working; and vvjh's
 * 1.0-era `min_height` stored as a bare number, which v2 ignores and renders
 * at its fallback height.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
final class VvjUpgradeAuditor {

  use SchemaCheckTrait;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads and saves view configuration. Saving through it (untrusted)
   *   casts every value to its schema type.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   Checks view configuration against the schema.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $stylePluginManager
   *   Resolves a style plugin ID to the class that knows its legacy options.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TypedConfigManagerInterface $typedConfig,
    private readonly PluginManagerInterface $stylePluginManager,
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
   * Describe how a view's VVJ style options differ from the v2 shape.
   *
   * Two sources: options the plugin's upgradeLegacyOptions() would change
   * (renamed or restructured keys), and schema violations under
   * `style.options` (values stored with the wrong type). Everything outside
   * the style options, such as core Views keys, is not VVJ's to report.
   *
   * @param string $viewId
   *   The view config ID.
   * @param list<string> $plugins
   *   VVJ style plugin IDs to check in that view.
   *
   * @return list<string>
   *   Human-readable drift descriptions; empty means the view is current.
   */
  public function detectDrift(string $viewId, array $plugins): array {
    $name = 'views.view.' . $viewId;
    $data = $this->configFactory->get($name)->getRawData();
    $displays = $data['display'] ?? NULL;
    if (!is_array($displays)) {
      return [];
    }

    /** @var list<string> $drift */
    $drift = [];
    foreach ($displays as $displayId => $display) {
      $style = is_array($display) ? ($display['display_options']['style'] ?? NULL) : NULL;
      $type = is_array($style) ? ($style['type'] ?? NULL) : NULL;
      if (!is_string($type) || !in_array($type, $plugins, TRUE)) {
        continue;
      }
      $options = is_array($style['options'] ?? NULL) ? $style['options'] : [];
      $upgraded = $this->upgradeOptions($type, $options);
      foreach (array_keys($options + $upgraded) as $key) {
        if (!array_key_exists($key, $upgraded)) {
          $drift[] = sprintf('%s: "%s" is a v1 option that v2 replaced.', $displayId, $key);
        }
        elseif (!array_key_exists($key, $options)) {
          $drift[] = sprintf('%s: "%s" will be set from the v1 option it replaces.', $displayId, $key);
        }
        elseif ($options[$key] !== $upgraded[$key]) {
          $drift[] = sprintf('%s: "%s" is stored in its v1 shape.', $displayId, $key);
        }
      }
    }

    $errors = $this->checkConfigSchema($this->typedConfig, $name, $data);
    if (is_array($errors)) {
      foreach ($errors as $path => $message) {
        if (preg_match('/:display\.([^.]+)\.display_options\.style\.options\.(.+)$/', (string) $path, $m)) {
          $drift[] = sprintf('%s: "%s" %s.', $m[1], $m[2], $message);
        }
      }
    }

    return array_values(array_unique($drift));
  }

  /**
   * Convert a view's VVJ displays to the v2 option shape and save it.
   *
   * Each display's options go through its plugin's upgradeLegacyOptions(),
   * then the view is saved through the config API WITHOUT trusted data, which
   * casts every value to the type its schema declares. Idempotent: a view
   * already in the v2 shape saves unchanged.
   *
   * The cast is core's plain PHP cast: a value that is not numeric in an
   * integer key becomes 0, without an error.
   *
   * @param string $viewId
   *   The view config ID.
   * @param list<string> $plugins
   *   VVJ style plugin IDs to convert; displays using others are untouched.
   *
   * @return bool
   *   TRUE when the stored configuration changed.
   */
  public function normalize(string $viewId, array $plugins): bool {
    $config = $this->configFactory->getEditable('views.view.' . $viewId);
    $before = $config->getRawData();
    $displays = $config->get('display');
    if (!is_array($displays)) {
      return FALSE;
    }

    $touched = FALSE;
    foreach ($displays as $displayId => $display) {
      $style = is_array($display) ? ($display['display_options']['style'] ?? NULL) : NULL;
      $type = is_array($style) ? ($style['type'] ?? NULL) : NULL;
      if (!is_string($type) || !in_array($type, $plugins, TRUE)) {
        continue;
      }
      $options = is_array($style['options'] ?? NULL) ? $style['options'] : [];
      $displays[$displayId]['display_options']['style']['options'] = $this->upgradeOptions($type, $options);
      $touched = TRUE;
    }
    if (!$touched) {
      return FALSE;
    }

    // save() without trusted data runs StorableConfigBase::castValue() over
    // every key, which is what converts '500' to 500 and 1 to TRUE.
    $config->set('display', $displays)->save();

    return $config->getRawData() !== $before;
  }

  /**
   * Convert every view using the given patterns; for update hooks.
   *
   * @param list<string> $slugs
   *   Pattern slugs, e.g. `['vvja']`.
   *
   * @return list<string>
   *   IDs of the views whose stored configuration changed.
   */
  public function normalizeAll(array $slugs): array {
    $allowed = $this->allowedPlugins($slugs);
    /** @var list<string> $changed */
    $changed = [];
    foreach ($this->findVvjViews($slugs) as $viewId => $plugins) {
      if ($this->normalize((string) $viewId, array_values(array_intersect($plugins, $allowed)))) {
        $changed[] = (string) $viewId;
      }
    }
    return $changed;
  }

  /**
   * Run a style plugin's legacy-option conversion.
   *
   * @param string $pluginId
   *   The style plugin ID.
   * @param array<string, mixed> $options
   *   Stored options.
   *
   * @return array<string, mixed>
   *   Options in the current shape; unchanged for an unknown plugin.
   */
  private function upgradeOptions(string $pluginId, array $options): array {
    $definition = $this->stylePluginManager->getDefinition($pluginId, FALSE);
    $class = is_array($definition) ? ($definition['class'] ?? NULL) : NULL;
    if (is_string($class) && is_subclass_of($class, VvjStylePluginBase::class)) {
      return $class::upgradeLegacyOptions($options);
    }
    return $options;
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
