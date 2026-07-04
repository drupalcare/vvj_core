<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Theme hook implementation for vvj_core.
 *
 * Vvj_core declares no theme hooks of its own — pattern modules each
 * declare their `views_view_vvjX` and `views_view_vvjX_fields`
 * templates. This class is a placeholder for any future shared
 * partials that pattern modules might want to include from a central
 * registry (e.g., a shared "loading" indicator or empty-state
 * partial). Empty by design.
 */
final class VvjCoreThemeHook {

  /**
   * Implements hook_theme().
   *
   * @param array<string, mixed> $existing
   *   Existing theme definitions from prior modules.
   * @param string $type
   *   Extension type.
   * @param string $theme
   *   Theme machine name.
   * @param string $path
   *   Theme path.
   *
   * @return array<string, array<string, mixed>>
   *   Theme registry entries. Empty for now.
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return [];
  }

}
