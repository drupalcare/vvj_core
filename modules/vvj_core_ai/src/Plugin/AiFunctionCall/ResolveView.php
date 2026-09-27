<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vvj_core_ai\Service\ViewPlannerInterface;
use Drupal\vvj_core_ai\Tool\SafeContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks whether a view can be created, and names what is still missing.
 *
 * THE ONE THING THIS MUST NOT DO IS BE OPTIMISTIC. Its job is to turn "make me
 * a slideshow of case studies" into five concrete values that exist on THIS
 * site, or into a question. A model asked to invent a field name will do it
 * confidently, and a view built on invented fields renders an empty box that
 * looks like a theme bug.
 *
 * IT ECHOES EVERY VALUE, and that is not padding. MEASURED in the sibling
 * module on 2026-09-03: `resolve_target` said "use exactly these values" and
 * then listed only some of them, so the model refilled the missing one from
 * the conversation and wrote to the wrong place. A tool that tells a model to
 * copy values has to print all of them.
 *
 * Reads only. `vvj_create_view` does the writing and re-checks all of this
 * itself, because a model may skip this tool entirely.
 *
 * @package Drupal\vvj_core_ai\Plugin\AiFunctionCall
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[FunctionCall(
  id: 'vvj_formats:resolve_view',
  function_name: 'vvj_resolve_view',
  name: 'Check what a new VVJ view needs',
  description: 'Checks whether a view using a VVJ display format can be created, against what actually exists on this site. Call this BEFORE vvj_create_view whenever anything is unstated or guessed. It returns either the exact values to pass on, or the questions to ask — never an invented field or content type. Reads only; it creates nothing.',
  group: 'vvj_formats',
  module_dependencies: ['vvj_core'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content kind'),
      description: 'Entity type to list, almost always "node". Omit for node.',
      required: FALSE,
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content type'),
      description: 'Machine name of the content type to list, e.g. article. Omit to list every content type — that is a real choice, not a missing answer.',
      required: FALSE,
    ),
    'format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('VVJ format'),
      description: 'The VVJ display format, e.g. vvjs for slideshow or vvja for accordion. Omit to be told which are installed here.',
      required: FALSE,
    ),
    'fields' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Fields'),
      description: 'Field machine names each item should show, in order, e.g. title, field_image. Omit to be told which exist on that content type.',
      required: FALSE,
    ),
    'label' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Name'),
      description: 'Human name for the view, e.g. "Featured case studies".',
      required: FALSE,
    ),
  ],
)]
final class ResolveView extends FunctionCallBase implements ExecutableFunctionCallInterface {

  use SafeContextTrait;
  use ViewArgumentsTrait;

  /**
   * Works out what is known and what is missing.
   */
  protected ViewPlannerInterface $planner;

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
    $instance->planner = $container->get(ViewPlannerInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    $plan = $this->planner->plan(
      $this->stringArgument('entity_type'),
      $this->stringArgument('bundle'),
      $this->stringArgument('format'),
      $this->listArgument('fields'),
      $this->stringArgument('label'),
    );

    if (!$plan->resolved) {
      $this->setOutput(implode("\n", array_merge(
        ['The view cannot be created yet. Ask the person about all of these together, in one message:', ''],
        array_map(static fn(string $q): string => '  - ' . $q, $plan->questions),
        [
          '',
          'Do not guess any of these. A field name or content type that does not exist here produces a view that renders nothing.',
        ],
      )));

      return;
    }

    // Every value, spelled out. See the class docblock — a partial echo is
    // what caused the model to refill a value from the conversation.
    $this->setOutput(implode("\n", [
      'Everything needed is known. Call vvj_create_view with exactly these values:',
      '',
      '  entity_type: ' . $plan->entityType,
      '  bundle:      ' . ($plan->bundle === '' ? '(none — lists every content type)' : $plan->bundle),
      '  format:      ' . $plan->format,
      '  fields:      ' . implode(', ', $plan->fields),
      '  label:       ' . $plan->label,
      '',
      sprintf('It will be created as "%s", DISABLED, so nothing appears on the site until the person enables it.', $plan->machineName),
      'Nothing has been created yet. This tool only checked.',
    ]));
  }

}
