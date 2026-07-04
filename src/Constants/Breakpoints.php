<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Constants;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Canonical responsive-breakpoint values and labels for every VVJ pattern.
 *
 * Pattern modules consume `Breakpoints::OPTIONS` for their breakpoint
 * select fields so the values stay in sync across the suite. The values
 * align with Bootstrap 5 conventions site builders are familiar with.
 */
final class Breakpoints {

  use StringTranslationTrait;

  /**
   * Active on every screen size — no breakpoint switch.
   */
  public const string ALL = 'all';

  /**
   * Small breakpoint — 576px / 36rem (phone landscape).
   */
  public const string BP_576 = '576';

  /**
   * Medium breakpoint — 768px / 48rem (tablet portrait).
   */
  public const string BP_768 = '768';

  /**
   * Large breakpoint — 992px / 62rem (tablet landscape / small desktop).
   */
  public const string BP_992 = '992';

  /**
   * Extra-large breakpoint — 1200px / 75rem (desktop).
   */
  public const string BP_1200 = '1200';

  /**
   * Extra-extra-large breakpoint — 1400px / 87.5rem (wide desktop).
   */
  public const string BP_1400 = '1400';

  /**
   * Default value when a pattern doesn't specify its own.
   */
  public const string DEFAULT_VALUE = self::BP_768;

  /**
   * All known breakpoint values, lowest to highest.
   *
   * @var list<string>
   */
  public const array ALL_VALUES = [
    self::ALL,
    self::BP_576,
    self::BP_768,
    self::BP_992,
    self::BP_1200,
    self::BP_1400,
  ];

  /**
   * Get translatable option list for use in form `#options`.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Option key → translatable label.
   */
  public function getOptions(): array {
    /** @var array<string, \Drupal\Core\StringTranslation\TranslatableMarkup> $options */
    $options = [
      self::ALL => $this->t('Active on all screens'),
      self::BP_576 => $this->t('576px / 36rem'),
      self::BP_768 => $this->t('768px / 48rem'),
      self::BP_992 => $this->t('992px / 62rem'),
      self::BP_1200 => $this->t('1200px / 75rem'),
      self::BP_1400 => $this->t('1400px / 87.5rem'),
    ];
    return $options;
  }

  /**
   * Prevent instantiation as a static-only utility.
   */
  private function __construct() {
  }

}
