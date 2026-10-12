<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\FunctionalJavascript;

use Drupal\Core\File\FileExists;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
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

    // WITHOUT THIS THE EXAMPLE VIEW DOES NOT EXIST, and every subclass fails on
    // `assert($view !== NULL)` below rather than on anything it was written to
    // check. Each pattern ships its example view as OPTIONAL config, and
    // optional config installs only once its dependencies already exist. The
    // view depends on `node.type.article`, which nothing supplies at module
    // install time: it left the standard profile for a recipe, and the testing
    // profile never had it. So the type has to be created first — the lines
    // above — and the optional config installed again now that it can be.
    //
    // The Functional install tests already carry this fix and pass; this base
    // class did not, so 20 FunctionalJavascript tests across ten pattern
    // modules were failing on modules that are themselves correct.
    // AND THE IMAGE FIELD HAS TO EXIST BEFORE THAT CALL TOO, for exactly the
    // reason the paragraph above gives about the content type. The four
    // image-based patterns — hero, lightbox, parallax, reveal — ship example
    // views that depend on `field.storage.node.field_image` and an image style.
    // This method used to run AFTER installOptionalConfig(), so at the moment
    // the installer looked, the field did not exist, the view was skipped as
    // having unmet dependencies, and those four subclasses then failed on
    // `assert($view !== NULL)` — never reaching the behaviour they were written
    // to test. The other six patterns pass because their views need no image.
    if ($this->vvjRequiresArticleImageField()) {
      $this->vvjEnsureArticleFieldImage();
    }

    $this->container->get('config.installer')->installOptionalConfig();

    if ($this->vvjRequiresArticleImageField()) {
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

    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->assertNotNull($anonymous);
    $anonymous->grantPermission('access content')->save();
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
    // NOT statusCodeEquals(). A WebDriver test drives a real browser through
    // DrupalSelenium2Driver, which cannot report an HTTP status code at all —
    // it throws UnsupportedDriverActionException. This was invisible until the
    // optional-config fix let these tests get past setUp and reach it.
    // Checking for the error page is the equivalent that does work here, and a
    // page that failed to build would fail the assertions below in any case.
    $this->assertSession()->pageTextNotContains(
      'The website encountered an unexpected error',
    );
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
    // NOT statusCodeEquals(). A WebDriver test drives a real browser through
    // DrupalSelenium2Driver, which cannot report an HTTP status code at all —
    // it throws UnsupportedDriverActionException. This was invisible until the
    // optional-config fix let these tests get past setUp and reach it.
    // Checking for the error page is the equivalent that does work here, and a
    // page that failed to build would fail the assertions below in any case.
    $this->assertSession()->pageTextNotContains(
      'The website encountered an unexpected error',
    );
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
   * No view transition is started while the page is hidden.
   *
   * Measured 2026-09-28: in a hidden tab `withTransition()` started one and
   * the browser rejected it — "Transition was aborted because of invalid
   * state. Document hidden" — uncaught, every autoplay tick. The state change
   * must still happen; only the animation is skipped. The page is made to
   * report itself hidden, because the test browser is visible.
   */
  public function testNoTransitionWhileThePageIsHidden(): void {
    $this->drupalGet('<front>');
    $tag = $this->vvjCustomElementTag();
    $this->assertJsCondition("document.querySelector('" . $tag . "') instanceof Drupal.Vvj.ElementBase");

    $result = $this->getSession()->evaluateScript(
      "(function () {"
      . " Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});"
      . " let ran = false;"
      . " const t = document.querySelector('" . $tag . "').withTransition(() => { ran = true; });"
      . " delete document.hidden;"
      . " return {started: t !== null, ran: ran};"
      . "})()"
    );

    self::assertIsArray($result);
    self::assertFalse($result['started'] ?? NULL, 'No view transition while hidden.');
    self::assertTrue($result['ran'] ?? NULL, 'The state change still happens.');
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
    // image-test.png, NOT image-test-transparent.png. The latter does not exist
    // in core and, as far as this repository is concerned, never did — it is
    // absent from both the Drupal 11 and Drupal 12 checkouts on this machine.
    // The assertFileExists() below was therefore failing every time this method
    // ran, and nobody saw it because these FunctionalJavascript tests have never
    // executed here: with no WebDriver listening on :4444 the whole suite errored
    // at setUp with "Could not connect", long before reaching this line.
    //
    // Nothing here asserts transparency; the pattern smoke tests only need a
    // real PNG to attach to an article. image-test.png ships in 10, 11 and 12.
    $path = \Drupal::root() . '/core/tests/fixtures/files/image-test.png';
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
