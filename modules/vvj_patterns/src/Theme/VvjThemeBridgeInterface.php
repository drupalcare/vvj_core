<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns\Theme;

/**
 * Resolves which Patterns theme-bridge library matches the active theme.
 */
interface VvjThemeBridgeInterface {

  /**
   * Bridge identifier for APEX and APEX sub-themes.
   */
  public const string BRIDGE_APEX = 'apex';

  /**
   * Bridge identifier for Solo and Solo sub-themes.
   */
  public const string BRIDGE_SOLO = 'solo';

  /**
   * Bridge identifier for every other theme (black and white stays).
   */
  public const string BRIDGE_NEUTRAL = 'neutral';

  /**
   * Resolves the bridge id from the active theme and its base themes.
   *
   * @return string
   *   One of {@see self::BRIDGE_APEX}, {@see self::BRIDGE_SOLO} or
   *   {@see self::BRIDGE_NEUTRAL}.
   */
  public function resolveBridgeId(): string;

}
