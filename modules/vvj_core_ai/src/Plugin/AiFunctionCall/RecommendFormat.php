<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use Drupal\vvj_core_ai\Tool\SafeContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the VVJ formats this site has, so a choice is made from facts.
 *
 * WHY THIS TOOL EXISTS AT ALL. A model that has read the VVJ documentation can
 * describe all ten formats fluently — including the seven a given site has not
 * installed. That answer is worse than no answer: it sends a site builder to
 * the Views UI looking for a format that is not in the list. This reports what
 * is actually here, and says plainly when the family's other formats are not.
 *
 * It does not do the matching. The model reads far better than any keyword
 * table would, and a hand-written "carousel → vvjb" map inside this module
 * would be a second copy of a fact the plugin descriptions already carry. This
 * supplies the ground truth; the model does the language.
 *
 * @package Drupal\vvj_core_ai\Plugin\AiFunctionCall
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[FunctionCall(
  id: 'vvj_formats:recommend_format',
  function_name: 'vvj_recommend_format',
  name: 'List the VVJ display formats',
  description: 'Reports every VVJ Views display format installed on this site, with what each one does and how many settings it has. Call this BEFORE naming any VVJ format: which formats exist is a fact about this site, not about the project, and recommending one that is not installed sends the person looking for something that is not there. Reads only; it never changes a view or a setting.',
  group: 'vvj_formats',
  module_dependencies: ['vvj_core'],
  context_definitions: [
    'looking_for' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('What the person wants'),
      description: 'What the person described wanting, in their own words. Optional — it is echoed back so the answer stays anchored to the actual request. It does not filter the list.',
      required: FALSE,
    ),
  ],
)]
final class RecommendFormat extends FunctionCallBase implements ExecutableFunctionCallInterface {

  use SafeContextTrait;

  /**
   * Finds the formats this site has.
   */
  protected FormatRegistryInterface $registry;

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
    $instance->registry = $container->get(FormatRegistryInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    $formats = $this->registry->all();

    if ($formats === []) {
      // vvj_core with no format module is a real, supported state — the core
      // module ships no format of its own. Saying so is the answer; listing
      // what the family contains would describe software that is not here.
      $this->setOutput(implode("\n", [
        'This site has VVJ Core installed but not one display format module, so there is no VVJ format to choose from yet.',
        'The formats are separate projects — vvja (accordion), vvjb (carousel), vvjc (3D carousel), vvjf (3D flip box), vvjh (hero), vvjl (lightbox), vvjp (parallax), vvjr (reveal), vvjs (slideshow), vvjt (tabs).',
        'Install the one that fits with composer, then enable it. Do not describe a format as available until it is.',
      ]));

      return;
    }

    $wanted = $this->string('looking_for');
    $lines = [];

    if ($wanted !== '') {
      $lines[] = sprintf('Asked for: %s', $wanted);
      $lines[] = '';
    }

    $lines[] = sprintf(
      'This site has %d VVJ display format%s installed:',
      count($formats),
      count($formats) === 1 ? '' : 's',
    );

    foreach ($formats as $format) {
      $lines[] = '  ' . $format->line();
    }

    $lines[] = '';
    $lines[] = 'Choose from these and name the plugin id exactly as written above — that is what the Views UI expects.';

    // The brief is explicit that an absent format which is the right answer
    // must still be named, with the fact that it is absent. Silence here would
    // quietly narrow the recommendation to whatever happens to be enabled.
    $lines[] = 'If none of them fits, say so and name the VVJ project that would, making clear it is NOT installed here and would have to be added.';
    $lines[] = 'Never present a format as available unless it appears in the list above.';

    $this->setOutput(implode("\n", $lines));
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
