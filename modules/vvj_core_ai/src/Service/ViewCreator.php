<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Entity\View;
use Drupal\vvj_core_ai\Model\ViewRequest;

/**
 * Default creator: plan, build, validate with Views, then save disabled.
 */
final class ViewCreator implements ViewCreatorInterface {

  use StringTranslationTrait;

  /**
   * Formats whose first field must be an image, and the formatter it needs.
   *
   * THE FORMATS OWN THIS RULE; this only puts an image first so the rule can
   * pass. Hero.php:529-533 refuses a first field that is not an image, and
   * Parallax.php:469-473 one without the "URL to image" formatter. The
   * finished view still goes through validate(), so if either format changes
   * its mind the save is refused rather than wrong.
   */
  private const IMAGE_FIRST = [
    'views_vvjh' => 'image',
    'views_vvjp' => 'image_url',
  ];

  /**
   * Constructs the creator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads view modes and saves the view.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   Finds an image field when a format needs one first.
   * @param \Drupal\vvj_core_ai\Service\ViewPlannerInterface $planner
   *   Re-checks every precondition; never assumed to have run.
   * @param \Drupal\vvj_core_ai\Service\RowRulesInterface $rowRules
   *   Says whether the format keeps a view-mode row.
   * @param \Drupal\Core\Path\PathValidatorInterface $pathValidator
   *   Says whether a page path is already answered by the site.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly ViewPlannerInterface $planner,
    private readonly RowRulesInterface $rowRules,
    private readonly PathValidatorInterface $pathValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function precheck(ViewRequest $request): array {
    $usesViewMode = $request->viewMode !== '';

    if ($usesViewMode && !$this->rowRules->allowsViewMode($request->format, $request->styleOptions)) {
      return ['view_mode' => (string) $this->t('This format shows fields, not whole items in a view mode. Choose "Fields" instead.')];
    }

    if ($usesViewMode && !$this->viewModeExists($request->entityType, $request->viewMode)) {
      return ['view_mode' => (string) $this->t('There is no "@mode" view mode for this content.', ['@mode' => $request->viewMode])];
    }

    if (!$usesViewMode && isset(self::IMAGE_FIRST[$request->format]) && $this->imageField($request->entityType, $request->bundle, []) === NULL) {
      return ['format' => (string) $this->t('This format needs an image field first, and this content type has none.')];
    }

    // A PATH THE SITE ALREADY ANSWERS — a route, an alias, another view's
    // page — would be taken over or shadowed the moment the view is enabled.
    $path = trim($request->pagePath, " /\t\n");

    if ($path !== '' && $this->pathValidator->getUrlIfValidWithoutAccessCheck('/' . $path) !== FALSE) {
      return ['path' => (string) $this->t('The path /@path is already used on this site. Choose another.', ['@path' => $path])];
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function create(ViewRequest $request): array {
    $usesViewMode = $request->viewMode !== '';
    $fields = $request->fields;
    $refusals = array_values($this->precheck($request));

    if ($refusals !== []) {
      return $this->refused($refusals);
    }

    // Precheck proved there is one.
    $image = !$usesViewMode && isset(self::IMAGE_FIRST[$request->format])
      ? $this->imageField($request->entityType, $request->bundle, $fields)
      : NULL;

    if ($image !== NULL) {
      $fields = array_values(array_unique(array_merge([$image], $fields)));
    }

    $plan = $this->planner->plan($request->entityType, $request->bundle, $request->format, $fields, $request->label, !$usesViewMode);

    if (!$plan->resolved) {
      return $this->refused($plan->questions);
    }

    $base = $this->baseTable($plan->entityType);

    if ($base === NULL) {
      return $this->refused([(string) $this->t('Views cannot list "@type" content on this site.', ['@type' => $plan->entityType])]);
    }

    $count = max(1, min(50, $request->count));
    $default = [
      // The title shown above the block; the label names it in the Views list.
      'title' => $request->blockTitle !== '' ? $request->blockTitle : $plan->label,
      // WHO MAY SEE IT, SET EXPLICITLY. Unset, Views falls back to "none" —
      // anyone, whatever their permissions. Core's own node wizard sets this.
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
      'style' => ['type' => $plan->format, 'options' => $request->styleOptions],
      'row' => $usesViewMode
        ? ['type' => 'entity:' . $plan->entityType, 'options' => ['view_mode' => $request->viewMode]]
        : ['type' => 'fields'],
      'fields' => $usesViewMode ? [] : $this->fields($plan->entityType, $plan->fields, $base, $plan->format),
      // A FIXED NUMBER, NO PAGER — maintainer, 2026-09-27. Page links under a
      // carousel, a slideshow or a set of tabs break the effect.
      'pager' => ['type' => 'some', 'options' => ['items_per_page' => $count, 'offset' => 0]],
      'filters' => $this->filters($plan->entityType, $plan->bundle, $base),
      'sorts' => $this->sorts($plan->entityType, $base, $request->sort),
    ];

    $displays = [
      'default' => [
        'display_plugin' => 'default',
        'id' => 'default',
        'display_title' => 'Default',
        'position' => 0,
        'display_options' => $default,
      ],
      // A BLOCK BY DEFAULT — maintainer, 2026-09-27. Placed in a region or a
      // layout; the Views admin shows where.
      'block_1' => [
        'display_plugin' => 'block',
        'id' => 'block_1',
        'display_title' => 'Block',
        'position' => 1,
        'display_options' => ['block_description' => $plan->label],
      ],
    ];

    $path = trim($request->pagePath, " /\t\n");

    if ($path !== '') {
      $displays['page_1'] = [
        'display_plugin' => 'page',
        'id' => 'page_1',
        'display_title' => 'Page',
        'position' => 2,
        'display_options' => ['path' => $path],
      ];
    }

    $view = View::create([
      'id' => $plan->machineName,
      'label' => $plan->label,
      'status' => FALSE,
      'base_table' => $base,
      'display' => $displays,
    ]);

    // VIEWS DECIDES, BEFORE ANYTHING IS SAVED. Every display is validated the
    // way the Views UI validates on save, which runs each format's own rules.
    foreach ($view->getExecutable()->validate() as $errors) {
      foreach ((array) $errors as $error) {
        $refusals[] = (string) $error;
      }
    }

    if ($refusals !== []) {
      return $this->refused(array_values(array_unique($refusals)));
    }

    $view->save();

    $notes = [
      (string) $this->t('The view is DISABLED: nothing appears on the site until you enable it.'),
      (string) $this->t('It has a block display, showing @count item(s) with no pager. Place the block where it should appear.', ['@count' => $count]),
    ];

    if ($path !== '') {
      $notes[] = (string) $this->t('It also has a page at /@path.', ['@path' => $path]);
    }

    $notes[] = $usesViewMode
      ? (string) $this->t('Each item is shown in the "@mode" view mode.', ['@mode' => $request->viewMode])
      : (string) $this->t('Each item shows: @fields.', ['@fields' => implode(', ', $plan->fields)]);

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
   * A refusal in the shape create() returns.
   *
   * @param list<string> $refusals
   *   Why nothing was built.
   *
   * @return array{created: false, refusals: list<string>, notes: list<string>}
   *   The outcome.
   */
  private function refused(array $refusals): array {
    return ['created' => FALSE, 'refusals' => $refusals, 'notes' => []];
  }

