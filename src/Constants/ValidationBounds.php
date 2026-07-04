<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Constants;

/**
 * Validation bounds (min/max) shared across VVJ pattern modules.
 *
 * Each pattern's plugin uses these as `#min` / `#max` on numeric form
 * fields and as guards in custom validators. Defining them here keeps
 * the suite consistent — e.g., transition speeds always range 0.1-2.0
 * seconds across accordion / slideshow / carousel.
 */
final class ValidationBounds {

  /**
   * Transition / animation speed bounds (seconds).
   */
  public const float MIN_TRANSITION_SPEED = 0.1;
  public const float MAX_TRANSITION_SPEED = 2.0;

  /**
   * Auto-play / slide timing bounds (seconds).
   */
  public const float MIN_SLIDE_TIME = 1.0;
  public const float MAX_SLIDE_TIME = 60.0;

  /**
   * Pixel-dimension bounds (px).
   */
  public const int MIN_PIXEL_WIDTH = 0;
  public const int MAX_PIXEL_WIDTH = 9999;
  public const int MIN_PIXEL_HEIGHT = 0;
  public const int MAX_PIXEL_HEIGHT = 9999;

  /**
   * Padding / gap bounds (px).
   */
  public const int MIN_PADDING = 0;
  public const int MAX_PADDING = 200;

  /**
   * Opacity bounds (0.0 - 1.0).
   */
  public const float MIN_OPACITY = 0.0;
  public const float MAX_OPACITY = 1.0;

  /**
   * Random unique-ID range.
   *
   * 8-digit numeric IDs are stable + collision-resistant.
   */
  public const int MIN_UNIQUE_ID = 10000000;
  public const int MAX_UNIQUE_ID = 99999999;

  /**
   * Deeplink identifier max length (characters).
   */
  public const int DEEPLINK_IDENTIFIER_MAX_LENGTH = 20;

  /**
   * Reserved deeplink-identifier words that conflict with URL fragments.
   *
   * @var list<string>
   */
  public const array DEEPLINK_RESERVED_WORDS = ['accordion', 'panel', 'vvja', 'vvj'];

  /**
   * Token-name validation pattern.
   *
   * Allows alphanumeric + underscore, optional `:plain` suffix.
   * Same pattern v1 used per module — preserved for compatibility.
   */
  public const string TOKEN_PATTERN = '/^[a-zA-Z0-9_]+(:plain)?$/';

  private function __construct() {
  }

}
