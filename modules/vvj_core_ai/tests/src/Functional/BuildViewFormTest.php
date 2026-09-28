<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The whole panel, end to end: Views list, form, AI, view, messages.
 *
 * The AI is the stub provider from vvj_core_ai_stub, so no request leaves the
 * test and it costs nothing. Everything else is real: the form, the drafter's
 * checks, the creator, Views' validation, the save and the redirect.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class BuildViewFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'block',
    'node',
    'views',
    'views_ui',
    'vvj_core',
    'vvjtest',
    'vvj_core_ai',
    'vvj_core_ai_stub',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    // Stark shows no local actions unless their block is placed.
    $this->drupalPlaceBlock('local_actions_block');
    $this->config('ai.settings')->set('default_providers', [
      'chat' => ['provider_id' => 'stub', 'model_id' => 'stub-model'],
    ])->save();
  }

  /**
   * From the Views list to a saved, disabled view with a block and a page.
   */
  public function testTheFormCreatesTheViewTheWayItSays(): void {
    $this->container->get('state')->set('vvj_core_ai_stub.answer', [
      'label' => 'Latest articles',
      'fields' => ['title', 'not_a_field'],
      'sort' => 'newest',
    ]);
    $this->drupalLogin($this->drupalCreateUser(['use vvj views assistant', 'administer views', 'access content']));

    $this->drupalGet('admin/structure/views');
    $this->clickLink('Build a VVJ view with AI');
    $this->assertSession()->addressEquals('admin/structure/views/vvj-ai');

    $this->submitForm([
      'format' => 'views_vvjtest',
      'bundle' => 'article',
      'count' => 4,
      'words' => 'The newest articles, title only.',
      'page' => TRUE,
      'path' => 'latest',
    ], 'Create the view');

    // Redirected to the new view, and told what happened.
    $this->assertSession()->addressEquals('admin/structure/views/view/latest_articles');
    $this->assertSession()->pageTextContains('The view is DISABLED');
    $this->assertSession()->pageTextContains('showing 4 item(s) with no pager');
    $this->assertSession()->pageTextContains('It also has a page at /latest');
    $this->assertSession()->pageTextContains('Each item shows: title');

    $view = View::load('latest_articles');
    self::assertNotNull($view);
    self::assertFalse($view->status());
    $displays = $view->get('display');
    self::assertIsArray($displays);
    self::assertSame(['default', 'block_1', 'page_1'], array_keys($displays));
    self::assertSame(4, $displays['default']['display_options']['pager']['options']['items_per_page'] ?? NULL);
    self::assertSame(['title'], array_keys($displays['default']['display_options']['fields'] ?? []), 'The invented field was dropped.');

    // The model was told what the format is for.
    $sent = $this->container->get('state')->get('vvj_core_ai_stub.sent');
    self::assertIsString($sent);
    self::assertStringContainsString('Answer only with the properties', $sent);
  }

  /**
   * A refusal from the model keeps the person on the form, with the reason.
   */
  public function testRefusalIsShownAndNothingIsSaved(): void {
    $this->container->get('state')->set('vvj_core_ai_stub.answer', [
      'label' => 'Nothing real',
      'fields' => ['not_a_field'],
    ]);
    $this->drupalLogin($this->drupalCreateUser(['use vvj views assistant', 'administer views', 'access content']));
    $before = count(View::loadMultiple());

    $this->drupalGet('admin/structure/views/vvj-ai');
    $this->submitForm(['format' => 'views_vvjtest', 'bundle' => 'article', 'words' => 'Ghosts.'], 'Create the view');

    $this->assertSession()->addressEquals('admin/structure/views/vvj-ai');
    $this->assertSession()->pageTextContains('no fields that exist on this content type');
    self::assertCount($before, View::loadMultiple());
  }

  /**
   * A request that can only be refused never reaches the paid AI call.
   */
  public function testPathInUseIsRefusedWithoutCallingTheModel(): void {
    $this->container->get('state')->set('vvj_core_ai_stub.answer', ['label' => 'Would be', 'fields' => ['title']]);
    $this->drupalLogin($this->drupalCreateUser(['use vvj views assistant', 'administer views', 'access content']));

    $this->drupalGet('admin/structure/views/vvj-ai');
    $this->submitForm([
      'format' => 'views_vvjtest',
      'bundle' => 'article',
      'words' => 'The newest articles.',
      'page' => TRUE,
      'path' => 'user/login',
    ], 'Create the view');

    $this->assertSession()->pageTextContains('The path /user/login is already used on this site.');
    self::assertNull($this->container->get('state')->get('vvj_core_ai_stub.sent'), 'The model was never asked.');
  }

  /**
   * Without both permissions there is no page and no button.
   */
  public function testItNeedsBothPermissions(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer views', 'access content']));
    $this->drupalGet('admin/structure/views/vvj-ai');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/structure/views');
    $this->assertSession()->linkNotExists('Build a VVJ view with AI');
  }

  /**
   * With no chat provider the page says where to choose one.
   */
  public function testWithoutProviderThePageSaysWhere(): void {
    $this->config('ai.settings')->set('default_providers', [])->save();
    $this->drupalLogin($this->drupalCreateUser(['use vvj views assistant', 'administer views', 'access content']));
    $this->drupalGet('admin/structure/views/vvj-ai');
    $this->assertSession()->pageTextContains('No default AI provider is set for chat');
    $this->assertSession()->buttonNotExists('Create the view');
  }

}
