<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vvj_core_ai\Model\ViewFinding;
use Drupal\vvj_core_ai\Service\ViewDoctorInterface;
use Drupal\vvj_core_ai\Tool\SafeContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Says why a view using a VVJ format is not showing what was expected.
 *
 * THE ANSWER A PERSON CANNOT GET BY LOOKING. A blank box on the page has
 * several causes that are indistinguishable from the front end — no fields
 * configured, no content matching the query, a disabled display — and each has
 * a different fix. A model asked "why is my view empty?" without this will
 * offer the most common cause and be wrong most of the time.
 *
 * Reads only. The view is loaded and run; nothing about it is changed.
 *
 * @package Drupal\vvj_core_ai\Plugin\AiFunctionCall
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[FunctionCall(
  id: 'vvj_formats:diagnose_view',
  function_name: 'vvj_diagnose_view',
  name: 'Diagnose a view using a VVJ format',
  description: 'Checks one view that uses a VVJ display format and reports what is actually wrong — no fields, no matching content, a disabled view, or settings the format no longer has. Call this instead of guessing why a view looks empty: the causes are indistinguishable from the page itself and each has a different fix. Omit the view to list the views that use a VVJ format. Reads only; it changes nothing.',
  group: 'vvj_formats',
  module_dependencies: ['vvj_core'],
  context_definitions: [
    'view' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('The view'),
      description: 'Machine name of the view, e.g. featured_work. Omit to list every view on this site that uses a VVJ format.',
      required: FALSE,
    ),
    'display' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('The display'),
      description: 'Display id, e.g. block_1 or page_1. Omit to check the first display that uses a VVJ format.',
      required: FALSE,
    ),
  ],
)]
final class DiagnoseView extends FunctionCallBase implements ExecutableFunctionCallInterface {

  use SafeContextTrait;

  /**
   * Runs the checks.
   */
  protected ViewDoctorInterface $doctor;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->doctor = $container->get(ViewDoctorInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    $viewId = $this->string('view');

    if ($viewId === '') {
      $this->setOutput($this->listViews());

      return;
    }

    $findings = $this->doctor->diagnose($viewId, $this->string('display') ?: NULL);

    if ($findings === NULL) {
      $this->setOutput(implode("\n", [
        sprintf('There is no view called "%s" on this site.', $viewId),
        'Check the machine name, or call this tool with no view to list the ones using a VVJ format.',
      ]));

      return;
    }

    $lines = [sprintf('Checked %s:', $viewId), ''];
    $broken = 0;

    foreach ($findings as $finding) {
      $lines[] = '  ' . $finding->line();

      if ($finding->severity === ViewFinding::BROKEN) {
        $broken++;
      }
    }

    $lines[] = '';
    $lines[] = $broken === 0
      // Saying this explicitly matters: a list of OK lines with no verdict
      // invites the reader to keep hunting for a problem that is not there.
      ? 'Nothing here explains a display problem. If it still looks wrong, the cause is in the theme or the content rather than the view.'
      : sprintf('%d thing(s) marked BROKEN above will stop this rendering. Fix those first.', $broken);

    $lines[] = 'Every fix above is made by hand in the Views UI. This tool changes nothing.';

    $this->setOutput(implode("\n", $lines));
  }

  /**
   * Every view on the site using a VVJ format.
   *
   * @return string
   *   The list, or a plain statement that there are none.
   */
  private function listViews(): string {
    $views = $this->doctor->usingVvj();

    if ($views === []) {
      return implode("\n", [
        'No view on this site uses a VVJ display format.',
        'That is not a fault — the formats are installed but nothing has been set to use one yet. A view picks its format under Format in the Views UI.',
      ]);
    }

    return implode("\n", array_merge(
      [sprintf('%d view display(s) use a VVJ format:', count($views))],
      array_map(static fn(string $v): string => '  ' . $v, $views),
      ['', 'Call this again with one of these view names to have it checked.'],
    ));
  }

  /**
   * A context value as a trimmed string.
   *
   * @param string $name
   *   The context name.
   *
   * @return string
   *   The value, or '' when absent.
   */
  private function string(string $name): string {
    $value = $this->contextValue($name);

    return is_string($value) ? trim($value) : '';
  }

}
