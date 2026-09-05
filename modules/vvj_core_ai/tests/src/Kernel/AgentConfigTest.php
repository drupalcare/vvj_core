<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vvj_core_ai\Traits\VvjOptionalModulesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The shipped agent, and whether it points at tools that exist.
 *
 * WHY THIS FILE EXISTS. A FunctionCall plugin reaches an assistant only through
 * ai_agents — ai_assistant_api builds its list from `actions_enabled`, which is
 * a different plugin type entirely. MEASURED on sts.local 2026-09-05: this
 * module shipped five working tools and no agent, so nothing on a site that
 * installed it could call any of them. The agent config is what makes the
 * module usable, which makes it worth testing rather than trusting.
 *
 * A tool id in that file that no longer matches a plugin id is drift that
 * produces an agent which loads, looks configured, and silently cannot do what
 * it advertises. Nothing else catches it: the YAML is valid, the agent
 * installs, and the failure only appears when a person asks.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class AgentConfigTest extends KernelTestBase {

  use VvjOptionalModulesTrait;

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
    'key',
    'ai',
    'vvj_core',
    'vvjtest',
    'vvj_core_ai',
  ];

  /**
   * The parsed agent configuration.
   *
   * @var array<string, mixed>
   */
  private array $agent;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->skipUnlessModuleAvailable(
      'ai',
      'The function-call plugin manager does not exist without it, so the agent\'s '
      . 'tool list cannot be checked here. Run this where drupal/ai is present.',
    );

    parent::setUp();

    $path = sprintf(
      '%s/config/optional/ai_agents.ai_agent.vvj_core_agent.yml',
      \Drupal::service('extension.list.module')->getPath('vvj_core_ai'),
    );

    self::assertFileExists($path, 'The module ships a default agent, or its tools reach nobody.');
    $parsed = Yaml::decode((string) file_get_contents($path));
    self::assertIsArray($parsed);
    $this->agent = $parsed;
  }

  /**
   * Every tool the agent claims is a plugin that actually exists.
   */
  public function testEveryToolTheAgentListsExists(): void {
    $manager = \Drupal::service('plugin.manager.ai.function_calls');
    $available = array_keys($manager->getDefinitions());
    $listed = array_keys(array_filter($this->agent['tools'] ?? []));

    self::assertNotEmpty($listed, 'An agent with no tools is an agent that does nothing.');

    foreach ($listed as $id) {
      self::assertContains($id, $available, sprintf('The agent lists "%s", which is not a plugin.', $id));
    }
  }

  /**
   * It ships every tool the module has, not a subset that drifted.
   */
  public function testItShipsEveryToolTheModuleHas(): void {
    $manager = \Drupal::service('plugin.manager.ai.function_calls');
    $ours = array_filter(
      array_keys($manager->getDefinitions()),
      static fn(string $id): bool => str_starts_with($id, 'vvj_formats:'),
    );
    $listed = array_keys(array_filter($this->agent['tools'] ?? []));

    sort($ours);
    sort($listed);
    self::assertSame($ours, $listed, 'A tool the module ships but the agent omits is unreachable.');
  }

  /**
   * Every listed tool has settings, because ai_agents reads them per tool.
   */
  public function testEveryToolHasItsSettings(): void {
    foreach (array_keys(array_filter($this->agent['tools'] ?? [])) as $id) {
      self::assertArrayHasKey($id, $this->agent['tool_settings'] ?? [], sprintf('No settings for "%s".', $id));
    }
  }

  /**
   * The prompt carries the two facts a model cannot work out for itself.
   */
  public function testThePromptCarriesTheRulesThatMatter(): void {
    $prompt = (string) ($this->agent['system_prompt'] ?? '');

    self::assertMatchesRegularExpression(
      '/disabled/i',
      $prompt,
      'A view is created disabled; a person told otherwise thinks it failed.',
    );
    self::assertMatchesRegularExpression(
      '/never name a format/i',
      $prompt,
      'Which formats exist is per-site, so naming one from memory is the core failure.',
    );
  }

  /**
   * It is enforced-dependent on this module, so it is removed with it.
   */
  public function testItIsTiedToThisModule(): void {
    self::assertSame(
      ['vvj_core_ai'],
      $this->agent['dependencies']['enforced']['module'] ?? [],
      'Without an enforced dependency the agent outlives the module that defines its tools.',
    );
  }

}
