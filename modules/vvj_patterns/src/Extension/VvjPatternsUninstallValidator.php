<?php

declare(strict_types=1);

namespace Drupal\vvj_patterns\Extension;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Refuses to uninstall VVJ Patterns while a style it ships is still used.
 *
 * The five shared image styles stay on the site after the module is gone, so
 * the check lists where each one is used: view field formatters, entity view
 * and form displays, and responsive image style mappings.
 */
final class VvjPatternsUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * Config entity types searched for a use of an image style.
   */
  private const SEARCHED_TYPES = [
    'view',
    'entity_view_display',
    'entity_form_display',
    'responsive_image_style',
  ];

  /**
   * Setting keys that hold an image style id.
   */
  private const STYLE_KEYS = [
    'image_style',
    'preview_image_style',
    'image_mapping',
    'fallback_image_style',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigManagerInterface $configManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'vvj_patterns') {
      return [];
    }
    $reasons = [];
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    foreach ($this->shippedStyleIds() as $id) {
      if (!isset($styles[$id])) {
        continue;
      }
      $users = $this->usersOf($id);
      if ($users) {
        $reasons[] = $this->t('The image style %style is used in: @where. Change those first, then uninstall.', [
          '%style' => $styles[$id]->label() . ' (' . $id . ')',
          '@where' => implode(', ', $users),
        ]);
      }
    }
    return $reasons;
  }

  /**
   * Returns the ids of the image styles this module ships.
   *
   * @return string[]
   *   Image style ids, read from the module's config/optional folder.
   */
  private function shippedStyleIds(): array {
    $path = $this->moduleExtensionList->getPath('vvj_patterns') . '/config/optional';
    $ids = [];
    foreach (glob($path . '/image.style.*.yml') ?: [] as $file) {
      $ids[] = substr(basename($file, '.yml'), strlen('image.style.'));
    }
    return $ids;
  }

  /**
   * Finds the config entities that use an image style.
   *
   * @return string[]
   *   Sorted, unique labels such as "views.view.vvj_demo_vvjs", one per user.
   */
  private function usersOf(string $style_id): array {
    $found = [];
    // Anything that records a config dependency on the style.
    foreach ($this->configManager->findConfigEntityDependencies('config', ['image.style.' . $style_id]) as $entity) {
      $found[$entity->getConfigDependencyName()] = $entity->getConfigDependencyName();
    }
    // A formatter or mapping can name the style without a recorded dependency.
    foreach (self::SEARCHED_TYPES as $type) {
      if (!$this->entityTypeManager->hasDefinition($type)) {
        continue;
      }
      foreach ($this->entityTypeManager->getStorage($type)->loadMultiple() as $entity) {
        if ($entity instanceof ConfigEntityInterface && $this->mentions($entity->toArray(), $style_id)) {
          $found[$entity->getConfigDependencyName()] = $entity->getConfigDependencyName();
        }
      }
    }
    $found = array_values($found);
    sort($found);
    return $found;
  }

  /**
   * Tells whether a style id appears as a style setting anywhere in a value.
   */
  private function mentions(mixed $value, string $style_id): bool {
    if (!is_array($value)) {
      return FALSE;
    }
    foreach ($value as $key => $item) {
      if (is_string($item) && $item === $style_id && in_array($key, self::STYLE_KEYS, TRUE)) {
        return TRUE;
      }
      if (is_array($item) && $this->mentions($item, $style_id)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
