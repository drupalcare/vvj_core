<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Default briefs reader, over the config a person writes.
 */
final class Briefs implements BriefsInterface {

  /**
   * The config object the briefs live in.
   */
  private const CONFIG = 'vvj_core_ai.briefs';

  /**
   * Constructs the reader.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Applies overrides and the active language's translation.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function forFormat(string $format): ?array {
    $briefs = $this->configFactory->get(self::CONFIG)->get('briefs');
    $brief = is_array($briefs) ? ($briefs[trim($format)] ?? NULL) : NULL;

    if (!is_array($brief)) {
      return NULL;
    }

    // `prompt:` is one example; `prompts:` names several. Both become a map.
    $prompts = [];
    $single = $brief['prompt'] ?? NULL;

    if (is_string($single) && trim($single) !== '') {
      $prompts[''] = trim($single);
    }

    foreach (is_array($brief['prompts'] ?? NULL) ? $brief['prompts'] : [] as $name => $text) {
      if (is_string($text) && trim($text) !== '') {
        $prompts[(string) $name] = trim($text);
      }
    }

    $features = [];

    foreach (is_array($brief['features'] ?? NULL) ? $brief['features'] : [] as $name => $value) {
      if (is_scalar($value)) {
        $features[(string) $name] = (string) $value;
      }
    }

    $count = $brief['count'] ?? 6;

    return [
      'good_for' => is_string($brief['good_for'] ?? NULL) ? $brief['good_for'] : '',
      'practices' => is_string($brief['practices'] ?? NULL) ? trim($brief['practices']) : '',
      'count' => is_numeric($count) ? max(1, (int) $count) : 6,
      'prompts' => $prompts,
      'features' => $features,
    ];
  }

}
