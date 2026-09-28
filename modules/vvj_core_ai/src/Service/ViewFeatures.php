<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Default features: the brief's list, checked against the settings form.
 */
final class ViewFeatures implements ViewFeaturesInterface {

  use StringTranslationTrait;

  /**
   * Constructs the service.
   *
   * @param \Drupal\vvj_core_ai\Service\BriefsInterface $briefs
   *   The one owner of the features list.
   * @param \Drupal\vvj_core_ai\Service\SettingsReaderInterface $settings
   *   Reads each format's settings from its own form: names, types, choices.
   */
  public function __construct(
    private readonly BriefsInterface $briefs,
    private readonly SettingsReaderInterface $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function valuesFor(string $format): array {
    $features = $this->briefs->forFormat($format)['features'] ?? [];
    $settings = $this->byName($format);
    $out = [];

    foreach ($features as $name => $value) {
      $setting = $settings[$name] ?? NULL;

      if ($setting === NULL) {
        continue;
      }

      if ($setting->type === 'checkbox' && in_array($value, ['0', '1'], TRUE)) {
        $out[$name] = $value === '1';
      }
      elseif ($setting->type === 'select' && in_array($value, array_map('strval', $setting->choices), TRUE)) {
        $out[$name] = $value;
      }
      elseif ($setting->type === 'number' && is_numeric($value)) {
        $out[$name] = str_contains($value, '.') ? (float) $value : (int) $value;
      }
    }

    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $format, array $values): array {
    $settings = $this->byName($format);
    $out = [];

    foreach ($values as $name => $value) {
      $setting = $settings[$name] ?? NULL;

      if ($setting === NULL) {
        continue;
      }

      $shown = is_bool($value) ? ($value ? $this->t('on') : $this->t('off')) : (string) $value;
      $out[] = (string) $this->t('@label: @value', ['@label' => $setting->label, '@value' => (string) $shown]);
    }

    return $out;
  }

  /**
   * The format's settings, keyed by name.
   *
   * @param string $format
   *   Views style plugin id.
   *
   * @return array<string, \Drupal\vvj_core_ai\Model\FormatSetting>
   *   Setting name => setting.
   */
  private function byName(string $format): array {
    $out = [];

    foreach ($this->settings->read($format) ?? [] as $setting) {
      $out[$setting->name] = $setting;
    }

    return $out;
  }

}
