<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Traits;

use Drupal\Core\Extension\ExtensionDiscovery;

/**
 * Skips a test class when a module it needs is not on this site.
 *
 * TWO MODULES ARE OUTSIDE THIS PROJECT and cannot be listed in composer.json:
 *
 *   - `ai` is a dependency of the vvj_core_ai submodule, not of vvj_core.
 *   - `vvja` is a separate drupal.org project — one of vvj_core's own
 *     dependents, which the foundation must not depend back on.
 *
 * Both are present on a development machine and absent on drupal.org CI, which
 * builds vvj_core from its own composer.json alone. MEASURED 2026-09-05: five
 * CI jobs went red on exactly that, and no local run could have shown it.
 *
 * WHY ExtensionDiscovery AND NOT THE CONTAINER. The check has to happen BEFORE
 * `parent::setUp()`, because that is what installs `$modules` and what fails
 * when one is missing. `$this->container` does not exist yet at that point —
 * measured, by writing it the other way first and getting ten
 * "Call to a member function get() on null" errors.
 *
 * A skip is not free and is not a pass. It is used only where the alternative
 * is a class that cannot run at all; everything that can be covered by the
 * `vvjtest` fixture module is covered by it instead, so it runs everywhere.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
trait VvjOptionalModulesTrait {

  /**
   * Skips the test when a module is not available to install.
   *
   * @param string $module
   *   The module machine name.
   * @param string $because
   *   What cannot be checked without it, and where it can be checked instead.
   */
  protected function skipUnlessModuleAvailable(string $module, string $because): void {
    static $available = NULL;

    if ($available === NULL) {
      // FALSE disables the file cache. It is not an optimisation choice: the
      // cache needs a prefix that FileCacheFactory only has once a site has
      // booted, and this runs before parent::setUp(). Measured — with the
      // default it throws "Required prefix configuration is missing".
      $discovery = new ExtensionDiscovery(DRUPAL_ROOT, FALSE);
      $available = $discovery->scan('module');
    }

    if (!isset($available[$module])) {
      $this->markTestSkipped(sprintf('%s is not installed. %s', $module, $because));
    }
  }

}
