<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\vvj_core_ai\Service\SettingsReaderInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reading a format's settings out of its own settings form.
 *
 * WHY THE NESTING TEST IS THE IMPORTANT ONE. A VVJ format's settings form is
 * not flat: measured on sts.local 2026-09-04, VVJS returns fourteen top-level
 * keys of which nine are `details` groups, with the real fields inside them. A
 * reader that did not recurse would find two settings out of twenty-eight and
 * report the other twenty-six as absent — confidently, and in a form a person
 * would act on.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class SettingsReaderTest extends KernelTestBase {

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
    'node',
    'views',
    // `views_ui` is required, and that is a finding rather than
    // test scaffolding.
    // Some VVJ formats build a link to the view's edit form inside their own
    // settings form, which needs the `entity.view.edit_form` route. Without it
    // the whole form build throws and NO settings can be read — measured
    // 2026-09-04. testInstalledButUnreadableIsNotTheSameAsNoSettings below pins
    // the distinction that limitation creates.
    'views_ui',
    'vvj_core',
    'vvjtest',
    // The drafter takes drupal/ai's provider manager, and the module declares
    // ai:ai, so the container cannot compile without it.
    'key',
    'ai',
    'vvj_core_ai',
  ];

  /**
   * The service under test.
   */
  private SettingsReaderInterface $reader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'filter', 'node']);

    $this->reader = \Drupal::service(SettingsReaderInterface::class);
  }

  /**
   * Settings nested inside form groups are found, not just the top level.
   *
   * The count is asserted as "more than a handful" rather than an exact
   * number: the exact figure belongs to the format module and may legitimately
   * change, but a reader that stopped at the top level would return about two,
   * and that is the regression worth catching.
   */
  public function testNestedSettingsAreFound(): void {
    $settings = $this->reader->read('vvjtest');

    self::assertIsArray($settings);
    self::assertGreaterThan(5, count($settings),
      'A top-level-only read returns about two settings; this must find the nested ones.');
  }

  /**
   * Each setting carries the group it appears under on the form.
   *
   * A flat list of eighteen machine names is a worse answer than a grouped
   * one, because the form itself is grouped and that is how a person looks.
   */
  public function testSettingsCarryTheirFormSection(): void {
    $settings = $this->reader->read('vvjtest') ?? [];
    $sections = [];

    foreach ($settings as $setting) {
      if ($setting->section !== '') {
        $sections[$setting->section] = TRUE;
      }
    }

    self::assertGreaterThan(1, count($sections),
      'The settings form has several groups; they must survive the read.');
  }

  /**
   * A select's allowed values are captured — the part a model invents.
   *
   * This is the whole reason the form is the source rather than
   * defineOptions(): a plausible-but-wrong value gets typed into a settings
   * form, saved, and silently does nothing.
   */
  public function testSelectSettingsCarryTheirAllowedValues(): void {
    $settings = $this->reader->read('vvjtest') ?? [];
    $withChoices = array_filter($settings, static fn($s) => $s->choices !== []);

    self::assertNotEmpty($withChoices, 'At least one setting is a select with fixed values.');

    foreach ($withChoices as $setting) {
      self::assertSame('select', $setting->type === 'radios' ? 'select' : $setting->type,
        'Only choice-style elements should carry choices.');
      foreach ($setting->choices as $choice) {
        self::assertIsString($choice);
      }
    }
  }

  /**
   * Containers are not reported as settings.
   *
   * `details` and `fieldset` group other elements; listing them as settings
   * would tell a person to set a value on something that holds none.
   */
  public function testFormContainersAreNotReportedAsSettings(): void {
    foreach ($this->reader->read('vvjtest') ?? [] as $setting) {
      self::assertNotContains($setting->type, ['details', 'fieldset', 'container', 'actions'],
        $setting->name . ' is a container, not a setting.');
    }
  }

  /**
   * An uninstalled format is NULL, which is not the same as no settings.
   *
   * NULL means "this site does not have it"; an empty array means "it is here
   * but unreadable". A caller that conflated them would tell someone a real
   * format has no settings.
   */
  public function testAnUninstalledFormatIsNullNotEmpty(): void {
    self::assertNull($this->reader->read('vvjs'), 'vvjs is not installed in this test.');
    self::assertNull($this->reader->read('views_vvjs'));
    self::assertNull($this->reader->read('nonsense'));
  }

  /**
   * The empty result means "unreadable", never "this format has no settings".
   *
   * An empty array and NULL are different answers and a caller must not merge
   * them: NULL is "not installed here", [] is "installed, but its form could
   * not be built". The second happens for real — a format whose settings form
   * links to the Views UI cannot be read on a site with Views UI disabled,
   * which is a normal production configuration.
   */
  public function testInstalledButUnreadableIsNotTheSameAsNoSettings(): void {
    $installed = $this->reader->read('vvjtest');
    $absent = $this->reader->read('vvjs');

    self::assertIsArray($installed, 'vvjtest is installed, so never NULL.');
    self::assertNull($absent, 'vvjs is not installed, so never [].');
    // Not assertNotSame($installed, $absent): an array is never identical to
    // null, so that assertion could not fail and tested nothing. The real
    // claim is that empty-and-absent are different answers.
    self::assertNotEmpty($installed, 'An installed format reports its settings.');
  }

  /**
   * Reading settings writes nothing — no view is left behind.
   *
   * The reader builds a view in memory to get at the form. If that ever got
   * saved, this module would be writing config, which is the one thing the
   * brief forbids outright.
   */
  public function testReadingLeavesNoViewBehind(): void {
    $storage = \Drupal::service('entity_type.manager')->getStorage('view');
    $before = count($storage->loadMultiple());

    $this->reader->read('vvjtest');
    $this->reader->read('vvjtest');

    $storage->resetCache();
    self::assertCount($before, $storage->loadMultiple(),
      'The probe view must never be saved.');
    self::assertNull($storage->load('vvj_core_ai_readonly_probe'));
  }

}
