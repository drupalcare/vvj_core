<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\vvj_core_ai\Model\ViewPlan;

/**
 * Works out whether a view can be created, or what is still missing.
 *
 * Reads only. `ViewCreator` does the writing — and calls this again itself
 * rather than trusting that it was called.
 *
 * @see \Drupal\vvj_core_ai\Service\ViewPlanner
 * @see docs/VVJ-AI-BRIEF.md §5
 */
interface ViewPlannerInterface {

  /**
   * Plans a view, or returns every unmet precondition at once.
   *
   * @param string|null $entityType
   *   Entity type to list. Defaults to `node`, which is what almost every
   *   view lists.
   * @param string|null $bundle
   *   Bundle to filter to, or NULL/'' for all of them.
   * @param string|null $format
   *   VVJ format — plugin id or module name.
   * @param list<string> $fields
   *   Field machine names to show, in the order they should appear.
   * @param string|null $label
   *   Human name for the view.
   * @param bool $needsFields
   *   FALSE for a view-mode row, which renders whole items and asks for no
   *   fields. Fields that ARE given are still checked either way.
   *
   * @return \Drupal\vvj_core_ai\Model\ViewPlan
   *   A usable plan, or the questions blocking one.
   */
  public function plan(
    ?string $entityType,
    ?string $bundle,
    ?string $format,
    array $fields,
    ?string $label,
    bool $needsFields = TRUE,
  ): ViewPlan;

  /**
   * The fields a person could sensibly show for a bundle.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   *
   * @return list<string>
   *   Field machine names, machinery excluded.
   */
  public function fieldsOn(string $entityType, string $bundle): array;

}
