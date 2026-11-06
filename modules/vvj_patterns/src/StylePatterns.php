<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vvj_core\Service\StylePatternsInterface;

/**
 * The module-independent Patterns logic for VVJ Views style plugins.
 *
 * One home for what every VVJ module needs: the Patterns form section, the
 * Save step that applies a chosen Pattern's option values, and the runtime
 * keys the templates read. Each module passes its own slug, so the Pattern
 * files and private code stay inside that module.
 */
final class StylePatterns implements StylePatternsInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly PatternRepository $repository,
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getActivePattern(string $module, array $options): ?array {
    $id = $options['vvj_pattern'] ?? 'none';
    if (!is_string($id) || $id === 'none') {
      return NULL;
    }
    return $this->repository->getPatterns($module)[$id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSection(string $module, array $options, array &$form): void {
    $patterns = $this->repository->getPatterns($module);
    if ($patterns === []) {
      return;
    }
    $choices = ['none' => $this->t('None')];
    foreach ($patterns as $id => $pattern) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $choices[$id] = $this->t($pattern['label']);
    }
    $form['patterns_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Patterns'),
      '#open' => TRUE,
      '#weight' => -50,
    ];
    $current = $options['vvj_pattern'] ?? 'none';
    $form['patterns_section']['vvj_pattern'] = [
      '#type' => 'select',
      '#title' => $this->t('Pattern'),
      '#options' => $choices,
      '#default_value' => is_string($current) && isset($choices[$current]) ? $current : 'none',
      '#description' => $this->t('A ready-made design. When you choose a different Pattern and save, its settings replace the layout, animation, styling and deep linking settings below; you can still change them afterwards. Choose None to keep your own settings.'),
    ];
    $active = $this->getActivePattern($module, $options);
    if ($active !== NULL) {
      $needs = array_values(array_filter((array) ($active['needs'] ?? []), 'is_string'));
      $form['patterns_section']['vvj_pattern_needs'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('To finish the @name Pattern', ['@name' => $choices[$active['id']]]),
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '#items' => array_map(fn (string $need) => $this->t($need), $needs),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applyChoice(string $module, array $definedOptions, array $options, array $values, array &$flattened): void {
    // Keep the stored choice when the section is absent (module disabled);
    // apply a Pattern's values only when the choice changes.
    $previous = $options['vvj_pattern'] ?? 'none';
    $previous = is_string($previous) ? $previous : 'none';
    $patterns = $this->repository->getPatterns($module);
    $section = $values['patterns_section'] ?? NULL;
    $chosen = is_array($section) && is_string($section['vvj_pattern'] ?? NULL)
      ? $section['vvj_pattern']
      : $previous;
    if ($chosen !== 'none' && !isset($patterns[$chosen]) && is_array($section)) {
      $chosen = 'none';
    }
    $flattened['vvj_pattern'] = $chosen;
    if ($chosen === 'none' || $chosen === $previous || !isset($patterns[$chosen])) {
      return;
    }
    $pattern = $patterns[$chosen];
    // Only this module's own option names, so a Pattern file cannot add keys.
    $applied = array_intersect_key($pattern['options'], $definedOptions);
    $flattened = $applied + $flattened;
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    $name = $this->t($pattern['label']);
    $this->messenger->addStatus($this->t('The @name Pattern set this view: @settings.', [
      '@name' => $name,
      '@settings' => implode(', ', array_keys($applied)),
    ]));
    foreach ($pattern['needs'] as $need) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $this->messenger->addWarning($this->t('@name Pattern, still to do: @need', [
        '@name' => $name,
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '@need' => $this->t($need),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRuntimeOptions(array $pattern, mixed $title): array {
    return [
      'vvj_pattern_active' => $pattern['class'],
      'vvj_pattern_features' => $pattern['features'],
      'vvj_pattern_label' => is_scalar($title) || $title instanceof \Stringable ? strip_tags((string) $title) : '',
    ];
  }

}
