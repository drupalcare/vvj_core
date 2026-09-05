<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\views\Plugin\ViewsPluginManager;
use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;
use Drupal\vvj_core_ai\Model\VvjFormat;

/**
 * Finds the VVJ formats this site actually has.
 *
 * DISCOVERY IS BY BASE CLASS, NOT BY NAME, and that is the whole design. There
 * is no list of format machine names anywhere in this module. Filtering the
 * Views style definitions on `VvjStylePluginBase` returns whatever the site
 * installed — three formats or ten — and an eleventh format released next year
 * is picked up here without a line changing.
 *
 * Measured on sts.local 2026-09-04 with the full family enabled: the filter
 * returns exactly ten, `views_vvja` through `views_vvjt`.
 *
 * NOTHING HERE WRITES. The registry holds a plugin manager and nothing it could
 * save with, which is how the "never changes a setting" rule is kept — by the
 * absence of a way to break it, not by a promise in a prompt.
 */
final class FormatRegistry implements FormatRegistryInterface {

  /**
   * Discovered formats, keyed by plugin id.
   *
   * @var array<string, \Drupal\vvj_core_ai\Model\VvjFormat>|null
   */
  private ?array $formats = NULL;

  /**
   * Constructs the registry.
   *
   * @param \Drupal\views\Plugin\ViewsPluginManager $styleManager
   *   The Views style plugin manager.
   */
  public function __construct(
    private readonly ViewsPluginManager $styleManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    if ($this->formats !== NULL) {
      return $this->formats;
    }

    $out = [];

    foreach ($this->styleManager->getDefinitions() as $id => $definition) {
      // A plugin definition is `mixed`. Indexing into it without checking is
      // the assumption these tools are built to refuse to make about a site.
      if (!is_array($definition)) {
        continue;
      }

      $class = $definition['class'] ?? NULL;

      if (!is_string($class) || !is_subclass_of($class, VvjStylePluginBase::class)) {
        continue;
      }

      $out[(string) $id] = new VvjFormat(
        (string) $id,
        $this->text($definition['provider'] ?? ''),
        $this->text($definition['title'] ?? '') ?: (string) $id,
        $this->text($definition['help'] ?? '') ?: $this->text($definition['short_title'] ?? ''),
        $this->countSettings((string) $id),
      );
    }

    ksort($out);

    return $this->formats = $out;
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $pluginId): ?VvjFormat {
    $pluginId = trim($pluginId);
    $all = $this->all();

    if (isset($all[$pluginId])) {
      return $all[$pluginId];
    }

    // A person is far more likely to say "vvjs" than "views_vvjs" — the module
    // name is what the project is called, the plugin id is an implementation
    // detail of Views. Accept either rather than refuse on a technicality.
    foreach ($all as $format) {
      if ($format->module === $pluginId) {
        return $format;
      }
    }

    return NULL;
  }

  /**
   * A definition value as plain text.
   *
   * Plugin definition values are `mixed`, and a title is routinely a
   * TranslatableMarkup rather than a string. Casting blind would fatal on an
   * array and silently produce "Array" on some values; this answers '' for
   * anything with no sensible string form.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The text, or '' when there is none.
   */
  private function text(mixed $value): string {
    if (is_string($value)) {
      return $value;
    }

    if ($value instanceof \Stringable) {
      return (string) $value;
    }

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * How many settings a format exposes.
   *
   * `defineOptions()` is protected and `->options` is empty until a view
   * initialises the plugin, so neither can be read from here — measured
   * 2026-09-04. The option definitions are reachable through the plugin's own
   * public form builder instead, which is also the only source carrying the
   * human labels a person needs.
   *
   * A count that cannot be taken returns 0 rather than throwing: a format whose
   * settings cannot be read is still a format worth naming in an answer, and a
   * fatal here would take down every tool in the module.
   *
   * @param string $pluginId
   *   Views style plugin id.
   *
   * @return int
   *   The number of settings, or 0 when they cannot be read.
   */
  private function countSettings(string $pluginId): int {
    try {
      $instance = $this->styleManager->createInstance($pluginId);
    }
    catch (\Throwable) {
      return 0;
    }

    // The options live behind a protected method. Reading them through the
    // plugin's own class scope is deliberate and contained: it asks the plugin
    // for its own declared options, changes nothing, and is the only route
    // Views leaves open from outside a view.
    $reader = \Closure::bind(
      static fn(VvjStylePluginBase $plugin): array => $plugin->defineOptions(),
      NULL,
      VvjStylePluginBase::class,
    );

    if (!$instance instanceof VvjStylePluginBase || $reader === NULL) {
      return 0;
    }

    try {
      return count($reader($instance));
    }
    catch (\Throwable) {
      return 0;
    }
  }

}
