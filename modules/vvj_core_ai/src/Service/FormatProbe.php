<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\views\Entity\View;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Default probe: a node view held in memory, never saved.
 */
final class FormatProbe implements FormatProbeInterface {

  /**
   * Constructs the probe.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $styleManager
   *   The Views style plugin manager.
   */
  public function __construct(
    private readonly PluginManagerInterface $styleManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function style(string $pluginId, array $options = []): StylePluginBase {
    $view = View::create([
      'id' => 'vvj_core_ai_readonly_probe',
      'label' => 'vvj_core_ai settings probe',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [],
        ],
      ],
    ]);
    $executable = $view->getExecutable();
    $executable->setDisplay('default');

    $style = $this->styleManager->createInstance($pluginId);

    if (!$style instanceof StylePluginBase) {
      throw new \RuntimeException(sprintf(
        'The "%s" style plugin is not a Views style plugin.',
        $pluginId,
      ));
    }

    $style->init($executable, $executable->display_handler, $options);

    return $style;
  }

}
