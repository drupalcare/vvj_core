<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vvj_core_ai\Traits\VvjOptionalModulesTrait;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every tool is discoverable, and the writing one behaves through the plugin.
 *
 * WHY THIS EXISTS, and it is not ceremony. MEASURED on 2026-09-03 in this same
 * module: ViewDoctor had a full passing test suite and NO plugin — the service
 * worked perfectly and no model could reach it. Service tests cannot catch
 * that, because the gap is between the service and the plugin manager.
 *
 * The second half is the same argument one layer up: ViewCreatorTest proves the
 * service refuses, and this proves the refusal survives the trip through
 * FunctionCallBase — where an absent argument throws instead of refusing unless
 * SafeContextTrait is used.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class ToolDiscoveryTest extends KernelTestBase {

  use UserCreationTrait;
  use VvjOptionalModulesTrait;

  /**
   * Every tool this module ships, by function name.
   *
   * Written out rather than derived from the plugin manager: a test that asks
   * the manager what exists and then checks that it exists passes on an empty
   * set, which is the failure it is supposed to catch.
   */
  private const TOOLS = [
    'vvj_recommend_format' => 'vvj_formats:recommend_format',
    'vvj_explain_settings' => 'vvj_formats:explain_settings',
    'vvj_diagnose_view' => 'vvj_formats:diagnose_view',
    'vvj_resolve_view' => 'vvj_formats:resolve_view',
    'vvj_create_view' => 'vvj_formats:create_view',
  ];

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
    'key',
    'ai',
    'vvj_core',
    'vvjtest',
    'vvj_core_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->skipUnlessModuleAvailable(
      'ai',
      'The function-call plugin manager does not exist without it, so tool discovery '
      . 'cannot be checked here. Run this where drupal/ai is present.',
    );

    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Every tool is registered with the id and function name it claims.
   */
  public function testEveryToolIsDiscoverable(): void {
    $definitions = \Drupal::service('plugin.manager.ai.function_calls')->getDefinitions();

    foreach (self::TOOLS as $functionName => $pluginId) {
      self::assertIsArray($definitions);
      self::assertArrayHasKey($pluginId, $definitions, sprintf(
        'The %s tool is not discoverable, so no model can call it.',
        $pluginId,
      ));
      self::assertSame($functionName, $this->definitionValue($definitions[$pluginId], 'function_name'));
      self::assertSame('vvj_formats', $this->definitionValue($definitions[$pluginId], 'group'));
    }
  }

  /**
   * A tool called with no arguments at all answers in words.
   *
   * This is the SafeContextTrait guarantee. Without it, reading an absent
   * context throws from inside execute() and the refusal is never reached —
   * and a model omitting an argument is one of the most ordinary things it
   * does.
   */
  public function testEveryToolAnswersWhenCalledWithNothing(): void {
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);

    foreach (self::TOOLS as $pluginId) {
      $tool = $this->tool($pluginId);
      $tool->execute();
      $output = $tool->getReadableOutput();

      self::assertNotSame('', trim((string) $output), $pluginId . ' returned nothing.');
      self::assertGreaterThan(40, strlen((string) $output), $pluginId . ' answered too tersely to act on.');
    }
  }

  /**
   * Calling create with nothing creates nothing, through the plugin.
   */
  public function testCreateViewWithNoArgumentsWritesNothing(): void {
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);
    $before = count(View::loadMultiple());

    $tool = $this->tool('vvj_formats:create_view');
    $tool->execute();

    self::assertCount($before, View::loadMultiple(), 'A bare call must not create a view.');
    self::assertStringContainsString('No view was created', (string) $tool->getReadableOutput());
  }

  /**
   * A complete call through the plugin creates a disabled view.
   *
   * The other half of the same argument: proving it refuses is only half a
   * test if it never succeeds either.
   */
  public function testCompleteCallCreatesTheViewDisabled(): void {
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);

    $tool = $this->tool('vvj_formats:create_view');
    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('bundle', 'article');
    $tool->setContextValue('format', 'vvjtest');
    $tool->setContextValue('fields', ['title']);
    $tool->setContextValue('label', 'Tool made this');
    $tool->execute();

    $view = View::load('tool_made_this');
    self::assertNotNull($view, 'Output was: ' . $tool->getReadableOutput());
    self::assertFalse($view->status());
    self::assertSame('views_vvjtest', $this->arr($this->displayOf($view)['style'] ?? NULL)['type'] ?? NULL);
  }

  /**
   * The fields argument survives arriving as a bare comma-separated string.
   *
   * Ai's ListConverter normally splits it, but it does not always run — see
   * ViewArgumentsTrait. A tool that only works when the converter ran is a
   * tool that breaks in the one place it cannot be watched.
   */
  public function testFieldsArriveEitherAsListOrCommaString(): void {
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);

    $tool = $this->tool('vvj_formats:create_view');
    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('bundle', 'article');
    $tool->setContextValue('format', 'vvjtest');
    $tool->setContextValue('fields', 'title, created');
    $tool->setContextValue('label', 'Csv fields');
    $tool->execute();

    $view = View::load('csv_fields');
    self::assertNotNull($view, 'Output was: ' . $tool->getReadableOutput());
    self::assertSame(
      ['title', 'created'],
      array_keys($this->arr($this->displayOf($view)['fields'] ?? NULL)),
    );
  }

  /**
   * Resolve_view echoes every value create_view needs — not most of them.
   *
   * MEASURED in the sibling module: a resolve tool printed "use exactly these
   * values" and omitted one, so the model refilled it from the conversation
   * and wrote to the wrong place.
   */
  public function testResolveEchoesEveryValueTheWriterNeeds(): void {
    $this->setUpCurrentUser(permissions: ['administer views', 'access content']);

    $tool = $this->tool('vvj_formats:resolve_view');
    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('bundle', 'article');
    $tool->setContextValue('format', 'vvjtest');
    $tool->setContextValue('fields', ['title']);
    $tool->setContextValue('label', 'Echo check');
    $output = $this->executeTool($tool);

    foreach (['node', 'article', 'views_vvjtest', 'title', 'Echo check'] as $value) {
      self::assertStringContainsString($value, $output, sprintf(
        'resolve_view did not echo "%s", so the model must supply it from memory.',
        $value,
      ));
    }

    self::assertStringNotContainsString('Created', $output, 'resolve_view must never write.');
  }

  /**
   * One value from a plugin definition, however the manager returns it.
   *
   * Ai's function-call manager yields definitions as arrays; other managers
   * yield attribute objects. Reading only one shape makes this test fail on a
   * change that is not a defect in this module.
   *
   * @param mixed $definition
   *   The plugin definition, in whatever shape the manager returns it.
   * @param string $key
   *   The value to read.
   *
   * @return mixed
   *   The value, or NULL when absent.
   */
  private function definitionValue(mixed $definition, string $key): mixed {
    if (is_array($definition)) {
      return $definition[$key] ?? NULL;
    }

    return is_object($definition) ? ($definition->{$key} ?? NULL) : NULL;
  }

  /**
   * Executes a tool and returns its output.
   *
   * @param \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool
   *   The tool plugin.
   *
   * @return string
   *   Readable output.
   */
  private function executeTool(ExecutableFunctionCallInterface $tool): string {
    $tool->execute();

    return (string) $tool->getReadableOutput();
  }

  /**
   * A tool plugin that can actually be run.
   *
   * `createInstance()` returns `object`. Asserting the interface narrows that
   * and states something the test cares about: a discovered plugin that cannot
   * be executed is not a usable tool.
   *
   * @param string $pluginId
   *   The plugin id.
   *
   * @return \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface
   *   The tool.
   */
  private function tool(string $pluginId): ExecutableFunctionCallInterface {
    $tool = \Drupal::service('plugin.manager.ai.function_calls')->createInstance($pluginId);

    self::assertInstanceOf(ExecutableFunctionCallInterface::class, $tool, $pluginId . ' cannot be executed.');

    return $tool;
  }

  /**
   * The default display's options for a view.
   *
   * `get('display')` is mixed and several levels deep. Narrowing once here
   * keeps the assertions about what they are testing rather than about types.
   *
   * @param \Drupal\views\Entity\View $view
   *   The view.
   *
   * @return array<string|int, mixed>
   *   The default display's display_options.
   */
  private function displayOf(View $view): array {
    $displays = $this->arr($view->get('display'));

    return $this->arr($this->arr($displays['default'] ?? NULL)['display_options'] ?? NULL);
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
