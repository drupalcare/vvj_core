<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\vvj_core_ai\Model\ViewRequest;
use Drupal\vvj_core_ai\Service\ViewCreatorInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Creating a view, and every way it must refuse instead.
 *
 * WHY THE MUTATION PROBE IS IN HERE. A model can skip vvj_resolve_view and hand
 * the creator whatever it invented, so the creator re-runs the planner itself —
 * that one call is the entire safety model. MEASURED: stub the guard out and
 * five of these nine fail (revalidation, existing name, uninstalled format,
 * unknown fields, no permission), because that guard is the single gate every
 * refusal passes through. testItRevalidatesInsteadOfTrustingItsCaller is the
 * one written for it: the others would each also pass with a separate check
 * bolted on locally, and this one only passes when the planner is consulted.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class ViewCreatorTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'views',
    'views_ui',
    'vvj_core',
    'vvjtest',
    // The drafter takes drupal/ai's provider manager, and the module declares
    // ai:ai, so the container cannot compile without it.
    'key',
    'ai',
    'vvj_core_ai',
  ];

  /**
   * The service under test.
   */
  private ViewCreatorInterface $creator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);

    $this->creator = \Drupal::service(ViewCreatorInterface::class);
  }

  /**
   * A complete request creates a view — DISABLED.
   *
   * Rule 1: a view that appears on a live site the moment a machine invents it
   * is the failure this guards against.
   */
  public function testItCreatesTheViewDisabled(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Featured work', ['title']));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    $view = $this->load(is_string($result['id'] ?? NULL) ? $result['id'] : '');
    self::assertFalse($view->status(), 'A new view must never be live on creation.');
    self::assertStringContainsString('DISABLED', implode(' ', $result['notes']));
  }

  /**
   * The created view actually uses the VVJ format and the chosen fields.
   *
   * Creating a view that does not use the format asked for would be the worst
   * outcome here: it reports success and produces the wrong thing.
   */
  public function testTheViewUsesTheFormatAndFieldsAsked(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Case studies', ['title', 'created']));
    $display = $this->displayOf($result);

    self::assertSame('views_vvjtest', $this->arr($display['style'] ?? NULL)['type'] ?? NULL);
    self::assertSame(['title', 'created'], array_keys($this->arr($display['fields'] ?? NULL)));
    self::assertSame('fields', $this->arr($display['row'] ?? NULL)['type'] ?? NULL);
  }

  /**
   * Only published content is listed by default.
   *
   * A view showing unpublished content to anonymous visitors is a disclosure,
   * not a display preference, so it is never the default.
   */
  public function testItFiltersToPublishedContent(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Published only', ['title']));
    $filters = $this->arr($this->displayOf($result)['filters'] ?? NULL);

    self::assertArrayHasKey('status', $filters);
    self::assertSame('1', $this->arr($filters['status'])['value'] ?? NULL);
  }

  /**
   * Seeing the view needs "access content", as core's own wizard sets it.
   *
   * Left unset, Views falls back to no access check at all.
   */
  public function testItNeedsAccessContent(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Guarded', ['title']));
    $access = $this->arr($this->displayOf($result)['access'] ?? NULL);

    self::assertSame('perm', $access['type'] ?? NULL);
    self::assertSame('access content', $this->arr($access['options'] ?? NULL)['perm'] ?? NULL);
  }

  /**
   * A name already taken gets the next free one; the original is untouched.
   *
   * It used to be a refusal — after a paid AI call, over a name the person
   * never chose. Rule 2 still holds: someone's view is never overwritten.
   */
  public function testTakenNameGetsTheNextFreeOne(): void {
    $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Taken name', ['title']));
    $before = $this->load('taken_name')->toArray();

    $second = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Taken name', ['title']));

    self::assertTrue($second['created'], implode(' | ', $second['refusals']));
    self::assertSame('taken_name_2', $second['id'] ?? NULL);
    self::assertSame($before, $this->load('taken_name')->toArray(), 'The original must be untouched.');
  }

  /**
   * THE MUTATION PROBE for the creator's own re-validation.
   *
   * A model that never called vvj_resolve_view can hand the creator a bundle
   * that does not exist. Without the creator's own check, a view is built
   * against nothing and reports success. See the class docblock for what the
   * measured mutation actually breaks.
   */
  public function testItRevalidatesInsteadOfTrustingItsCaller(): void {
    $before = count(View::loadMultiple());

    $result = $this->creator->create(new ViewRequest('node', 'no_such_bundle', 'vvjtest', 'Bad bundle', ['title']));

    self::assertFalse($result['created'], 'An unknown bundle must be refused.');
    self::assertStringContainsString('no "no_such_bundle" content type', implode(' ', $result['refusals']));
    self::assertCount($before, View::loadMultiple(), 'A refusal must create nothing.');
  }

  /**
   * A format this site does not have is refused, naming what it does have.
   */
  public function testAnUninstalledFormatIsRefusedByName(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjs', 'Slideshow please', ['title']));

    self::assertFalse($result['created']);
    self::assertStringContainsString('does not have', implode(' ', $result['refusals']));
    self::assertStringContainsString('views_vvjtest', implode(' ', $result['refusals']),
      'The refusal must name what IS available.');
  }

  /**
   * Fields that do not exist on the bundle are refused, with the real list.
   */
  public function testUnknownFieldsAreRefusedWithTheRealOnes(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Bad fields', ['not_a_field']));

    self::assertFalse($result['created']);
    self::assertStringContainsString('not fields on that content', implode(' ', $result['refusals']));
  }

  /**
   * Without the permission, nothing is created and the answer is permission.
   */
  public function testWithoutPermissionNothingIsCreated(): void {
    $this->setUpCurrentUser(permissions: ['access content']);
    $creator = \Drupal::service(ViewCreatorInterface::class);
    $before = count(View::loadMultiple());

    $result = $creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Not allowed', ['title']));

    self::assertFalse($result['created']);
    self::assertStringContainsString('administer views', implode(' ', $result['refusals']));
    self::assertCount($before, View::loadMultiple());
  }

  /**
   * An unknown bundle is never then described as though it existed.
   *
   * MEASURED on sts.local 2026-09-04, and only visible in a live run: the
   * planner said "There is no apex_case_study content type" and, in the next
   * sentence, "Available on apex_case_study: changed, created, status, title".
   * Those four are node's BASE fields, which getFieldDefinitions() returns for
   * any bundle name at all — so the tool contradicted itself and handed the
   * model a field list for a content type that does not exist.
   */
  public function testAnUnknownBundleIsNotThenDescribed(): void {
    $result = $this->creator->create(new ViewRequest('node', 'no_such_bundle', 'vvjtest', 'Ghost', []));
    $said = implode(' ', $result['refusals']);

    self::assertStringContainsString('no "no_such_bundle" content type', $said);
    self::assertStringNotContainsString('Available on no_such_bundle', $said,
      'It must not list fields for a content type it just said is absent.');
    self::assertStringNotContainsString('not fields on that content', $said,
      'Nor validate fields against a bundle that does not exist.');
  }

  /**
   * A near-miss bundle name gets one suggestion, not a shrug.
   *
   * MEASURED in a live model conversation on sts.local 2026-09-04: the person
   * asked for "the FAQs", the model sent `faq`, the site has `apex_faq`, and
   * the refusal listed six types and let the model ask a question whose answer
   * was already on screen.
   */
  public function testNearMissBundleIsSuggested(): void {
    NodeType::create(['type' => 'apex_faq', 'name' => 'FAQ'])->save();

    $result = $this->creator->create(new ViewRequest('node', 'faq', 'vvjtest', 'Faqs', ['title']));

    self::assertFalse($result['created']);
    self::assertStringContainsString('Did you mean "apex_faq"?', implode(' ', $result['refusals']));
  }

  /**
   * Two equally plausible matches produce no suggestion at all.
   *
   * Offering a list of guesses is not help, and a wrong single guess the model
   * then acts on is worse than none. The suggestion is deliberately only made
   * when exactly one candidate fits.
   */
  public function testAnAmbiguousNearMissSuggestsNothing(): void {
    NodeType::create(['type' => 'apex_faq', 'name' => 'One'])->save();
    NodeType::create(['type' => 'legacy_faq', 'name' => 'Two'])->save();

    $result = $this->creator->create(new ViewRequest('node', 'faq', 'vvjtest', 'Faqs', ['title']));

    self::assertFalse($result['created']);
    self::assertStringNotContainsString('Did you mean', implode(' ', $result['refusals']));
  }

  /**
   * Every refusal is a sentence a person can act on.
   */
  public function testEveryRefusalReadsAsPlainSentence(): void {
    $result = $this->creator->create(new ViewRequest('node', '', '', '', []));

    self::assertFalse($result['created']);
    self::assertNotEmpty($result['refusals']);

    foreach ($result['refusals'] as $refusal) {
      self::assertGreaterThan(25, strlen($refusal), 'Too terse to act on: ' . $refusal);
      self::assertMatchesRegularExpression('/[.?]$/', $refusal, 'Not a sentence: ' . $refusal);
    }
  }

  /**
   * A block by default, a fixed count and no pager — the maintainer's call.
   *
   * 2026-09-27: "block by default". Before this the creator made only the
   * default display, which cannot be placed anywhere.
   */
  public function testItMakesBlockWithFixedCountAndNoPage(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Block only', ['title'], count: 7));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    $displays = $this->arr($this->load((string) ($result['id'] ?? ''))->get('display'));
    self::assertSame(['default', 'block_1'], array_keys($displays));
    self::assertSame('block', $this->arr($displays['block_1'] ?? NULL)['display_plugin'] ?? NULL);
    $pager = $this->arr($this->displayOf($result)['pager'] ?? NULL);
    self::assertSame('some', $pager['type'] ?? NULL, 'A fixed number, no page links.');
    self::assertSame(7, $this->arr($pager['options'] ?? NULL)['items_per_page'] ?? NULL);
  }

  /**
   * A page only when a path is given, at that path.
   */
  public function testPageIsAddedOnlyWithPath(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'With a page', ['title'], pagePath: '/our-work/'));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    $displays = $this->arr($this->load((string) ($result['id'] ?? ''))->get('display'));
    $page = $this->arr($displays['page_1'] ?? NULL);
    self::assertSame('page', $page['display_plugin'] ?? NULL);
    self::assertSame('our-work', $this->arr($page['display_options'] ?? NULL)['path'] ?? NULL);
  }

  /**
   * The features arrive as the format's own options.
   *
   * `row_class` because the test format ships no schema of its own; a real
   * format's options are held to its schema by FeaturesContractTest.
   */
  public function testFeaturesAreWrittenAsStyleOptions(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Featured', ['title'], styleOptions: ['row_class' => 'made-by-ai']));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    self::assertSame('made-by-ai', $this->arr($this->arr($this->displayOf($result)['style'] ?? NULL)['options'] ?? NULL)['row_class'] ?? NULL);
  }

  /**
   * A fields-only format refuses a view mode instead of silently switching.
   *
   * VvjStylePluginBase forces fields for every format that does not opt out,
   * and the test format does not.
   */
  public function testFieldsOnlyFormatRefusesViewMode(): void {
    $before = count(View::loadMultiple());
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Teasers', [], viewMode: 'teaser'));

    self::assertFalse($result['created']);
    self::assertStringContainsString('shows fields, not whole items', implode(' ', $result['refusals']));
    self::assertCount($before, View::loadMultiple());
  }

  /**
   * An image-first format on a type with no image field is refused up front.
   *
   * Before the AI call, so the request costs nothing; Article here has no
   * image field, and Hero needs one first.
   */
  public function testImageFirstWithoutImageIsRefusedBeforeDrafting(): void {
    $refusals = $this->creator->precheck(new ViewRequest('node', 'article', 'views_vvjh', ''));

    self::assertSame(['format'], array_keys($refusals));
    self::assertStringContainsString('needs an image field', $refusals['format']);
  }

  /**
   * A page path the site already answers is refused; a free one is not.
   */
  public function testPathInUseIsRefused(): void {
    \Drupal::service('router.builder')->rebuild();

    $taken = $this->creator->precheck(new ViewRequest('node', 'article', 'vvjtest', '', pagePath: '/user/login'));
    self::assertSame(['path'], array_keys($taken));
    self::assertStringContainsString('/user/login is already used', $taken['path']);

    self::assertSame([], $this->creator->precheck(new ViewRequest('node', 'article', 'vvjtest', '', pagePath: 'vvj-free-path')));
  }

  /**
   * The block title, when given, is the title shown; the label stays the name.
   */
  public function testBlockTitleIsTheTitleShown(): void {
    $result = $this->creator->create(new ViewRequest('node', 'article', 'vvjtest', 'Named', ['title'], blockTitle: 'Latest news'));

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    self::assertSame('Latest news', $this->displayOf($result)['title'] ?? NULL);
    self::assertSame('Named', $result['label']);
  }

  /**
   * A configurable field is read from its own table, not the base one.
   *
   * Measured 2026-09-27 on sts.local: every field was given the base table,
   * so an image field got a handler with no field behind it — Hero refused
   * the view and Slideshow would have printed a broken field.
   */
  public function testConfigurableFieldUsesItsOwnTable(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Subtitle',
    ])->save();

    $result = $this->creator->create(
      new ViewRequest('node', 'article', 'vvjtest', 'Subtitles', ['title', 'field_subtitle']),
    );

    self::assertTrue($result['created'], implode(' | ', $result['refusals']));
    $fields = $this->arr($this->displayOf($result)['fields'] ?? NULL);
    self::assertSame('node_field_data', $this->arr($fields['title'] ?? NULL)['table'] ?? NULL);
    self::assertSame('node__field_subtitle', $this->arr($fields['field_subtitle'] ?? NULL)['table'] ?? NULL);
  }

  /**
   * The default display's options for a view the creator just made.
   *
   * Narrowing in one place rather than at every assertion: `View::load()` is
   * nullable and `get('display')` is mixed, and a test that walks that
   * structure blind reports a confusing type error instead of the failure it
   * was written to catch.
   *
   * @param array<string, mixed> $result
   *   A result from ViewCreator::create().
   *
   * @return array<string|int, mixed>
   *   The default display's display_options.
   */
  private function displayOf(array $result): array {
    if (($result['created'] ?? FALSE) !== TRUE) {
      $refusals = array_map(
        static fn(mixed $r): string => is_string($r) ? $r : '',
        $this->arr($result['refusals'] ?? NULL),
      );
      self::fail('Expected a view. Refusals: ' . implode(' | ', $refusals));
    }

    $id = $result['id'] ?? NULL;
    $displays = $this->arr($this->load(is_string($id) ? $id : '')->get('display'));

    return $this->arr($this->arr($displays['default'] ?? NULL)['display_options'] ?? NULL);
  }

  /**
   * A view that must exist.
   *
   * @param string $id
   *   The machine name.
   *
   * @return \Drupal\views\Entity\View
   *   The view.
   */
  private function load(string $id): View {
    $view = View::load($id);

    if (!$view instanceof View) {
      self::fail(sprintf('The view "%s" does not exist.', $id));
    }

    return $view;
  }

  /**
   * A value as an array.
   *
   * @param mixed $value
   *   The value.
   *
   * @return array<string|int, mixed>
   *   The array, or [].
   */
  private function arr(mixed $value): array {
    return is_array($value) ? $value : [];
  }

}
