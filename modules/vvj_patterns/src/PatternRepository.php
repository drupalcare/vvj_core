<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Reads the Patterns a VVJ module ships in `<module>/patterns/<p>/pattern.yml`.
 *
 * Each module owns its Patterns (files, CSS, JS, library). This service only
 * finds and normalizes their definitions, so every module reads them the same
 * way. A Pattern definition holds:
 * - label: the Pattern name shown in the select (English, translatable).
 * - description: one sentence shown under the select.
 * - options: style option values Save writes into the view, keyed by the
 *   module's own option names.
 * - features: optional template features the Pattern switches on.
 * - needs: steps the site builder must still do by hand.
 *
 * @phpstan-type PatternDefinition array{id: string, name: string, module: string, label: string, description: string, options: array<string, mixed>, features: list<string>, needs: list<string>, library: string, class: string}
 */
final class PatternRepository {

  /**
   * Definitions per module, memoised for the request.
   *
   * @var array<string, array<string, PatternDefinition>>
   */
  private array $cache = [];

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Returns the Patterns a module ships, keyed by Pattern id.
   *
   * @param string $module
   *   The module machine name, for example `vvjt`.
   *
   * @return array<string, PatternDefinition>
   *   Definitions keyed by `<module>_<pattern>`, sorted by their weight.
   */
  public function getPatterns(string $module): array {
    if (isset($this->cache[$module])) {
      return $this->cache[$module];
    }
    $patterns = [];
    if (!$this->moduleExtensionList->exists($module)) {
      return $this->cache[$module] = [];
    }
    $dir = $this->moduleExtensionList->getPath($module) . '/patterns';
    $files = glob($dir . '/*/pattern.yml') ?: [];
    $weights = [];
    foreach ($files as $file) {
      $name = basename(dirname($file));
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
        continue;
      }
      $raw = file_get_contents($file);
      $data = $raw === FALSE ? NULL : Yaml::decode($raw);
      if (!is_array($data) || !isset($data['label']) || !is_string($data['label'])) {
        continue;
      }
      $id = $module . '_' . $name;
      $patterns[$id] = [
        'id' => $id,
        'name' => $name,
        'module' => $module,
        'label' => $data['label'],
        'description' => is_string($data['description'] ?? NULL) ? $data['description'] : '',
        'options' => is_array($data['options'] ?? NULL) ? $data['options'] : [],
        'features' => array_values(array_filter((array) ($data['features'] ?? []), 'is_string')),
        'needs' => array_values(array_filter((array) ($data['needs'] ?? []), 'is_string')),
        'library' => $module . '/pattern.' . $name,
        'class' => 'vvj-pattern--' . $module . '-' . str_replace('_', '-', $name),
      ];
      $weights[$id] = is_int($data['weight'] ?? NULL) ? $data['weight'] : 0;
    }
    uksort($patterns, static fn (string $a, string $b): int => [$weights[$a], $a] <=> [$weights[$b], $b]);
    return $this->cache[$module] = $patterns;
  }

  /**
   * Returns one Pattern definition, or NULL when the id is unknown.
   *
   * @param string $module
   *   The module machine name.
   * @param string $id
   *   The Pattern id, `<module>_<pattern>`.
   *
   * @return array<string, mixed>|null
   *   The definition, or NULL.
   */
  public function getPattern(string $module, string $id): ?array {
    return $this->getPatterns($module)[$id] ?? NULL;
  }

}
