<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Constants;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Standard CSS easing function values shared across VVJ patterns.
 *
 * These are the five CSS easing keywords plus a sensible default.
 * Pattern modules that expose an "animation easing" form field consume
 * `Easing::getOptions()` so the values stay consistent.
 */
final class Easing {

  use StringTranslationTrait;

  /**
   * Default smooth curve (CSS keyword).
   */
  public const string EASE = 'ease';

  /**
   * Slow start, faster end.
   */
  public const string EASE_IN = 'ease-in';

  /**
   * Fast start, slow end.
   */
  public const string EASE_OUT = 'ease-out';

  /**
   * Slow start and end, faster middle.
   */
  public const string EASE_IN_OUT = 'ease-in-out';

  /**
   * Constant speed throughout.
   */
  public const string LINEAR = 'linear';

  /**
   * Default easing across the suite.
   */
  public const string DEFAULT_VALUE = self::EASE_IN_OUT;

  /**
   * All known easing keyword values.
   *
   * @var list<string>
   */
  public const array ALL_VALUES = [
    self::EASE,
    self::EASE_IN,
    self::EASE_OUT,
    self::EASE_IN_OUT,
    self::LINEAR,
  ];

  /**
   * Get translatable easing option list.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Easing keyword → translatable label.
   */
  public function getOptions(): array {
    return [
      self::EASE => $this->t('Ease (default smooth curve)'),
      self::EASE_IN => $this->t('Ease in (slow start)'),
      self::EASE_OUT => $this->t('Ease out (slow end)'),
      self::EASE_IN_OUT => $this->t('Ease in-out (slow ends)'),
      self::LINEAR => $this->t('Linear (constant speed)'),
    ];
  }

  private function __construct() {
  }

}
