<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * User for VVJ pattern modules whose optional example view uses fields.
 *
 * Required in the test class static $modules (plus the pattern module):
 * system, user, field, file, filter, text, image, node, views, vvj_core.
 */
trait VvjArticleImageExampleKernelTrait {

  /**
   * Installs default field stack and optional config for one pattern module.
   *
   * Call from setUp() after parent::setUp(). Imports optional example view when
   * its dependencies (body + field_image + image styles) are satisfied.
   *
   * @param string $patternModule
   *   Machine name: vvjh, vvjl, vvjp, or vvjr.
   */
  protected function vvjKernelInstallArticleImageExampleEnvironment(string $patternModule): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'field',
      'file',
      'filter',
      'image',
      'node',
      'text',
    ]);

    if (NodeType::load('article') === NULL) {
      NodeType::create([
        'type' => 'article',
        'name' => 'Article',
        'display_submitted' => FALSE,
      ])->save();
    }

    if (FieldStorageConfig::loadByName('node', 'body') === NULL) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'settings' => [],
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    if (FieldConfig::loadByName('node', 'article', 'body') === NULL) {
      $storage = FieldStorageConfig::loadByName('node', 'body');
      assert($storage !== NULL);
      FieldConfig::create([
        'field_storage' => $storage,
        'bundle' => 'article',
        'label' => 'Body',
        'settings' => [
          'display_summary' => TRUE,
          'required_summary' => FALSE,
          'allowed_formats' => [],
        ],
      ])->save();
    }

    if (FieldStorageConfig::loadByName('node', 'field_image') === NULL) {
      FieldStorageConfig::create([
        'field_name' => 'field_image',
        'entity_type' => 'node',
        'type' => 'image',
        'settings' => [
          'target_type' => 'file',
          'display_field' => FALSE,
          'display_default' => FALSE,
          'uri_scheme' => 'public',
          'default_image' => [
            'uuid' => NULL,
            'alt' => '',
            'title' => '',
            'width' => NULL,
            'height' => NULL,
          ],
        ],
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    if (FieldConfig::loadByName('node', 'article', 'field_image') === NULL) {
      $storage = FieldStorageConfig::loadByName('node', 'field_image');
      assert($storage !== NULL);
      FieldConfig::create([
        'field_storage' => $storage,
        'bundle' => 'article',
        'label' => 'Image',
        'settings' => [
          'handler' => 'default:file',
          'handler_settings' => [],
          'file_directory' => '[date:custom:Y]-[date:custom:m]',
          'file_extensions' => 'png gif jpg jpeg webp',
          'max_filesize' => '',
          'max_resolution' => '',
          'min_resolution' => '',
          'alt_field' => TRUE,
          'alt_field_required' => TRUE,
          'title_field' => FALSE,
          'title_field_required' => FALSE,
          'default_image' => [
            'uuid' => NULL,
            'alt' => '',
            'title' => '',
            'width' => NULL,
            'height' => NULL,
          ],
        ],
      ])->save();
    }

    $this->installConfig([$patternModule]);
  }

}
