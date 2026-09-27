<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use Drupal\vvj_core_ai\Service\SettingsReaderInterface;
use Drupal\vvj_core_ai\Tool\SafeContextTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports what a VVJ format's settings actually are, and what they accept.
 *
 * THE VALUE IS THE ALLOWED VALUES. A model asked "what does the slideshow's
 * animation setting take?" will produce something plausible — `fade`, `slide`,
 * `zoom`. The real answer on this site is `none, a-zoom, a-fade, a-top,
 * a-bottom, a-left, a-right`, and a plausible wrong value is worse than no
 * answer: it is typed into a settings form, saved, and quietly does nothing.
 *
 * Every word here comes from the format's own settings form at runtime. There
 * is no table of settings in this module.
 *
 * @package Drupal\vvj_core_ai\Plugin\AiFunctionCall
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[FunctionCall(
  id: 'vvj_formats:explain_settings',
  function_name: 'vvj_explain_settings',
  name: 'Explain a VVJ format\'s settings',
  description: 'Reports every setting on one VVJ display format — its machine name, what the form calls it, its type, its default, and the exact values it accepts. Call this before naming any setting or value: the allowed values are specific to the format and cannot be guessed. Reads only; it never changes a view or a setting.',
  group: 'vvj_formats',
  module_dependencies: ['vvj_core'],
  context_definitions: [
    'format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('The format'),
      description: 'Either the Views plugin id (views_vvjs) or the module name (vvjs). Call vvj_recommend_format first if you are not sure which formats this site has.',
      required: TRUE,
    ),
    'setting' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('One setting'),
      description: 'Machine name of a single setting to explain. Omit to list them all.',
      required: FALSE,
    ),
  ],
)]
final class ExplainSettings extends FunctionCallBase implements ExecutableFunctionCallInterface {

  use SafeContextTrait;

  /**
   * Reads settings from a format's own form.
   */
  protected SettingsReaderInterface $reader;

  /**
   * Names the formats this site has, for a useful refusal.
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
    $instance->reader = $container->get(SettingsReaderInterface::class);
    $instance->registry = $container->get(FormatRegistryInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    $format = $this->string('format');

    if ($format === '') {
      $this->setOutput('Which format? Give its plugin id or module name, or call vvj_recommend_format to see what this site has.');

      return;
    }

    $settings = $this->reader->read($format);

    if ($settings === NULL) {
      $this->setOutput($this->notInstalled($format));

      return;
    }

    if ($settings === []) {
      // Installed but unreadable is NOT "has no settings" — saying the latter
      // would be a confident falsehood about a format that has plenty.
      $this->setOutput(implode("\n", [
        sprintf('The format %s is installed, but its settings form could not be built, so its settings cannot be listed.', $format),
        'The usual cause is that the Views UI module is not enabled: some formats link to the view edit form from inside their own settings form, and that link needs a route Views UI provides. Enabling views_ui fixes it.',
        'This is a limit of reading settings through the form — not a fault in the format, and not a sign the format has no settings. The exact reason was written to the log channel vvj_core_ai.',
      ]));

      return;
    }

    $one = $this->string('setting');

    if ($one !== '') {
      foreach ($settings as $setting) {
        if ($setting->name === $one) {
          $this->setOutput(implode("\n", [
            sprintf('%s, on %s:', $setting->name, $format),
            '  ' . $setting->line(),
            '  Found under "' . ($setting->section ?: 'the top level of the settings form') . '".',
            'Change it in the Views UI: edit the view, open the format settings. This tool does not change it.',
          ]));

          return;
        }
      }

      $this->setOutput(sprintf(
        "There is no setting called \"%s\" on %s. It has: %s.",
        $one,
        $format,
        implode(', ', array_map(static fn($s) => $s->name, $settings)),
      ));

      return;
    }

    $lines = [sprintf('%s has %d settings:', $format, count($settings))];
    $section = NULL;

    foreach ($settings as $setting) {
      $group = $setting->section !== '' ? $setting->section : 'Ungrouped';

      if ($group !== $section) {
        $lines[] = '';
        $lines[] = $group . ':';
        $section = $group;
      }

      $lines[] = '  ' . $setting->line();
    }

    $lines[] = '';
    $lines[] = 'Use these machine names and these values exactly — a value not listed above will be rejected or silently ignored.';
    $lines[] = 'Everything here is changed by hand in the Views UI. This tool does not change any of it.';

    $this->setOutput(implode("\n", $lines));
  }

  /**
   * The sentence for a format this site does not have.
   *
   * @param string $format
   *   What the caller asked for.
   *
   * @return string
   *   A refusal that names what IS here, so the next attempt can succeed.
   */
  private function notInstalled(string $format): string {
    $all = $this->registry->all();

    if ($all === []) {
      return sprintf(
        'This site has no VVJ display formats installed, so "%s" cannot be explained. VVJ Core is present but no format module is.',
        $format,
      );
    }

    return sprintf(
      'This site does not have "%s". It has: %s. Do not describe a format that is not in that list.',
      $format,
      implode(', ', array_keys($all)),
    );
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
