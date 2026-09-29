<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The post update removes what the retired chat tools left, and only that.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class RetiredChatConfigTest extends KernelTestBase {

  /**
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
    $this->installConfig(['system']);
    // Through the real installer: dependencies arrive as on a site.
    $this->container->get('module_installer')->install(['vvj_core_ai']);
    require_once dirname(__DIR__, 3) . '/vvj_core_ai.post_update.php';
  }

  /**
   * The old settings go; the module's own briefs stay; a second run is safe.
   */
  public function testTheOldSettingsAreRemovedAndNothingElse(): void {
    // Written to storage directly, as it sits on an old site: its schema went
    // with the chat tools, so a normal save would be refused by the checker.
    $this->container->get('config.storage')->write('vvj_core_ai.settings', ['assistant' => '']);
    $factory = $this->container->get('config.factory');
    $factory->reset('vvj_core_ai.settings');
    self::assertFalse($factory->get('vvj_core_ai.settings')->isNew());

    $message = vvj_core_ai_post_update_remove_chat_tools_config();

    self::assertStringContainsString('vvj_core_ai.settings', $message);
    self::assertTrue($factory->get('vvj_core_ai.settings')->isNew(), 'The retired settings are gone.');
    self::assertFalse($factory->get('vvj_core_ai.briefs')->isNew(), 'The module\'s current config is untouched.');
    self::assertStringContainsString('Nothing to remove', vvj_core_ai_post_update_remove_chat_tools_config());
  }

}
