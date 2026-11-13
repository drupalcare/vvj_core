<?php

/**
 * @file
 * Post-update functions for VVJ Patterns.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;

/**
 * Creates the shared VVJ image styles that a site is missing.
 */
function vvj_patterns_post_update_image_styles(): string {
  // The five styles used to ship in vvj_patterns_demo only. A style that
  // exists with the shipped effects is left alone, a missing one is created,
  // and one the site changed is never overwritten but is named in the result.
  $path = \Drupal::service('extension.list.module')->getPath('vvj_patterns') . '/config/optional';
  $source = new FileStorage($path);
  $storage = \Drupal::entityTypeManager()->getStorage('image_style');
  $created = [];
  $kept_different = [];

  foreach ($source->listAll('image.style.') as $name) {
    $data = $source->read($name);
    if (!is_array($data) || !isset($data['name'])) {
      continue;
    }
    $existing = $storage->load($data['name']);
    if ($existing === NULL) {
      $storage->createFromStorageRecord($data)->save();
      $created[] = $data['name'];
      continue;
    }
    if (_vvj_patterns_effect_signature($existing->toArray()['effects'] ?? []) !== _vvj_patterns_effect_signature($data['effects'] ?? [])) {
      $kept_different[] = $data['name'];
    }
  }

  $parts = [];
  $parts[] = 'Image styles created: ' . ($created ? implode(', ', $created) : 'none');
  if ($kept_different) {
    $parts[] = 'kept as the site has them (effects differ from the shipped ones): ' . implode(', ', $kept_different);
  }
  return implode('; ', $parts) . '.';
}

/**
 * Returns a comparable form of an effect list, ignoring the effect uuids.
 *
 * @param array<string, array<string, mixed>> $effects
 *   Effects as stored in an image style, keyed by uuid.
 *
 * @return array<int, array<string, mixed>>
 *   Effects sorted by weight then id, without uuid keys.
 */
function _vvj_patterns_effect_signature(array $effects): array {
  $list = [];
  foreach ($effects as $effect) {
    $data = is_array($effect['data'] ?? NULL) ? $effect['data'] : [];
    ksort($data);
    $list[] = [
      'id' => (string) ($effect['id'] ?? ''),
      'weight' => (int) ($effect['weight'] ?? 0),
      'data' => $data,
    ];
  }
  usort($list, static fn(array $a, array $b): int => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
  return $list;
}

/**
 * Creates the shared VVJ portrait image style.
 */
function vvj_patterns_post_update_image_style_portrait(): string {
  // Added for vvjb Dockrail, whose slides are drawn 3:4 up to 912x1216 px,
  // larger than any landscape or square style covers. Same create-or-keep
  // rules as the first image style update.
  return vvj_patterns_post_update_image_styles();
}
