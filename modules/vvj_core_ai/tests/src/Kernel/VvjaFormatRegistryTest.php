<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vvj_core_ai\Traits\VvjOptionalModulesTrait;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Discovery with exactly ONE format installed.
 *
 * WHY ONE AND NOT TEN. The registry's job is to report what a site has, not
 * what the family contains. Enabling all ten would pass equally against a
 * hardcoded list of ten; enabling exactly one proves the opposite — that nine
 * real, existing formats are correctly absent from the answer because this site
 * does not have them.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class VvjaFormatRegistryTest extends KernelTestBase {

  use VvjOptionalModulesTrait;

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
    'vvja',
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
    // This class deliberately uses the REAL vvja module rather than the
    // fixture, because its whole point is that the registry resolves an
    // actual shipped format.
    $this->skipUnlessModuleAvailable(
      'vvja',
      'The vvjtest fixture covers the same registry behaviour; this class additionally '
      . 'checks a real shipped format.',
    );

    parent::setUp();
    $this->installConfig(['system', 'filter']);

    $this->registry = \Drupal::service(FormatRegistryInterface::class);
  }

  /**
   * The one installed format is found, by base class rather than by name.
   */
  public function testTheInstalledFormatIsDiscovered(): void {
    $all = $this->registry->all();

    self::assertCount(1, $all, 'Exactly the one installed format: ' . implode(', ', array_keys($all)));
    self::assertArrayHasKey('views_vvja', $all);

    $format = $all['views_vvja'];
    self::assertSame('vvja', $format->module);
    self::assertNotSame('', $format->label);
  }

  /**
   * Its settings are COUNTED from the plugin, not guessed.
   *
   * `defineOptions()` is protected and `->options` is empty outside a view, so
   * a count above zero is the proof that the registry found a working route to
   * the plugin's own declarations rather than silently giving up.
   */
  public function testItsSettingsAreCountedFromThePlugin(): void {
    $format = $this->registry->all()['views_vvja'];

    self::assertGreaterThan(0, $format->settingCount,
      'A zero count means the settings could not be read at all.');
  }

  /**
   * The nine formats this site does NOT have are absent from the answer.
   *
   * They are real modules that exist in the family. Reporting them here would
   * be the exact failure this design prevents.
   */
  public function testTheNineUninstalledFormatsAreAbsent(): void {
    $all = $this->registry->all();

    foreach (['vvjb', 'vvjc', 'vvjf', 'vvjh', 'vvjl', 'vvjp', 'vvjr', 'vvjs', 'vvjt'] as $absent) {
      self::assertArrayNotHasKey('views_' . $absent, $all);
      self::assertNull($this->registry->get($absent), $absent . ' is not installed here.');
    }
  }

  /**
   * A person's name for it works as well as Views' name for it.
   *
   * People say "vvja"; Views wants "views_vvja". Refusing the first would be a
   * refusal on a technicality.
   */
  public function testEitherTheModuleNameOrThePluginIdResolves(): void {
    $byModule = $this->registry->get('vvja');
    $byPluginId = $this->registry->get('views_vvja');

    self::assertNotNull($byModule);
    self::assertNotNull($byPluginId);
    self::assertSame($byModule->pluginId, $byPluginId->pluginId);
  }

}
