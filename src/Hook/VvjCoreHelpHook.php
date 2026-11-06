<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Help hook for vvj_core.
 */
final class VvjCoreHelpHook {

  use StringTranslationTrait;

  /**
   * Implements hook_help() for the vvj_core help route.
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.vvj_core') {
      return NULL;
    }
    $output = '<h2>' . $this->t('About') . '</h2>';
    $output .= '<p>' . $this->t('The VVJ Core module provides the shared foundation for the VVJ Views formats: accordion, basic carousel, 3D carousel, 3D flip cards, hero, lightbox, parallax, reveal, slideshow and tabs. It is installed automatically with any VVJ module and has no settings of its own. For more information, see the <a href=":project">online documentation for the VVJ Core module</a>.', [
      ':project' => 'https://www.drupal.org/project/vvj_core',
    ]) . '</p>';
    $output .= '<h2>' . $this->t('Uses') . '</h2>';
    $output .= '<dl>';
    $output .= '<dt>' . $this->t('Using a VVJ format') . '</dt>';
    $output .= '<dd>' . $this->t('Install one of the VVJ modules, then in the Views administration pages (<em>Structure</em> > <em>Views</em>) edit a view and choose that module under <em>Format</em>.') . '</dd>';
    $output .= '<dt>' . $this->t('Offering ready-made Patterns') . '</dt>';
    $output .= '<dd>' . $this->t('The VVJ Patterns sub-module is the shared base for the ready-made designs each VVJ module offers in the <em>Patterns</em> section of its format settings.') . '</dd>';
    $output .= '<dt>' . $this->t('Adding example content') . '</dt>';
    $output .= '<dd>' . $this->t('The VVJ Patterns Demo sub-module adds the VVJ Slide content type, its fields and image styles, and five demo slides with original pictures.') . '</dd>';
    $output .= '<dt>' . $this->t('Building a VVJ view with AI') . '</dt>';
    $output .= '<dd>' . $this->t('The optional VVJ Core AI sub-module adds <em>Build a VVJ view with AI</em> to the Views administration page. Choose a VVJ format and a content type and describe what to show; it creates a new view, disabled and as a block, for you to check. It never edits an existing view.') . '</dd>';
    $output .= '</dl>';
    return $output;
  }

}
