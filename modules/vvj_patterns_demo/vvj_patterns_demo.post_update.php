<?php

/**
 * @file
 * Post-update functions for VVJ Patterns Demo.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;
use Drupal\views\Entity\View;

/**
 * Adds the responsive image styles to the shipped VVJ demo views.
 */
function vvj_patterns_demo_post_update_responsive_images(): string {
  // Sites that installed the demo before this release have neither the
  // responsive_image module nor the new styles, so both are added first.
  \Drupal::service('module_installer')->install(['responsive_image']);

  $path = \Drupal::service('extension.list.module')->getPath('vvj_patterns_demo') . '/config/optional';
  $source = new FileStorage($path);
  $entity_type_manager = \Drupal::entityTypeManager();

  // A style the site already has is never overwritten.
  $created = [];
  $types = [
    'image.style.' => 'image_style',
    'responsive_image.styles.' => 'responsive_image_style',
  ];
  foreach ($types as $prefix => $entity_type) {
    $storage = $entity_type_manager->getStorage($entity_type);
    foreach ($source->listAll($prefix) as $name) {
      $data = $source->read($name);
      $id = is_array($data) ? (string) ($data['id'] ?? $data['name'] ?? '') : '';
      if ($id === '') {
        continue;
      }
      if ($storage->load($id) === NULL) {
        $storage->createFromStorageRecord($data)->save();
        $created[] = $id;
      }
    }
  }

  // The formatter a view had when it shipped. A field that still has it moves
  // to the responsive formatter; a field the site changed is left alone.
  $shipped_styles = [
    'vvj_thumb' => 'vvj_thumb_240',
    'vvj_square' => 'vvj_square_800',
    'vvj_wide' => 'vvj_wide_1600',
    'vvj_landscape' => 'vvj_landscape_1200',
    'vvj_portrait' => 'vvj_portrait_1280',
    'vvj_cinema' => 'vvj_cinema_1680',
  ];
  $updated = 0;
  $kept = 0;
  foreach ($source->listAll('views.view.vvj_demo_') as $name) {
    $data = $source->read($name);
    $view = is_array($data) && isset($data['id']) ? View::load((string) $data['id']) : NULL;
    if ($view === NULL) {
      continue;
    }
    $displays = $view->get('display');
    $changed = FALSE;
    foreach ($data['display'] ?? [] as $display_id => $display) {
      foreach ($display['display_options']['fields'] ?? [] as $field_id => $field) {
        $wanted = $field['settings']['responsive_image_style'] ?? NULL;
        if (($field['type'] ?? '') !== 'responsive_image' || !isset($shipped_styles[$wanted])) {
          continue;
        }
        $current = $displays[$display_id]['display_options']['fields'][$field_id] ?? NULL;
        if (!is_array($current)) {
          continue;
        }
        if (($current['type'] ?? '') === 'image' && ($current['settings']['image_style'] ?? '') === $shipped_styles[$wanted]) {
          $displays[$display_id]['display_options']['fields'][$field_id]['type'] = 'responsive_image';
          $displays[$display_id]['display_options']['fields'][$field_id]['settings'] = $field['settings'];
          $changed = TRUE;
          $updated++;
        }
        elseif (($current['type'] ?? '') !== 'responsive_image') {
          $kept++;
        }
      }
    }
    if ($changed) {
      // Saving recalculates the view dependencies on the new formatter.
      $view->set('display', $displays);
      $view->save();
    }
  }

  return 'Styles created: ' . ($created ? implode(', ', $created) : 'none') . '; demo view fields moved to the responsive formatter: ' . $updated . '; fields kept as the site has them: ' . $kept . '.';
}
