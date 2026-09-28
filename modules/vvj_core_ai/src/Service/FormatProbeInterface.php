<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Builds a format's style plugin against a throwaway, never-saved view.
 *
 * ONE OWNER FOR THE PROBE. Reading a format's settings form and asking a
 * format which row it needs both have to construct the plugin the way Views
 * does; two copies of that construction would drift apart.
 */
interface FormatProbeInterface {

  /**
   * A format's style plugin, initialised with the given options.
   *
   * @param string $pluginId
   *   Views style plugin id, such as `views_vvjs`.
   * @param array<string, mixed> $options
   *   Options to initialise it with; the format's defaults fill the rest.
   *
   * @return \Drupal\views\Plugin\views\style\StylePluginBase
   *   The initialised plugin.
   *
   * @throws \RuntimeException
   *   When the id is not a Views style plugin.
   */
  public function style(string $pluginId, array $options = []): StylePluginBase;

}
