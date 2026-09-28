<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Discovery by base class, and the two states a real site can be in.
 *
 * WHY NO FORMAT MODULE IS ENABLED HERE. The point of the registry is that it
 * carries no list of format names, so the test that matters most is the one
 * with nothing installed: vvj_core alone must return an empty registry rather
 * than the ten formats the family contains. A test that enabled all ten would
 * pass just as well against a hardcoded array, and would prove nothing about
 * the design.
 *
 * The populated case is covered by VvjaFormatRegistryTest, which enables one
 * real format — one is enough to prove discovery works, and using exactly one
 * also proves the registry reports what is present rather than what exists.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class FormatRegistryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'views',
    'vvj_core',
    // The drafter takes drupal/ai's provider manager, and the module declares
    // ai:ai, so the container cannot compile without it.
    'key',
    'ai',
    'vvj_core_ai',
  ];

  /**
   * The service under test.
   */
  private FormatRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'filter']);

    $this->registry = \Drupal::service(FormatRegistryInterface::class);
  }

  /**
   * With no format module installed, the registry is empty — not the family.
   *
   * This is the test the whole design rests on. A registry holding a list of
   * the ten VVJ formats would answer this wrongly and confidently, describing
   * software the site does not have.
   */
  public function testWithNoFormatModuleTheRegistryIsEmpty(): void {
    self::assertSame([], $this->registry->all());
  }

  /**
   * An unknown format is NULL rather than an invention.
   */
  public function testAnUnknownFormatIsNull(): void {
    self::assertNull($this->registry->get('views_vvjs'));
    self::assertNull($this->registry->get('vvjs'));
    self::assertNull($this->registry->get('not_a_format'));
  }

  /**
   * Core's own Views styles are never mistaken for VVJ formats.
   *
   * `views` ships `default`, `html_list`, `table` and more. The filter is by
   * VvjStylePluginBase, so none of them may appear — a looser filter, such as
   * matching on an id prefix, would be quietly wrong the first time another
   * project shipped a plugin whose name began the same way.
   */
  public function testCoreViewsStylesAreNotTreatedAsVvjFormats(): void {
    $manager = \Drupal::service('plugin.manager.views.style');
    $all = array_keys($manager->getDefinitions());

    self::assertNotEmpty($all, 'Views must have registered its own styles.');
    self::assertContains('default', $all);
    self::assertSame([], $this->registry->all(), 'None of core\'s styles is a VVJ format.');
  }

}