  /**
   * Whether a view mode exists for an entity type. `default` always does.
   */
  private function viewModeExists(string $entityType, string $viewMode): bool {
    return $viewMode === 'default'
      || $this->entityTypeManager->getStorage('entity_view_mode')->load($entityType . '.' . $viewMode) !== NULL;
  }

  /**
   * The image field to put first: one asked for, else the bundle's first.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle, or '' for none.
   * @param list<string> $fields
   *   Fields asked for.
   *
   * @return string|null
   *   A field machine name, or NULL when the bundle has no image field.
   */
  private function imageField(string $entityType, string $bundle, array $fields): ?string {
    if ($bundle === '') {
      return NULL;
    }

    $images = [];

    foreach ($this->fieldManager->getFieldDefinitions($entityType, $bundle) as $name => $definition) {
      if ($definition->getType() === 'image') {
        $images[] = (string) $name;
      }
    }

    foreach ($fields as $name) {
      if (in_array($name, $images, TRUE)) {
        return $name;
      }
    }

    return $images[0] ?? NULL;
  }

  /**
   * The table Views lists this entity type from.
   */
  private function baseTable(string $entityType): ?string {
    $definition = $this->entityTypeManager->getDefinition($entityType, FALSE);

    if ($definition === NULL) {
      return NULL;
    }

    $table = $definition->getDataTable() ?: $definition->getBaseTable();

    return is_string($table) && $table !== '' ? $table : NULL;
  }

