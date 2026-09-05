<?php

declare(strict_types=1);

namespace Drupal\vvjtest\Plugin\views\style;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\vvj_core\Plugin\views\style\VvjStylePluginBase;

/**
 * A minimal VVJ format that exists only for these tests.
 *
 * WHY THIS EXISTS. The tests need *a* VVJ format to discover, describe, plan
 * against and build a view with. They originally used `vvja`, which is a
 * SEPARATE drupal.org project — fine on a development machine where every VVJ
 * module is checked out, and absent on drupal.org CI, where vvj_core is built
 * from its own composer.json alone. Measured 2026-09-05: five CI jobs went red
 * on exactly that, and nothing locally could have shown it, because locally
 * vvja is always there.
 *
 * Adding drupal/vvja to require-dev would have been the quicker fix and the
 * wrong one: it makes the foundation module's test suite depend on one of its
 * own dependents, and it would have to be repeated for every sibling.
 *
 * It follows the family's naming convention — module `vvjtest` provides plugin
 * id `views_vvjtest` — because the registry resolves both forms and a fixture
 * that broke the convention would not exercise that.
 *
 * @ingroup views_style_plugins
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[ViewsStyle(
  id: 'views_vvjtest',
  title: new TranslatableMarkup('VVJ test format'),
  help: new TranslatableMarkup('A VVJ format that exists only so the tests have one.'),
  theme: 'views_view_vvjtest',
  display_types: ['normal'],
)]
final class VvjTestStyle extends VvjStylePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getModuleSlug(): string {
    return 'vvjtest';
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomElementTag(): string {
    return 'vvj-test';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $options
   *   The options being defined.
   *
   * @return array<string, mixed>
   *   The options, with this pattern's own added.
   */
  protected function definePatternOptions(array $options): array {
    // Two settings, deliberately: one plain and one inside a group, so the
    // settings reader is exercised on both shapes rather than only the easy
    // one.
    $options['test_label'] = ['default' => 'Test'];
    $options['test_speed'] = ['default' => 300];
    $options['test_position'] = ['default' => 'top'];

    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The options form.
   */
  protected function buildPatternSections(array &$form): void {
    $form['test_label'] = [
      '#type' => 'textfield',
      '#title' => new TranslatableMarkup('Test label'),
      '#description' => new TranslatableMarkup('A plain setting, at the top level of the form.'),
      '#default_value' => $this->options['test_label'] ?? 'Test',
    ];

    $form['test_group'] = [
      '#type' => 'details',
      '#title' => new TranslatableMarkup('Behaviour settings'),
      'test_speed' => [
        '#type' => 'number',
        '#title' => new TranslatableMarkup('Speed'),
        '#description' => new TranslatableMarkup('A setting nested inside a group, in milliseconds.'),
        '#default_value' => $this->options['test_speed'] ?? 300,
      ],
      // A select, so the reader is exercised on a setting that carries a fixed
      // list of allowed values — the third shape, and the one a person most
      // often needs spelled out when asking what a setting accepts.
      'test_position' => [
        '#type' => 'select',
        '#title' => new TranslatableMarkup('Position'),
        '#description' => new TranslatableMarkup('Where the control sits.'),
        '#options' => [
          'top' => new TranslatableMarkup('Top'),
          'bottom' => new TranslatableMarkup('Bottom'),
        ],
        '#default_value' => $this->options['test_position'] ?? 'top',
      ],
    ];
  }

}
