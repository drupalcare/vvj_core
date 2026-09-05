<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Explains why a view using a VVJ format is not behaving as expected.
 *
 * Reads only. Views are loaded and executed — a SELECT — and never saved.
 *
 * @see \Drupal\vvj_core_ai\Service\ViewDoctor
 * @see docs/VVJ-AI-BRIEF.md §1
 */
interface ViewDoctorInterface {

  /**
   * Every view display on this site that uses a VVJ format.
   *
   * @return list<string>
   *   Entries like "featured_work:block_1 (views_vvjs)". Empty when no view
   *   uses one, which is a real state and not a failure.
   */
  public function usingVvj(): array;

  /**
   * Checks one view display.
   *
   * @param string $viewId
   *   The view's machine name.
   * @param string|null $displayId
   *   The display, or NULL for the first one using a VVJ format.
   *
   * @return list<\Drupal\vvj_core_ai\Model\ViewFinding>|null
   *   The findings, or NULL when there is no such view. Never empty: a sound
   *   view returns OK findings, because "nothing to report" and "I did not
   *   look" must not read the same.
   */
  public function diagnose(string $viewId, ?string $displayId = NULL): ?array;

}
