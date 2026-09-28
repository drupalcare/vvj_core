<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\vvj_core_ai\Model\DraftedView;

/**
 * Asks the model for the parts of a view a person described in words.
 *
 * IT NEVER CHOOSES A FORMAT, A CONTENT TYPE, A ROW OR A COUNT: those arrive
 * from the panel. The model answers inside a schema built from THIS site's
 * fields, so a field it invents has nowhere to go.
 */
interface ViewDrafterInterface {

  /**
   * A name, the fields to show, and the sort.
   *
   * @param string $format
   *   Views style plugin id, for the brief the model is given.
   * @param string $entityType
   *   Entity type, usually `node`.
   * @param string $bundle
   *   Content type.
   * @param bool $withFields
   *   FALSE for a view-mode row: the model is asked for no fields.
   * @param string $words
   *   What the person asked for.
   *
   * @return \Drupal\vvj_core_ai\Model\DraftedView
   *   The draft, or why there is none.
   */
  public function draft(string $format, string $entityType, string $bundle, bool $withFields, string $words): DraftedView;

}
