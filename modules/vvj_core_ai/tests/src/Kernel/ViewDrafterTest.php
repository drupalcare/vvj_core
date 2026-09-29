<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\vvj_core_ai\Service\ViewDrafter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The model names the view and picks fields and sort — held to what exists.
 *
 * No request leaves the test: the provider answers with a fixed document and
 * records what it was sent.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class ViewDrafterTest extends KernelTestBase {

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
    'key',
    'ai',
    'vvj_core_ai',
  ];

  /**
   * The last request the stub provider received.
   */
  private ?ChatInput $sent = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'filter', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser(permissions: ['administer views']);
    $this->config('ai.settings')->set('default_providers', [
      'chat' => ['provider_id' => 'stub', 'model_id' => 'stub-model'],
    ])->save();
  }

  /**
   * Invented fields are dropped, the order and the sort are kept.
   */
  public function testTheAnswerIsHeldToTheRealFields(): void {
    $draft = $this->drafter([
      'label' => 'Latest articles',
      'fields' => ['created', 'no_such_field', 'title', 'title'],
      'sort' => 'oldest',
    ])->draft('views_vvjtest', 'node', 'article', TRUE, 'Latest articles, date and title.');

    self::assertTrue($draft->usable, implode(' | ', $draft->refusals));
    self::assertSame('Latest articles', $draft->label);
    self::assertSame(['created', 'title'], $draft->fields, 'Only real fields, once each, in the order asked.');
    self::assertSame('oldest', $draft->sort);

    // The model was offered only this site's fields, by name.
    $enum = $this->sent?->getChatStructuredJsonSchema()['schema']['properties']['fields']['items']['enum'] ?? [];
    self::assertContains('title', $enum);
    self::assertNotContains('no_such_field', $enum);
  }

  /**
   * A view-mode row asks for no fields at all.
   */
  public function testViewModeRowAsksForNoFields(): void {
    $draft = $this->drafter(['label' => 'Teasers', 'sort' => 'bogus'])
      ->draft('views_vvjtest', 'node', 'article', FALSE, 'The newest teasers.');

    self::assertTrue($draft->usable, implode(' | ', $draft->refusals));
    self::assertSame([], $draft->fields);
    self::assertSame('newest', $draft->sort, 'An unknown sort falls back to newest.');
    self::assertArrayNotHasKey('fields', $this->sent?->getChatStructuredJsonSchema()['schema']['properties'] ?? []);
  }

  /**
   * A label longer than the schema allows is cut, whatever the model sent.
   */
  public function testLongLabelIsCut(): void {
    $draft = $this->drafter(['label' => str_repeat('x', 200), 'fields' => ['title']])
      ->draft('views_vvjtest', 'node', 'article', TRUE, 'Anything.');

    self::assertSame(60, mb_strlen($draft->label));
  }

  /**
   * No name from the model is not a refusal: the format's name stands in.
   *
   * Measured live 2026-09-27: a paid answer with fields and a sort but no
   * label was thrown away.
   */
  public function testMissingLabelFallsBackToTheFormatName(): void {
    $draft = $this->drafter(['fields' => ['title'], 'sort' => 'newest'])
      ->draft('views_vvjtest', 'node', 'article', TRUE, 'The newest articles.');

    self::assertTrue($draft->usable, implode(' | ', $draft->refusals));
    self::assertNotSame('', $draft->label);
    self::assertSame(['label'], $this->sent?->getChatStructuredJsonSchema()['schema']['required'] ?? NULL);
  }

  /**
   * A timeout says what happened and where the limit is, not raw cURL text.
   */
  public function testTimeoutSaysWhereTheLimitIs(): void {
    $this->config('ai.settings')->set('request_timeout', 45)->save();
    $draft = $this->drafter(['throw' => 'cURL error 28: Operation timed out after 45001 milliseconds'])
      ->draft('views_vvjtest', 'node', 'article', TRUE, 'The newest articles.');

    self::assertFalse($draft->usable);
    $said = implode(' ', $draft->refusals);
    self::assertStringContainsString('took longer than this site allows (45 seconds)', $said);
    self::assertStringContainsString('Request timeout', $said);
    self::assertStringNotContainsString('cURL', $said);
  }

  /**
   * No field that exists means nothing to build, said plainly.
   */
  public function testNoRealFieldIsRefused(): void {
    $draft = $this->drafter(['label' => 'Ghosts', 'fields' => ['no_such_field']])
      ->draft('views_vvjtest', 'node', 'article', TRUE, 'Show the ghosts.');

    self::assertFalse($draft->usable);
    self::assertStringContainsString('no fields that exist', implode(' ', $draft->refusals));
  }

  /**
   * A drafter whose provider answers with a fixed document.
   *
   * @param array<string, mixed> $answer
   *   What the "model" says.
   *
   * @return \Drupal\vvj_core_ai\Service\ViewDrafter
   *   The drafter.
   */
  private function drafter(array $answer): ViewDrafter {
    $provider = new class($answer, $this) {

      public function __construct(
        private readonly array $answer,
        private readonly ViewDrafterTest $test,
      ) {}

      /**
       * Records the request and answers with the fixed document.
       */
      public function chat(ChatInput $input, string $model, array $tags): object {
        $this->test->record($input);

        // A test can make the provider fail the way a real one does.
        if (is_string($this->answer['throw'] ?? NULL)) {
          throw new \RuntimeException($this->answer['throw']);
        }

        $message = new ChatMessage('assistant', (string) json_encode($this->answer));

        return new class($message) {

          public function __construct(private readonly ChatMessage $message) {}

          /**
           * The answer, as a provider's output normalises it.
           */
          public function getNormalized(): ChatMessage {
            return $this->message;
          }

        };
      }

    };
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->willReturn($provider);

    $drafter = new ViewDrafter(
      $this->container->get('config.factory'),
      $manager,
      $this->container->get('vvj_core_ai.view_planner'),
      $this->container->get('entity_field.manager'),
      $this->container->get('vvj_core_ai.briefs'),
      $this->container->get('vvj_core_ai.format_registry'),
    );
    $drafter->setStringTranslation($this->container->get('string_translation'));

    return $drafter;
  }

  /**
   * Keeps the request the stub provider was sent.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The request.
   */
  public function record(ChatInput $input): void {
    $this->sent = $input;
  }

}
