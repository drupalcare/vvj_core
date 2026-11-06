<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\vvj_patterns\Theme\VvjThemeBridgeInterface;

/**
 * Adds the active theme's bridge libraries to the Patterns base libraries.
 *
 * Library definitions are cached per active theme, so the bridge chosen here
 * is the one for the theme that renders the page. The neutral bridge adds
 * nothing: black and white stays the default.
 */
final class VvjPatternsLibraryInfoAlterHook {

  public function __construct(
    private readonly VvjThemeBridgeInterface $themeBridge,
  ) {}

  /**
   * Implements hook_library_info_alter().
   *
   * @param array<string, array<string, mixed>> $libraries
   *   Library definitions for the extension being altered.
   * @param string $extension
   *   Module or theme extension name.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    $id = $this->themeBridge->resolveBridgeId();
    if ($id === VvjThemeBridgeInterface::BRIDGE_NEUTRAL) {
      return;
    }

    // The shared bridge: maps the --vvj-* colour tokens every Pattern reads.
    if ($extension === 'vvj_patterns') {
      $this->addDependency($libraries, 'base', 'vvj_patterns/theme-bridge-' . $id);
      return;
    }

    // A VVJ module's own bridge, when it ships one for this theme.
    $base = $extension . '-pattern-base';
    $bridge = 'pattern-bridge-' . $id;
    if (isset($libraries[$base], $libraries[$bridge])) {
      $this->addDependency($libraries, $base, $extension . '/' . $bridge);
    }
  }

  /**
   * Appends a dependency to a library once.
   *
   * @param array<string, array<string, mixed>> $libraries
   *   Library definitions for the extension being altered.
   * @param string $library
   *   The library to add the dependency to.
   * @param string $dependency
   *   The full library name, extension/library.
   */
  private function addDependency(array &$libraries, string $library, string $dependency): void {
    if (!isset($libraries[$library])) {
      return;
    }
    $dependencies = $libraries[$library]['dependencies'] ?? [];
    if (!is_array($dependencies)) {
      $dependencies = [];
    }
    if (!in_array($dependency, $dependencies, TRUE)) {
      $dependencies[] = $dependency;
    }
    $libraries[$library]['dependencies'] = $dependencies;
  }

}
