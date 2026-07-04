<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vvj_core\Constants\Breakpoints;

/**
 * Service wrapper around the canonical breakpoint enum.
 *
 * Pattern modules consume this via DI in their plugin's `create()`
 * method to render their breakpoint select fields. The values come
 * from `Breakpoints` (typed const enum); this service exists so the
 * translatable labels resolve through Drupal's string-translation
 * system instead of being computed at parse time.
 */
final class BreakpointRegistry {

  use StringTranslationTrait;

  /**
   * Get translatable option list for a breakpoint select field.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Breakpoint key → translatable label.
   */
  public function getOptions(): array {
    /** @var array<string, \Drupal\Core\StringTranslation\TranslatableMarkup> $options */
    $options = [
      Breakpoints::ALL => $this->t('Active on all screens'),
      Breakpoints::BP_576 => $this->t('576px / 36rem'),
      Breakpoints::BP_768 => $this->t('768px / 48rem'),
      Breakpoints::BP_992 => $this->t('992px / 62rem'),
      Breakpoints::BP_1200 => $this->t('1200px / 75rem'),
      Breakpoints::BP_1400 => $this->t('1400px / 87.5rem'),
    ];
    return $options;
  }

  /**
   * Whether a given value is a valid breakpoint key.
   */
  public function isValid(string $value): bool {
    return in_array($value, Breakpoints::ALL_VALUES, TRUE);
  }

  /**
   * Get the default breakpoint value.
   */
  public function getDefault(): string {
    return Breakpoints::DEFAULT_VALUE;
  }

}
