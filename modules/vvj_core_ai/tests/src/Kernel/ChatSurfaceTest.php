<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\vvj_core_ai\Service\ChatSurfaceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every reason the Views chat panel stays hidden, in words.
 *
 * WHY EACH REFUSAL IS ASSERTED SEPARATELY. Five preconditions gate the panel
 * and not one of them is visible from the Views UI. A person who turns this on
 * and sees nothing has no way to tell which one is missing — so each refusal
 * has to name itself, and a test that only checked `show === FALSE` would let
 * a useless message through.
 *
 * The panel is NOT expected to show in these tests: ai_chatbot and
 * ai_assistant_api are not dependencies of this module and are not installed
 * here. That is the point of the first refusals — the module has to behave on
 * a site that has only what it actually requires.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class ChatSurfaceTest extends KernelTestBase {

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
    'vvj_core_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'vvj_core_ai']);
  }

  /**
   * Without the permission, the panel is hidden and says so.
   *
   * Checked first, and refused first, so that someone without the permission
   * is never told about the site's AI configuration through the reason text.
   */
  public function testWithoutThePermissionItSaysSo(): void {
    $this->setUpCurrentUser(permissions: ['access content']);

    $decision = $this->surface()->decide();

    self::assertFalse($decision->show);
    self::assertStringContainsString('permission', $decision->reason);
  }

  /**
   * Without ai_chatbot, the reason names ai_chatbot.
   */
  public function testWithoutTheChatbotModuleItNamesIt(): void {
    $this->setUpCurrentUser(permissions: [ChatSurfaceInterface::PERMISSION]);

    $decision = $this->surface()->decide();

    self::assertFalse($decision->show);
    self::assertStringContainsString('ai_chatbot', $decision->reason);
  }

  /**
   * Nothing is rendered when the panel is hidden.
   *
   * A half-built container with no chat inside would leave an empty box on
   * every Views page — visible, useless, and hard to trace back to here.
   */
  public function testHiddenPanelRendersNothingAtAll(): void {
    $this->setUpCurrentUser(permissions: [ChatSurfaceInterface::PERMISSION]);

    self::assertSame([], $this->surface()->build());
  }

  /**
   * Every refusal is a sentence someone can act on.
   *
   * The whole purpose of carrying a reason is defeated by a reason that reads
   * like an error code.
   */
  public function testEveryRefusalReadsAsPlainSentence(): void {
    $surface = $this->surface();

    foreach ([['access content'], [ChatSurfaceInterface::PERMISSION]] as $permissions) {
      $this->setUpCurrentUser(permissions: $permissions);
      $reason = $surface->decide()->reason;

      self::assertGreaterThan(30, strlen($reason), 'Too terse to act on: ' . $reason);
      self::assertMatchesRegularExpression('/[.]$/', $reason, 'Not a sentence: ' . $reason);
    }
  }

  /**
   * The panel never writes configuration just by being asked about itself.
   *
   * Deciding whether to show a panel is a read. If asking the question altered
   * the site's configuration, the settings page — which asks on every load —
   * would rewrite config on each visit.
   */
  public function testDecidingWritesNoConfiguration(): void {
    $this->setUpCurrentUser(permissions: [ChatSurfaceInterface::PERMISSION]);
    $before = $this->config('vvj_core_ai.settings')->getRawData();

    $surface = $this->surface();
    $surface->decide();
    $surface->build();

    self::assertSame($before, $this->config('vvj_core_ai.settings')->getRawData());
  }

  /**
   * The shipped setting is empty, and that is the intended resting state.
   *
   * An assistant cannot be shipped: the entity names an llm_provider and
   * llm_model that exist on one site only, so a default would install
   * something that looks configured and fails on first use.
   */
  public function testItShipsWithNoAssistantChosen(): void {
    self::assertSame('', $this->config('vvj_core_ai.settings')->get('assistant'));
  }

  /**
   * The surface, typed.
   *
   * The container returns `object`, so every property read off a decision was
   * `mixed` and the assertions were testing types rather than behaviour.
   *
   * @return \Drupal\vvj_core_ai\Service\ChatSurfaceInterface
   *   The surface.
   */
  private function surface(): ChatSurfaceInterface {
    // \Drupal::service() and not $this->container->get(): on Drupal 12
    // KernelTestBase types $container as ?ContainerBuilder, so every call
    // through it is a null-call. Measured on drupal.org CI 2026-09-05 — ten
    // findings across six test classes, in the `phpstan (next major)` job
    // only, because Drupal 11 does not type it that way. The static accessor
    // has no nullable receiver, so the whole class of error is gone by
    // construction rather than suppressed by an assertion.
    $surface = \Drupal::service(ChatSurfaceInterface::class);
    self::assertInstanceOf(ChatSurfaceInterface::class, $surface);

    return $surface;
  }

}
