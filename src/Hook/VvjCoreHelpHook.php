<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\filter\FilterPluginManager;

/**
 * Help hook for vvj_core — renders README.md on the help page.
 *
 * If the contrib `markdown` module is enabled, runs the README through
 * its filter for nicely-rendered help. Otherwise falls back to a `<pre>`
 * block with HTML-escaped contents.
 */
final class VvjCoreHelpHook {

  use StringTranslationTrait;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   Resolves the module's path on disk (where README.md lives).
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   For checking whether the optional `markdown` filter module is enabled.
   * @param \Drupal\filter\FilterPluginManager $filterManager
   *   For instantiating the markdown filter when available.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   For loading markdown.settings when the filter is enabled.
   */
  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FilterPluginManager $filterManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_help() for the vvj_core help route.
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.vvj_core') {
      return NULL;
    }
    return $this->renderReadme();
  }

  /**
   * Render the module's README.md as HTML.
   */
  private function renderReadme(): string {
    $module_path = $this->moduleExtensionList->getPath('vvj_core');
    $readme_path = $module_path . '/README.md';
    $text = @file_get_contents($readme_path);

    if ($text === FALSE) {
      return (string) $this->t('README.md file not found.');
    }

    if (!$this->moduleHandler->moduleExists('markdown')) {
      return '<pre>' . Html::escape($text) . '</pre>';
    }

    $settings = $this->configFactory->get('markdown.settings')->getRawData();
    /** @var \Drupal\filter\Plugin\FilterInterface $filter */
    $filter = $this->filterManager->createInstance('markdown', ['settings' => $settings]);
    return $filter->process($text, 'en')->getProcessedText();
  }

}
