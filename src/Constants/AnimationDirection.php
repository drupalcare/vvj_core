<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Constants;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Standard animation-direction presets shared across VVJ patterns.
 *
 * Pattern modules use these CSS class suffixes (`a-fade`, `a-top`, etc.)
 * which are matched by the corresponding CSS keyframes in each module's
 * stylesheet. Using a shared set keeps animation behavior consistent
 * across accordion / slideshow / lightbox / etc.
 */
final class AnimationDirection {

  use StringTranslationTrait;

  /**
   * No animation.
   */
  public const string NONE = 'none';

  /**
   * Cross-fade animation.
   */
  public const string FADE = 'a-fade';

  /**
   * Scale-in zoom animation.
   */
  public const string ZOOM = 'a-zoom';

  /**
   * Slide in from the top.
   */
  public const string TOP = 'a-top';

  /**
   * Slide in from the bottom.
   */
  public const string BOTTOM = 'a-bottom';

  /**
   * Slide in from the left.
   */
  public const string LEFT = 'a-left';

  /**
   * Slide in from the right.
   */
  public const string RIGHT = 'a-right';

  /**
   * Default animation direction across the suite.
   */
  public const string DEFAULT_VALUE = self::NONE;

  /**
   * All known animation-direction keyword values.
   *
   * @var list<string>
   */
  public const array ALL_VALUES = [
    self::NONE,
    self::FADE,
    self::ZOOM,
    self::TOP,
    self::BOTTOM,
    self::LEFT,
    self::RIGHT,
  ];

  /**
   * Get translatable animation-direction option list.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Animation key → translatable label.
   */
  public function getOptions(): array {
    return [
      self::NONE => $this->t('None'),
      self::FADE => $this->t('Fade'),
      self::ZOOM => $this->t('Zoom'),
      self::TOP => $this->t('Slide from Top'),
      self::BOTTOM => $this->t('Slide from Bottom'),
      self::LEFT => $this->t('Slide from Left'),
      self::RIGHT => $this->t('Slide from Right'),
    ];
  }

  private function __construct() {
  }

}
