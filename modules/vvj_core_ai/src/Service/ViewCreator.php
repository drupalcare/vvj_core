<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Entity\View;

/**
 * Creates a view that uses a VVJ format.
 *
 * THE THREE RULES, none of them configurable:
 *
 * 1. A new view is created DISABLED. A view that appears on a live site the
 *    moment a machine invents it is the failure this guards against — the
 *    person enables it after looking. Same shape as `create_sections` leaving
 *    a new page unpublished.
 * 2. It NEVER touches an existing view. A machine name already in use is a
 *    refusal, not an overwrite. Someone's view is their work.
 * 3. It re-runs every precondition ITSELF. The planner is called here as well
 *    as by `vvj_resolve_view`, because a model can skip the first tool, and a
 *    creator that trusted it would build a view against a bundle that does not
 *    exist or fields that are not there. The redundancy IS the safety model.
 */
final class ViewCreator implements ViewCreatorInterface {

  /**
   * Constructs the creator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Creates and saves the view.
   * @param \Drupal\vvj_core_ai\Service\ViewPlannerInterface $planner
   *   Re-runs every precondition; see rule 3.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewPlannerInterface $planner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(
    ?string $entityType,
    ?string $bundle,
    ?string $format,
    array $fields,
    ?string $label,
  ): array {
    $plan = $this->planner->plan($entityType, $bundle, $format, $fields, $label);

    if (!$plan->resolved) {
      return ['created' => FALSE, 'refusals' => $plan->questions, 'notes' => []];
    }

    $base = $this->baseTable($plan->entityType);

    if ($base === NULL) {
      return [
        'created' => FALSE,
        'refusals' => [sprintf(
          'Views cannot list "%s" content on this site — it has no Views data.',
          $plan->entityType,
          ),
        ],
        'notes' => [],
      ];
    }

    $notes = [];
    $display = [
      // Views stores the FULL style plugin id, e.g. views_vvjs.
      'style' => ['type' => $plan->format, 'options' => []],
      'row' => ['type' => 'fields'],
      'fields' => $this->fields($plan->entityType, $plan->fields, $base),
      'pager' => ['type' => 'some', 'options' => ['items_per_page' => 10]],
      'filters' => $this->filters($plan->entityType, $plan->bundle, $base),
      'sorts' => [],
    ];

    $view = View::create([
      'id' => $plan->machineName,
      'label' => $plan->label,
      // Rule 1. Not a preference.
      'status' => FALSE,
      'base_table' => $base,
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => $display,
        ],
      ],
    ]);
    $view->save();

    $notes[] = 'The view is DISABLED — nothing appears on the site until you enable it.';
    $notes[] = sprintf('Showing %d field(s): %s.', count($plan->fields), implode(', ', $plan->fields));

    if ($plan->bundle === '') {
      $notes[] = 'No content type filter was set, so it lists every type. Add a filter if that is not what you want.';
    }

    $notes[] = 'The format\'s own settings are at their defaults. Adjust them in the Views UI — this tool does not set them.';

    return [
      'created' => TRUE,
      'id' => $plan->machineName,
      'label' => $plan->label,
      'format' => $plan->format,
      'refusals' => [],
      'notes' => $notes,
    ];
  }

  /**
   * The Views base table for an entity type.
   *
   * @param string $entityType
   *   Entity type id.
   *
   * @return string|null
   *   The base table, or NULL when the entity type has no Views integration.
   */
  private function baseTable(string $entityType): ?string {
    $definition = $this->entityTypeManager->getDefinition($entityType, FALSE);

    if ($definition === NULL) {
      return NULL;
    }

    // Views lists the data table where there is one — that is where the
    // translatable, per-language values live — and the base table otherwise.
    $table = $definition->getDataTable() ?: $definition->getBaseTable();

    return is_string($table) && $table !== '' ? $table : NULL;
  }

  /**
   * Field handlers for the chosen fields.
   *
   * @param string $entityType
   *   Entity type id.
   * @param list<string> $fields
   *   Field machine names, in display order.
   * @param string $base
   *   The Views base table.
   *
   * @return array<string, array<string, mixed>>
   *   Views field handler configuration.
   */
  private function fields(string $entityType, array $fields, string $base): array {
    $out = [];

    foreach ($fields as $name) {
      $out[$name] = [
        'id' => $name,
        'table' => $base,
        'field' => $name,
        'entity_type' => $entityType,
        'plugin_id' => 'field',
      ];
    }

    return $out;
  }

  /**
   * Filters restricting the view to published content of one bundle.
   *
   * @param string $entityType
   *   Entity type id.
   * @param string $bundle
   *   Bundle, or '' for all.
   * @param string $base
   *   The Views base table.
   *
   * @return array<string, array<string, mixed>>
   *   Views filter configuration.
   */
  private function filters(string $entityType, string $bundle, string $base): array {
    $out = [];
    $definition = $this->entityTypeManager->getDefinition($entityType, FALSE);
    $keys = $definition?->getKeys() ?? [];

    // Entity keys are declared as mixed. A key that is not a string cannot
    // name a database column, so it is skipped rather than coerced — coercing
    // it would build a filter against a column that does not exist, and the
    // view would return nothing for a reason no one could see.
    $statusKey = $keys['status'] ?? NULL;
    $bundleKey = $keys['bundle'] ?? NULL;

    // Published-only where the entity type has a status key. A view that shows
    // unpublished content to anonymous visitors is a disclosure, not a display
    // choice, so it is never the default here.
    if (is_string($statusKey) && $statusKey !== '') {
      $out[$statusKey] = [
        'id' => $statusKey,
        'table' => $base,
        'field' => $statusKey,
        'entity_type' => $entityType,
        'plugin_id' => 'boolean',
        'value' => '1',
      ];
    }

    if ($bundle !== '' && is_string($bundleKey) && $bundleKey !== '') {
      $out[$bundleKey] = [
        'id' => $bundleKey,
        'table' => $base,
        'field' => $bundleKey,
        'entity_type' => $entityType,
        'plugin_id' => 'bundle',
        'value' => [$bundle => $bundle],
      ];
    }

    return $out;
  }

}
