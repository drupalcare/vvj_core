<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns\Theme;

use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Default {@see VvjThemeBridgeInterface} implementation.
 *
 * Mirrors Paragraphs Bundles' PbThemeBridgeService: the active theme and
 * then its base themes are walked in order, and the first APEX, Solo or W3CSS
 * name decides the bridge.
 */
final class VvjThemeBridge implements VvjThemeBridgeInterface {

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveBridgeId(): string {
    return $this->bridgeIdFromTheme($this->themeManager->getActiveTheme());
  }

  /**
   * Maps an active theme and its base themes to a bridge id.
   *
   * @param \Drupal\Core\Theme\ActiveTheme $theme
   *   The active theme.
   *
   * @return string
   *   One of the interface's BRIDGE_* constants.
   */
  private function bridgeIdFromTheme(ActiveTheme $theme): string {
    foreach ($this->themeChain($theme) as $name) {
      if ($name === 'apex_theme' || str_starts_with($name, 'apex_')) {
        return self::BRIDGE_APEX;
      }
      if ($name === 'solo' || str_starts_with($name, 'solo_')) {
        return self::BRIDGE_SOLO;
      }
      // The W3CSS theme's machine name; its sub-themes (amun, amunet, anhur,
      // a site sub-theme) reach it through the base-theme chain.
      if ($name === 'drupal8_w3css_theme') {
        return self::BRIDGE_W3CSS;
      }
    }
    return self::BRIDGE_NEUTRAL;
  }

  /**
   * Lists the active theme name followed by its base theme names.
   *
   * @param \Drupal\Core\Theme\ActiveTheme $theme
   *   The active theme.
   *
   * @return list<string>
   *   Theme machine names.
   */
  private function themeChain(ActiveTheme $theme): array {
    $chain = [$theme->getName()];
    foreach (array_keys($theme->getBaseThemeExtensions()) as $base) {
      $chain[] = (string) $base;
    }
    return $chain;
  }

}
