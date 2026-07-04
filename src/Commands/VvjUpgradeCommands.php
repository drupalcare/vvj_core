<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Commands;

use Drupal\vvj_core\Service\VvjUpgradeAuditor;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the VVJ v2 upgrade path.
 *
 * `drush vvj:upgrade` audits every View that uses a VVJ style plugin and
 * reports option-key drift between the installed configuration and the
 * current schema. Run it after updating a site from v1 to v2 to confirm
 * every VVJ view is recognized and on the current schema. The v2.0 drop-in
 * upgrade has NO breaking renames, so it reports "no migration needed" for
 * every v1 view.
 *
 * All scan/drift logic lives in the Drush-independent
 * \Drupal\vvj_core\Service\VvjUpgradeAuditor (unit-tested); this class is a
 * thin presentation wrapper.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
final class VvjUpgradeCommands extends DrushCommands {

  /**
   * Constructs a VvjUpgradeCommands instance.
   *
   * @param \Drupal\vvj_core\Service\VvjUpgradeAuditor $auditor
   *   Finds VVJ views and reports schema drift.
   */
  public function __construct(
    private readonly VvjUpgradeAuditor $auditor,
  ) {
    parent::__construct();
  }

  /**
   * Audit + migrate v1 VVJ views to the current v2 schema.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int
   *   A Drush exit code.
   */
  #[CLI\Command(name: 'vvj:upgrade')]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Report drift without applying any changes.',
  )]
  #[CLI\Option(
    name: 'module',
    description: 'Comma-separated pattern slugs to limit the scan (e.g. vvja,vvjs). Default: all.',
  )]
  #[CLI\Usage(
    name: 'drush vvj:upgrade --dry-run',
    description: 'Report any VVJ views that need migration; make no changes.',
  )]
  public function upgrade(array $options = ['dry-run' => FALSE, 'module' => '']): int {
    $rawModule = $options['module'] ?? '';
    $slugs = $this->parseSlugs(is_string($rawModule) ? $rawModule : '');
    $views = $this->auditor->findVvjViews($slugs);

    $this->io()->title('VVJ v2 upgrade audit');

    if ($views === []) {
      $this->io()->success('No VVJ views found — nothing to migrate.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->text(
      sprintf('Scanned %d view(s) using VVJ style plugins.', count($views)),
    );

    /** @var array<string, list<string>> $drift */
    $drift = [];
    foreach ($views as $viewId => $plugins) {
      // Recursive Domain Guard: view config IDs are always strings.
      if (!is_string($viewId)) {
        continue;
      }
      $items = $this->auditor->detectDrift($viewId, $plugins);
      if ($items !== []) {
        $drift[$viewId] = $items;
      }
    }

    if ($drift === []) {
      $this->io()->success(
        'No migration needed — all VVJ views are on the v2.0 schema.',
      );
      return self::EXIT_SUCCESS;
    }

    // Should a view ever report drift, list it, then prompt to apply.
    foreach ($drift as $viewId => $items) {
      // Recursive Domain Guard: view config IDs are always strings.
      if (!is_string($viewId)) {
        continue;
      }
      $this->io()->section($viewId);
      $this->io()->listing($items);
    }

    if (!empty($options['dry-run'])) {
      $this->io()->note('Dry run — no changes applied.');
      return self::EXIT_SUCCESS;
    }

    if (!$this->io()->confirm('Apply the changes above?', FALSE)) {
      $this->io()->warning('Aborted — no changes applied.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->success('Migration complete.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Parse the --module option into a list of pattern slugs.
   *
   * @param string $raw
   *   Comma-separated slugs, e.g. "vvja,vvjs".
   *
   * @return list<string>
   *   Normalized, non-empty slugs.
   */
  private function parseSlugs(string $raw): array {
    /** @var list<string> $slugs */
    $slugs = [];
    foreach (explode(',', $raw) as $part) {
      $part = trim($part);
      if ($part !== '') {
        $slugs[] = $part;
      }
    }
    return $slugs;
  }

}
