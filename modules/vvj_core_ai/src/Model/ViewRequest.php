<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * Everything the creator needs to build one view, decided before it runs.
 *
 * Nothing here comes from a model unchecked: the format, the content type,
 * the row and the count come from the panel; the label, the fields and the
 * sort are the model's answer, held to what exists by the planner and the
 * drafter before the creator sees them.
 */
final readonly class ViewRequest {

  /**
   * The sorts a view can be given, by the name the drafter answers with.
   */
  public const SORTS = ['newest', 'oldest', 'title_az', 'title_za'];

  /**
   * Constructs a request.
   *
   * @param string $entityType
   *   Entity type the view lists, usually `node`.
   * @param string $bundle
   *   Content type, or '' for every type.
   * @param string $format
   *   VVJ style plugin id.
   * @param string $label
   *   Human name for the view.
   * @param list<string> $fields
   *   Fields to show, in order. Empty for a view-mode row.
   * @param string $viewMode
   *   View mode for a rendered-entity row, or '' for a fields row.
   * @param int $count
   *   How many items the block shows. No pager beyond that.
   * @param string $pagePath
   *   A path for an extra page display, or '' for the block alone.
   * @param string $sort
   *   One of self::SORTS.
   * @param array<string, bool|int|float|string> $styleOptions
   *   The format options the view is created with — its features.
   * @param string $blockTitle
   *   The title shown above the block, or '' to use the label.
   */
  public function __construct(
    public string $entityType,
    public string $bundle,
    public string $format,
    public string $label,
    public array $fields = [],
    public string $viewMode = '',
    public int $count = 6,
    public string $pagePath = '',
    public string $sort = 'newest',
    public array $styleOptions = [],
    public string $blockTitle = '',
  ) {}

}
