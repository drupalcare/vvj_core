<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\FunctionalJavascript;

use Drupal\Core\File\FileExists;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\views\Entity\View;

/**
 * Base for “shipped example view” browser smoke (one test per VVJ pattern).
 *
 * Subclasses must set static ::$modules (including `block` and the pattern
 * module) and implement the abstract selectors.
 */
abstract class VvjPatternJavascriptTestBase extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Machine id of optional example view (e.g. `vvjb_example`).
   */
  abstract protected function vvjExampleViewId(): string;

  /**
   * Custom element tag to assert on the front page (e.g. `vvjb-carousel`).
   */
  abstract protected function vvjCustomElementTag(): string;

  /**
   * Whether the example view lists nodes with `field_image` (Hero, etc.).
   */
  protected function vvjRequiresArticleImageField(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);

    if ($this->vvjRequiresArticleImageField()) {
      $this->vvjEnsureArticleFieldImage();
      $this->vvjCreateArticleNodesWithImages(2);
    }
    else {
      foreach (range(1, 3) as $i) {
        Node::create([
          'type' => 'article',
          'title' => 'VVJ browser item ' . (string) $i,
          'status' => 1,
        ])->save();
      }
    }

    user_role_grant_permissions('anonymous', ['access content']);
    $this->vvjAddBlockDisplayToView($this->vvjExampleViewId());
    $this->drupalPlaceBlock('views_block:' . $this->vvjExampleViewId() . '-block_1', [
      'region' => 'content',
    ]);
  }

  /**
   * Shipped example view should output the pattern custom element in the block.
   */
  public function testShippedExampleViewRendersPatternCustomElement(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists(
      'css',
      $this->vvjCustomElementTag(),
    );
  }

  /**
   * The Drupal.Vvj namespace loads and the pattern registers its element.
   *
   * This asserts the contract introduced when VVJ moved from ES module
   * imports to Drupal core's namespace + library-dependency-ordering
   * pattern: `vvj_core` populates `Drupal.Vvj.*`, and each pattern reads
   * its base class off that namespace with load order guaranteed by the
   * library `dependencies:`. It does not depend on lazy hydration —
   * namespace population, custom-element registration, and element upgrade
   * all happen on parse, so this is a stable guard against a
   * dependency-ordering regression.
   */
  public function testVvjNamespaceAndCustomElementRegistration(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $tag = $this->vvjCustomElementTag();

    // vvj_core/element-base ran first and populated the shared namespace.
    $this->assertJsCondition(
      "typeof Drupal === 'object'"
      . " && typeof Drupal.Vvj === 'object'"
      . " && typeof Drupal.Vvj.ElementBase === 'function'",
    );

    // The pattern registered its custom element — only possible if
    // Drupal.Vvj.ElementBase was defined when the pattern script ran.
    $this->assertJsCondition(
      "typeof customElements.get('" . $tag . "') === 'function'",
    );

    // The server-rendered element upgraded to a subclass of the base.
    $this->assertJsCondition(
      "document.querySelector('" . $tag . "') instanceof Drupal.Vvj.ElementBase",
    );
  }

  /**
   * Adds `field_image` to `article` like default Drupal / optional view deps.
   */
  private function vvjEnsureArticleFieldImage(): void {
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
  }

  /**
   * Creates published article nodes with images for example Views.
   *
   * @param positive-int $count
   *   Number of published article nodes to create.
   */
  private function vvjCreateArticleNodesWithImages(int $count): void {
    $path = \Drupal::root() . '/core/tests/fixtures/files/image-test-transparent.png';
    $this->assertFileExists($path);
    $binary = (string) file_get_contents($path);
    $file = \Drupal::service('file.repository')->writeData(
      $binary,
      'public://vvj-js-smoke-' . uniqid('', TRUE) . '.png',
      FileExists::Replace,
    );

    foreach (range(1, $count) as $i) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'VVJ image item ' . (string) $i,
        'status' => 1,
        'field_image' => [
          'target_id' => $file->id(),
          'alt' => 'Alt text',
          'title' => '',
        ],
      ]);
      $node->save();
    }
  }

  /**
   * Adds a block display to the example view so it can be placed in a region.
   */
  private function vvjAddBlockDisplayToView(string $viewId): void {
    $view = View::load($viewId);
    assert($view !== NULL);
    $raw = $view->get('display');
    if (!is_array($raw)) {
      throw new \UnexpectedValueException(
        'View display configuration must be an array.',
      );
    }
    /** @var array<string, mixed> $displays */
    $displays = $raw;
    $displays['block_1'] = [
      'id' => 'block_1',
      'display_title' => 'Block',
      'display_plugin' => 'block',
      'position' => 1,
      'display_options' => [
        'display_extenders' => [],
      ],
    ];
    $view->set('display', $displays);
    $view->save();
  }

}
