<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vvj_core_ai\Traits\VvjOptionalModulesTrait;
use Drupal\vvj_core_ai\Service\BriefsInterface;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use Drupal\vvj_core_ai\Service\RowRulesInterface;
use Drupal\vvj_core_ai\Service\ViewFeaturesInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The row rule and the features, against the ten real formats.
 *
 * The formats are separate projects, so this skips where they are absent;
 * the vvjtest fixture covers the creator on its own.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class RealFormatsTest extends KernelTestBase {

  use VvjOptionalModulesTrait;

  /**
   * The ten formats, by module.
   */
  private const FORMATS = ['vvja', 'vvjb', 'vvjc', 'vvjf', 'vvjh', 'vvjl', 'vvjp', 'vvjr', 'vvjs', 'vvjt'];

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
    'text',
    'file',
    'image',
    'node',
    'views',
    // A format's settings form links to the Views UI edit route; without it
    // the form cannot be built, and every feature reads as missing.
    'views_ui',
    'vvj_core',
    'vvja',
    'vvjb',
    'vvjc',
    'vvjf',
    'vvjh',
    'vvjl',
    'vvjp',
    'vvjr',
    'vvjs',
    'vvjt',
    'key',
    'ai',
    'vvj_core_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    foreach (self::FORMATS as $module) {
      $this->skipUnlessModuleAvailable($module, 'The row rule and the features are checked against every real format.');
    }

    parent::setUp();
    $this->installConfig(['system', 'filter', 'vvj_core_ai']);
  }

  /**
   * Exactly the formats that opt out of fields keep a view mode.
   *
   * Measured 2026-09-27 from the code: Basic Carousel and 3D Carousel opt out
   * always, Slideshow outside hero mode. Every other format forces fields.
   */
  public function testTheRowRuleIsWhatEachFormatDoes(): void {
    $rules = $this->container->get(RowRulesInterface::class);
    $viewMode = [];

    foreach ($this->container->get(FormatRegistryInterface::class)->all() as $format) {
      if ($rules->allowsViewMode($format->pluginId)) {
        $viewMode[] = $format->pluginId;
      }
    }

    sort($viewMode);
    self::assertSame(['views_vvjb', 'views_vvjc', 'views_vvjs'], $viewMode);
    self::assertFalse($rules->allowsViewMode('views_vvjs', ['hero_slideshow' => TRUE]), 'A hero slideshow needs fields.');
  }

  /**
   * Every format has a brief, and not one feature in it is dropped.
   *
   * A feature is dropped when it names no real setting or a value the
   * setting cannot hold — a typo — and a dropped feature is silently not
   * switched on. So the check is: what the brief lists is what survives.
   */
  public function testEveryFeatureIsRealSettingValue(): void {
    $briefs = $this->container->get(BriefsInterface::class);
    $features = $this->container->get(ViewFeaturesInterface::class);
    $problems = [];
    $checked = 0;

    foreach ($this->container->get(FormatRegistryInterface::class)->all() as $format) {
      $brief = $briefs->forFormat($format->pluginId);

      if ($brief === NULL) {
        $problems[] = $format->pluginId . ': no brief';
        continue;
      }

      $kept = $features->valuesFor($format->pluginId);

      foreach (array_keys($brief['features']) as $name) {
        $checked++;

        if (!array_key_exists($name, $kept)) {
          $problems[] = sprintf('%s.%s = "%s" is not a real setting value', $format->pluginId, $name, $brief['features'][$name]);
        }
      }
    }

    self::assertSame([], $problems);
    self::assertGreaterThan(40, $checked, 'The features were found.');
  }

}
