<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\Component\Plugin\PluginManagerInterface;
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
   * The tool ids the agent switches on.
   *
   * @var list<string>
   */
  private array $toolIds = [];

  /**
   * The tool ids that have a settings block.
   *
   * @var list<string>
   */
  private array $settingsKeys = [];

  /**
   * The agent's system prompt.
   */
  private string $prompt = '';

  /**
   * The modules the agent config is enforced-dependent on.
   *
   * @var list<string>
   */
  private array $enforcedModules = [];

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

    $this->toolIds = self::enabledKeys($parsed['tools'] ?? NULL);
    $this->settingsKeys = self::stringKeys($parsed['tool_settings'] ?? NULL);

    $prompt = $parsed['system_prompt'] ?? '';
    $this->prompt = is_string($prompt) ? $prompt : '';

    $dependencies = $parsed['dependencies'] ?? NULL;
    $enforced = is_array($dependencies) ? ($dependencies['enforced'] ?? NULL) : NULL;
    $this->enforcedModules = self::stringList(is_array($enforced) ? ($enforced['module'] ?? NULL) : NULL);
  }

  /**
   * Every tool the agent claims is a plugin that actually exists.
   */
  public function testEveryToolTheAgentListsExists(): void {
    $available = array_keys($this->toolManager()->getDefinitions());

    self::assertNotEmpty($this->toolIds, 'An agent with no tools is an agent that does nothing.');

    foreach ($this->toolIds as $id) {
      self::assertContains($id, $available, sprintf('The agent lists "%s", which is not a plugin.', $id));
    }
  }

  /**
   * It ships every tool the module has, not a subset that drifted.
   */
  public function testItShipsEveryToolTheModuleHas(): void {
    $ours = [];

    foreach (array_keys($this->toolManager()->getDefinitions()) as $id) {
      if (is_string($id) && str_starts_with($id, 'vvj_formats:')) {
        $ours[] = $id;
      }
    }

    $listed = $this->toolIds;
    sort($ours);
    sort($listed);
    self::assertSame($ours, $listed, 'A tool the module ships but the agent omits is unreachable.');
  }

  /**
   * Every listed tool has settings, because ai_agents reads them per tool.
   */
  public function testEveryToolHasItsSettings(): void {
    foreach ($this->toolIds as $id) {
      self::assertContains($id, $this->settingsKeys, sprintf('No settings for "%s".', $id));
    }
  }

  /**
   * The prompt carries the two facts a model cannot work out for itself.
   */
  public function testThePromptCarriesTheRulesThatMatter(): void {
    $prompt = $this->prompt;

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
      $this->enforcedModules,
      'Without an enforced dependency the agent outlives the module that defines its tools.',
    );
  }

  /**
   * The plugin manager, narrowed through a core interface.
   *
   * \Drupal::service() returns object, and drupal/ai's own manager class
   * cannot be named here — it is absent whenever the project is built from its
   * own composer.json, which is what drupal.org CI does. PluginManagerInterface
   * is core, is always resolvable, and declares getDefinitions() through
   * DiscoveryInterface, so this narrows the type honestly instead of hiding the
   * problem behind an exclusion.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   The function-call plugin manager.
   */
  private function toolManager(): PluginManagerInterface {
    $manager = \Drupal::service('plugin.manager.ai.function_calls');

    if (!$manager instanceof PluginManagerInterface) {
      self::fail('The function-call plugin manager is not a plugin manager.');
    }

    return $manager;
  }

  /**
   * The ids of every tool switched on, as strings.
   *
   * Decoded YAML is mixed all the way down. Walking it with real checks costs
   * a few lines and means nothing downstream has to be asserted about.
   *
   * @param mixed $value
   *   The decoded `tools` mapping, or anything else.
   *
   * @return list<string>
   *   The enabled ids.
   */
  private static function enabledKeys(mixed $value): array {
    $out = [];

    if (is_array($value)) {
      foreach ($value as $key => $enabled) {
        if (is_string($key) && $enabled) {
          $out[] = $key;
        }
      }
    }

    return $out;
  }

  /**
   * Every key of a decoded mapping, as strings.
   *
   * @param mixed $value
   *   The decoded mapping, or anything else.
   *
   * @return list<string>
   *   The keys.
   */
  private static function stringKeys(mixed $value): array {
    $out = [];

    if (is_array($value)) {
      foreach (array_keys($value) as $key) {
        if (is_string($key)) {
          $out[] = $key;
        }
      }
    }

    return $out;
  }

  /**
   * A decoded list, as strings.
   *
   * @param mixed $value
   *   The decoded list, or anything else.
   *
   * @return list<string>
   *   The strings in it.
   */
  private static function stringList(mixed $value): array {
    $out = [];

    if (is_array($value)) {
      foreach ($value as $item) {
        if (is_string($item)) {
          $out[] = $item;
        }
      }
    }

    return $out;
  }

}
