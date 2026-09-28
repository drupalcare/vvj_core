<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai_stub;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * A provider manager whose one provider answers from state.
 *
 * The answer is whatever a test put in `vvj_core_ai_stub.answer`; the last
 * request is kept in `vvj_core_ai_stub.sent` so a test can read what the
 * model would have been asked.
 */
final class StubProviderManager implements PluginManagerInterface {

  /**
   * Constructs the manager.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Holds the fixed answer.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $state = $this->state;

    return new class($state) {

      public function __construct(private readonly StateInterface $state) {}

      /**
       * Records the request and answers with the fixed document.
       */
      public function chat(ChatInput $input, string $model, array $tags): object {
        $this->state->set('vvj_core_ai_stub.sent', $input->getSystemPrompt());
        $message = new ChatMessage('assistant', (string) json_encode($this->state->get('vvj_core_ai_stub.answer', [])));

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
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return ['id' => 'stub'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return ['stub' => ['id' => 'stub']];
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $plugin_id === 'stub';
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return FALSE;
  }

}
