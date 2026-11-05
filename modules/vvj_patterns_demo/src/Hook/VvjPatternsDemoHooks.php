<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns_demo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\ViewExecutable;

/**
 * Puts the demo views on their own full-width stage.
 *
 * Only the views this module ships (machine names starting "vvj_demo_") get
 * the stage; the Pattern modules never depend on it.
 */
final class VvjPatternsDemoHooks {

  /**
   * Implements hook_preprocess_HOOK() for views_view.
   *
   * @param array<string, mixed> $variables
   *   Template variables.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    if (!($view instanceof ViewExecutable) || !str_starts_with((string) $view->id(), 'vvj_demo_')) {
      return;
    }
    $variables['attributes']['class'][] = 'vvj-demo-stage';
    $variables['#attached']['library'][] = 'vvj_patterns_demo/stage';
  }

}
