<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

/**
 * The contract a Patterns provider gives the VVJ Views style base.
 *
 * Declared here so vvj_core can use Patterns without depending on the
 * vvj_patterns module: the optional vvj_patterns module implements it as the
 * `vvj_patterns.style_patterns` service, and the style base takes that
 * service only when the container has it.
 */
interface StylePatternsInterface {

  /**
   * Returns the Pattern definition chosen in the style options, or NULL.
   *
   * @param string $module
   *   The module slug, for example `vvjt`.
   * @param array<string, mixed> $options
   *   The stored style options.
   *
   * @return array<string, mixed>|null
   *   The definition when a known Pattern is chosen, otherwise NULL.
   */
  public function getActivePattern(string $module, array $options): ?array;

  /**
   * Adds the Patterns section to the style options form.
   *
   * Adds nothing when the module ships no Pattern.
   *
   * @param string $module
   *   The module slug.
   * @param array<string, mixed> $options
   *   The stored style options.
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  public function buildSection(string $module, array $options, array &$form): void;

  /**
   * Records the submitted Pattern choice and applies it when it changed.
   *
   * Keeps the stored choice when the Patterns section was not submitted.
   * Only option keys the plugin defines are ever written.
   *
   * @param string $module
   *   The module slug.
   * @param array<string, mixed> $definedOptions
   *   The plugin's defined options, keyed by option name.
   * @param array<string, mixed> $options
   *   The stored style options (the previous state).
   * @param array<int|string, mixed> $values
   *   The submitted style option values, keyed by section.
   * @param array<string, mixed> $flattened
   *   The flattened options to save, modified in place.
   */
  public function applyChoice(string $module, array $definedOptions, array $options, array $values, array &$flattened): void;

  /**
   * Returns the runtime-only option keys the templates read.
   *
   * @param array<string, mixed> $pattern
   *   The active Pattern definition.
   * @param mixed $title
   *   The view title, any scalar or stringable value.
   *
   * @return array<string, mixed>
   *   Values for `vvj_pattern_active`, `vvj_pattern_features` and
   *   `vvj_pattern_label`.
   */
  public function getRuntimeOptions(array $pattern, mixed $title): array;

}
