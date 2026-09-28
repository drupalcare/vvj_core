<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * The model's answer for one view: a name, the fields, the sort — or why not.
 *
 * NOTHING HERE IS WRITTEN. The creator re-checks every part of it.
 */
final readonly class DraftedView {

  /**
   * Constructs a draft.
   *
   * @param bool $usable
   *   Whether there is anything worth building.
   * @param string $label
   *   The view's human name.
   * @param list<string> $fields
   *   Field machine names, in order, each one a real field on the bundle.
   * @param string $sort
   *   One of ViewRequest::SORTS.
   * @param list<string> $refusals
   *   Why there is no draft. Empty when $usable is TRUE.
   */
  private function __construct(
    public bool $usable,
    public string $label = '',
    public array $fields = [],
    public string $sort = 'newest',
    public array $refusals = [],
  ) {}

  /**
   * A usable draft.
   *
   * @param string $label
   *   The view's name.
   * @param list<string> $fields
   *   Fields, in order.
   * @param string $sort
   *   The sort.
   *
   * @return self
   *   The draft.
   */
  public static function of(string $label, array $fields, string $sort): self {
    return new self(TRUE, $label, $fields, $sort);
  }

  /**
   * No draft, and why.
   *
   * @param list<string> $refusals
   *   Complete sentences.
   *
   * @return self
   *   The refusal.
   */
  public static function refused(array $refusals): self {
    return new self(FALSE, refusals: $refusals);
  }

}