  /**
   * Field handlers, the first one given the formatter its format needs.
   *
   * @param string $entityType
   *   Entity type.
   * @param list<string> $fields
   *   Field machine names, in order.
   * @param string $base
   *   Base table.
   * @param string $format
   *   The format, for IMAGE_FIRST.
   *
   * @return array<string, array<string, mixed>>
   *   Views field handlers, keyed by id.
   */
  private function fields(string $entityType, array $fields, string $base, string $format): array {
    $out = [];

    $storages = $this->fieldManager->getFieldStorageDefinitions($entityType);
    $storage = $this->entityTypeManager->getStorage($entityType);
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;

    foreach ($fields as $position => $name) {
      // THE FIELD'S OWN TABLE, not the base one. A configurable field lives in
      // `node__<field>`; handing Views the base table gave the image a handler
      // with no field behind it — Hero refused it, and Slideshow would have
      // printed a broken field (measured on sts.local, 2026-09-27).
      $definition = $storages[$name] ?? NULL;
      $table = $base;

      if ($definition !== NULL && $mapping instanceof DefaultTableMapping && $mapping->requiresDedicatedTableStorage($definition)) {
        $table = $mapping->getDedicatedDataTableName($definition);
      }

      $out[$name] = [
        'id' => $name,
        'table' => $table,
        'field' => $name,
        'entity_type' => $entityType,
        'entity_field' => $name,
        'plugin_id' => 'field',
        'label' => '',
      ];

      if ($position === 0 && isset(self::IMAGE_FIRST[$format])) {
        $out[$name]['type'] = self::IMAGE_FIRST[$format];
      }
    }

    return $out;
  }

  /**
   * Published only, and the one content type when one was chosen.
   *
   * @return array<string, array<string, mixed>>
   *   Views filter handlers, keyed by id.
   */
  private function filters(string $entityType, string $bundle, string $base): array {
    $out = [];
    $definition = $this->entityTypeManager->getDefinition($entityType, FALSE);
    $keys = $definition?->getKeys() ?? [];
    $statusKey = $keys['status'] ?? NULL;
    $bundleKey = $keys['bundle'] ?? NULL;

    if (is_string($statusKey) && $statusKey !== '') {
      $out[$statusKey] = [
        'id' => $statusKey,
        'table' => $base,
        'field' => $statusKey,
        'entity_type' => $entityType,
        'entity_field' => $statusKey,
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
        'entity_field' => $bundleKey,
        'plugin_id' => 'bundle',
        'value' => [$bundle => $bundle],
      ];
    }

    return $out;
  }

  /**
   * The one sort a request names, on the entity's own created or label key.
   *
   * @return array<string, array<string, mixed>>
   *   Views sort handlers, keyed by id; empty when the entity has no such key.
   */
  private function sorts(string $entityType, string $base, string $sort): array {
    $definition = $this->entityTypeManager->getDefinition($entityType, FALSE);
    $labelKey = $definition?->getKey('label');
    $byTitle = in_array($sort, ['title_az', 'title_za'], TRUE);
    $field = $byTitle ? (is_string($labelKey) && $labelKey !== '' ? $labelKey : NULL) : 'created';

    if ($field === NULL) {
      return [];
    }

    return [
      $field => [
        'id' => $field,
        'table' => $base,
        'field' => $field,
        'entity_type' => $entityType,
        'entity_field' => $field,
        'plugin_id' => $byTitle ? 'standard' : 'date',
        'order' => in_array($sort, ['oldest', 'title_az'], TRUE) ? 'ASC' : 'DESC',
      ],
    ];
  }

}
