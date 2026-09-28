<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Installs, uninstalls and reinstalls the module through the real installer.
 *
 * Maintainer, 2026-09-27: "you must test any config in install will not cause
 * any errors". The briefs in config/install are checked on each pass: the
 * installer throws nothing, they pass their schema, uninstall removes them,
 * and a second install is clean.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class InstallUninstallTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * Only what the installer cannot pull in by itself.
   *
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Core's uninstall clears user data for the module: the table must exist.
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system']);
  }

  /**
   * Install, uninstall, reinstall: no error, valid config, nothing stranded.
   */
  public function testInstallUninstallReinstall(): void {
    self::assertTrue($this->container->get('module_installer')->install(['vvj_core_ai']));
    $this->assertBriefsInstalledAndValid();

    $this->container->get('module_installer')->uninstall(['vvj_core_ai']);
    self::assertTrue($this->container->get('config.factory')->get('vvj_core_ai.briefs')->isNew(), 'The briefs are removed on uninstall.');

    self::assertTrue($this->container->get('module_installer')->install(['vvj_core_ai']));
    $this->assertBriefsInstalledAndValid();
  }

  /**
   * The briefs exist, pass their schema, and read back through the service.
   */
  private function assertBriefsInstalledAndValid(): void {
    $data = $this->container->get('config.storage')->read('vvj_core_ai.briefs');
    self::assertIsArray($data);
    self::assertTrue($this->checkConfigSchema($this->container->get('config.typed'), 'vvj_core_ai.briefs', $data));
    self::assertSame(5, $this->container->get('vvj_core_ai.briefs')->forFormat('views_vvjs')['count'] ?? NULL);
  }

}
