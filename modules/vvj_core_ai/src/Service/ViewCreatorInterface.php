<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Creates a view that uses a VVJ format.
 *
 * The only thing in this module that writes, and it only ever CREATES. It
 * never edits an existing view and never changes a format's settings.
 *
 * @see \Drupal\vvj_core_ai\Service\ViewCreator
 * @see docs/VVJ-AI-BRIEF.md §5
 */
interface ViewCreatorInterface {

  /**
   * Creates a disabled view, re-checking every precondition first.
   *
   * Does not throw for ordinary refusals — an unknown bundle, a field that is
   * not there, a name already taken are all results rather than exceptions,
   * because each is something a person can act on.
   *
   * @param string|null $entityType
   *   Entity type to list; defaults to `node`.
   * @param string|null $bundle
   *   Bundle to filter to, or NULL/'' for all.
   * @param string|null $format
   *   VVJ format — plugin id or module name.
   * @param list<string> $fields
   *   Field machine names to show, in order.
   * @param string|null $label
   *   Human name for the view.
   *
   * @return array{created: TRUE, id: string, label: string, format: string, refusals: list<string>, notes: list<string>}|array{created: FALSE, refusals: list<string>, notes: list<string>}
   *   What was created, or why nothing was. Two shapes rather than one with
   *   optional keys: `created` being TRUE is exactly what guarantees `id`,
   *   `label` and `format` are there, and saying so lets a caller that checks
   *   `created` read them without a second existence test.
   */
  public function create(
    ?string $entityType,
    ?string $bundle,
    ?string $format,
    array $fields,
    ?string $label,
  ): array;

}
