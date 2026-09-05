<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\vvj_core_ai\Model\SurfaceDecision;

/**
 * Decides whether the Views chat panel appears, and builds it.
 *
 * @see \Drupal\vvj_core_ai\Service\ChatSurface
 * @see docs/VVJ-AI-BRIEF.md §6
 */
interface ChatSurfaceInterface {

  /**
   * The permission that gates the panel.
   *
   * Separate from `administer views`, which the creating tool checks on every
   * call. This one grants a surface; that one grants the ability to act.
   */
  public const string PERMISSION = 'use vvj views assistant';

  /**
   * Whether the panel should appear here, and why not when it should not.
   *
   * @return \Drupal\vvj_core_ai\Model\SurfaceDecision
   *   The decision, carrying the reason when hidden.
   */
  public function decide(): SurfaceDecision;

  /**
   * The chat panel as a render array.
   *
   * @return array<string, mixed>
   *   The render array, or an empty array when the panel is not showing.
   */
  public function build(): array;

}
