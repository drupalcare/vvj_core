<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * A view that could be created, or the questions blocking it.
 *
 * Deliberately not a bare array. A plan is one of exactly two things — enough
 * to build a view, or a list of what is still missing — and a value object
 * makes the caller say which rather than test for keys.
 *
 * WHY QUESTIONS ARE A LIST. Every unmet precondition comes back at once so the
 * assistant asks one combined question. Asking one item per turn is what makes
 * a conversational tool exhausting: four round trips to learn a content type,
 * two fields and a format that could have been asked for together.
 */
final readonly class ViewPlan {

  /**
   * Constructs a plan.
   *
   * @param bool $resolved
   *   TRUE when everything needed to create the view is known.
   * @param string $entityType
   *   Entity type to list, e.g. `node`.
   * @param string $bundle
   *   Bundle to filter to, e.g. `apex_case_study`. Empty means every bundle.
   * @param string $format
   *   The VVJ style plugin id, e.g. `views_vvjs`.
   * @param list<string> $fields
   *   Field machine names to show, in order.
   * @param string $label
   *   Human name for the view.
   * @param string $machineName
   *   Machine name, free on this site.
   * @param list<string> $questions
   *   Everything still missing. Each is a complete sentence a person can act
   *   on, not an error code.
   */
  private function __construct(
    public bool $resolved,
    public string $entityType = '',
    public string $bundle = '',
    public string $format = '',
    public array $fields = [],
    public string $label = '',
    public string $machineName = '',
    public array $questions = [],
  ) {}

  /**
   * A plan with everything it needs.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle, or '' for all.
   * @param string $format
   *   VVJ style plugin id.
   * @param list<string> $fields
   *   Fields to show.
   * @param string $label
   *   Human name.
   * @param string $machineName
   *   Machine name.
   *
   * @return self
   *   The plan.
   */
  public static function ready(
    string $entityType,
    string $bundle,
    string $format,
    array $fields,
    string $label,
    string $machineName,
  ): self {
    return new self(TRUE, $entityType, $bundle, $format, array_values($fields), $label, $machineName);
  }

  /**
   * A plan that cannot proceed, and why.
   *
   * @param list<string> $questions
   *   One or more complete sentences naming what is missing or wrong.
   *
   * @return self
   *   The plan.
   */
  public static function blocked(array $questions): self {
    assert($questions !== [], 'A blocked plan must carry at least one question.');

    return new self(FALSE, questions: array_values($questions));
  }

}
