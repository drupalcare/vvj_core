<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Template\Attribute;
use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;

/**
 * Shared preprocess primitives for vvj_core.
 *
 * Adds the `vvj-component` class to every Views wrapper that's using
 * a VVJ Style plugin. Pattern modules can rely on the `.vvj-component`
 * selector for global VVJ styling (e.g., the focus-ring tokens in
 * `vvj-a11y.css` apply to every VVJ pattern via this class).
 *
 * Pattern modules add their pattern-specific class
 * (`.vvj-accordion`, `.vvj-slideshow`, etc.) in their own preprocess
 * — vvj_core only adds the universal class.
 */
final class VvjCorePreprocessHook {

  /**
   * Implements hook_preprocess_HOOK() for views_view.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    if (!isset($variables['view'])) {
      return;
    }
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $variables['view'];

    $style = $view->style_plugin;
    if (!($style instanceof VvjStylePluginBase)) {
      return;
    }

    $attributes = $variables['attributes'] ?? NULL;
    if ($attributes instanceof Attribute) {
      $attributes->addClass('vvj-component');
      $attributes->addClass('vvj-' . $style->getModuleSlug());
    }
  }

}
