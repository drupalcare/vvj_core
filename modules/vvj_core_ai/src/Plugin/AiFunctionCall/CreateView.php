<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vvj_core_ai\Service\ViewCreatorInterface;
use Drupal\vvj_core_ai\Tool\SafeContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a view that uses a VVJ format. The only tool here that writes.
 *
 * THE THREE RULES IT ENFORCES, none of them configurable:
 *
 * 1. The view is created DISABLED. Someone looks at it before a visitor does.
 * 2. An existing view is never touched. A name in use is a refusal.
 * 3. Every precondition is re-checked here, through the same planner
 *    `vvj_resolve_view` uses. It must never assume that tool was called,
 *    because a model can skip it — and a creator that trusted its caller
 *    would build a view against a content type that does not exist.
 *
 * The format's own settings are left at their defaults, deliberately. Those
 * are the site builder's to set: a machine rewriting someone's configured
 * slideshow speed is the failure this module exists to avoid.
 *
 * @package Drupal\vvj_core_ai\Plugin\AiFunctionCall
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[FunctionCall(
  id: 'vvj_formats:create_view',
  function_name: 'vvj_create_view',
  name: 'Create a view using a VVJ format',
  description: 'Creates a new, DISABLED view that lists content using a VVJ display format. Call vvj_resolve_view first unless every value is already known to exist on this site — this refuses rather than guesses, and never modifies an existing view. It sets the format, the fields and a published-only filter; the format\'s own settings are left at their defaults for the site builder to choose.',
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
      description: 'Machine name of the content type to list, e.g. article. Omit to list every content type.',
      required: FALSE,
    ),
    'format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('VVJ format'),
      description: 'The VVJ display format to use, e.g. vvjs or views_vvjs. Must be installed on this site.',
      required: FALSE,
    ),
    'fields' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Fields'),
      description: 'Field machine names each item shows, in display order, e.g. title, field_image. They must exist on that content type.',
      required: FALSE,
    ),
    'label' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Name'),
      description: 'Human name for the view, e.g. "Featured case studies". The machine name is derived from it.',
      required: FALSE,
    ),
  ],
)]
final class CreateView extends FunctionCallBase implements ExecutableFunctionCallInterface {

  use SafeContextTrait;
  use ViewArgumentsTrait;

  /**
   * The only service in this module that writes.
   */
  protected ViewCreatorInterface $creator;

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
    $instance->creator = $container->get(ViewCreatorInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $result = $this->creator->create(
      $this->stringArgument('entity_type'),
      $this->stringArgument('bundle'),
      $this->stringArgument('format'),
      $this->listArgument('fields'),
      $this->stringArgument('label'),
    );

    if ($result['created'] !== TRUE) {
      $this->setOutput(implode("\n", array_merge(
        ['No view was created. These have to be settled first:', ''],
        array_map(static fn(string $r): string => '  - ' . $r, $result['refusals']),
        [
          '',
          'Nothing on the site was changed. Ask the person about all of the above together, then call this again.',
        ],
      )));

      return;
    }

    $this->setOutput(implode("\n", array_merge(
      [
        sprintf('Created the view "%s" (machine name %s) using %s.', $result['label'], $result['id'], $result['format']),
        '',
      ],
      array_map(static fn(string $n): string => '  - ' . $n, $result['notes']),
      [
        '',
        sprintf('Edit or enable it at /admin/structure/views/view/%s.', $result['id']),
      ],
    )));
  }

}
