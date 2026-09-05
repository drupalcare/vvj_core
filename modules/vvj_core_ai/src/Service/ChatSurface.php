<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\vvj_core_ai\Model\SurfaceDecision;

/**
 * Decides whether the Views chat panel appears, and builds it.
 *
 * WHY THIS IS A SERVICE AND NOT A HOOK BODY. Five preconditions gate the
 * panel, none of them visible from the Views UI, and a hook that returned
 * early five times would be untestable without rendering a whole page. Here
 * each refusal is one assertion.
 *
 * WHY NO ASSISTANT IS SHIPPED. It would be the obvious convenience, and it
 * cannot work: an `ai_assistant` entity requires an `llm_provider` and an
 * `llm_model`, both naming something specific to one site. A shipped default
 * would install an assistant pointing at a provider that may not exist — a
 * thing that appears configured and fails on first use, which is worse than an
 * empty setting that says what to do.
 *
 * IT NEVER WRITES CONFIGURATION. Placing this panel does not alter the Views
 * UI, the block layout, or the assistant. The only configuration it reads is
 * its own setting, and the only thing that writes that setting is a person on
 * the settings form.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
final class ChatSurface implements ChatSurfaceInterface {

  /**
   * The chat block shipped by ai_chatbot.
   */
  private const string BLOCK_ID = 'ai_deepchat_block';

  /**
   * The chat processor that routes a conversation to an assistant.
   *
   * Supplied by ai_assistant_api, whose presence is checked before this is
   * used. The block will not render without one.
   */
  private const string PROCESSOR_ID = 'ai_assistant_api_processor';

  /**
   * Constructs the surface.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads which assistant the site chose.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Checks for ai_chatbot and ai_assistant_api, neither of which is a
   *   dependency of this module.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Confirms the chosen assistant still exists.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Checked for the permission this module adds.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   Builds the chat block.
   * @param \Drupal\vvj_core_ai\Service\FormatRegistryInterface $formats
   *   Names the VVJ formats installed here; with none there is nothing for the
   *   assistant to build a view with.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly BlockManagerInterface $blockManager,
    private readonly FormatRegistryInterface $formats,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(): SurfaceDecision {
    // Permission first. Someone without it does not need to hear about the
    // site's AI configuration, and checking it last would leak that anyway
    // through the reason text.
    if (!$this->currentUser->hasPermission(self::PERMISSION)) {
      return SurfaceDecision::hide(
        'You do not have the "use the VVJ views assistant" permission.',
      );
    }

    if (!$this->moduleHandler->moduleExists('ai_chatbot')) {
      return SurfaceDecision::hide(
        'The AI Chatbot module (ai_chatbot, part of drupal/ai) is not enabled, so there is no chat panel to show.',
      );
    }

    if (!$this->moduleHandler->moduleExists('ai_assistant_api')) {
      return SurfaceDecision::hide(
        'The AI Assistant API module (ai_assistant_api, part of drupal/ai) is not enabled, so there is no assistant to talk to.',
      );
    }

    // A site with vvj_core but no format module installed has nothing for the
    // assistant to build a view with. Offering it here would invite a
    // conversation whose only possible outcome is a refusal.
    if ($this->formats->all() === []) {
      return SurfaceDecision::hide(
        'No VVJ display format is installed on this site, so there is nothing for the assistant to build a view with. Install one of the format modules — vvja, vvjs and the rest — first.',
      );
    }

    // Config values are mixed. Casting one is an assumption; this is a check.
    $setting = $this->configFactory
      ->get('vvj_core_ai.settings')
      ->get('assistant');
    $chosen = is_string($setting) ? trim($setting) : '';

    if ($chosen === '') {
      return SurfaceDecision::hide(
        'No assistant has been chosen yet. Create one at Configuration › AI › AI Assistants, then choose it on this page.',
      );
    }

    // An assistant that was chosen and later deleted is the case that would
    // otherwise fail as a broken block rather than as a hidden one.
    //
    // AND LOADING IT CAN THROW, which is not defensive theatre — it has now
    // been measured TWICE on sts.local, on two different properties, which is
    // why the catch is broad rather than aimed at one field:
    //
    //   - 2026-09-04, sibling module: an assistant whose `description` was
    //     null could not be constructed at all.
    //   - 2026-09-04, building THIS module's own test assistant: saving one
    //     without `llm_configuration` stored null, and every later load threw
    //     `Cannot assign null to property AiAssistant::$llm_configuration of
    //     type array`. The entity became permanently unloadable through the
    //     entity API and had to be repaired through the config factory.
    //
    // The contrib entity types these properties as non-nullable, so
    // EntityBase's constructor raises a TypeError before the storage can
    // return NULL. Config written by an older version, a partial import, a
    // programmatic save that omits a key, or another module's half-finished
    // save all produce it — and the second case above shows how ordinary that
    // is: it happened while simply creating an assistant.
    //
    // Unhandled, that TypeError would not just hide this panel — it would take
    // down every page it runs on, and this one runs on the Views admin pages.
    // A surface whose entire promise is "absent unless wanted" must not be able
    // to break the page it is a guest on. So the throw is caught HERE, around
    // this one statement, and turned into the same visible refusal as every
    // other missing precondition. It is never swallowed: the message goes into
    // the reason the settings page prints.
    try {
      $exists = $this->entityTypeManager
        ->getStorage('ai_assistant')
        ->load($chosen);
    }
    catch (\Throwable $error) {
      return SurfaceDecision::hide(sprintf(
        'The chosen assistant "%s" is stored but cannot be loaded (%s). Its configuration is damaged — re-save it, or choose another one.',
        $chosen,
        $error->getMessage(),
      ));
    }

    if ($exists === NULL) {
      return SurfaceDecision::hide(sprintf(
        'The chosen assistant "%s" no longer exists. Choose another one.',
        $chosen,
      ));
    }

    return SurfaceDecision::show($chosen);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $decision = $this->decide();

    if (!$decision->show) {
      return [];
    }

    // FOUR THINGS HERE ARE NOT OBVIOUS, all four measured on sts.local rather
    // than reasoned about, and every one of them failed silently.
    //
    // 1. The assistant is chosen through the chat PROCESSOR's `assistant_id`,
    //    not through a top-level key on the block. The block's own
    //    `ai_assistant` key exists but only feeds an ajax callback on its
    //    configuration form; setting it alone selects nothing.
    // 2. `chat_processor_plugin` has to be named explicitly. The block's
    //    defaultConfiguration() leaves it an empty string and its build()
    //    passes that straight to createInstance(), so the block's own defaults
    //    throw PluginNotFoundException on render.
    // 3. `placement` defaults to 'toolbar', which styles the widget for
    //    Drupal's toolbar and rendered 679px tall inline. 'bottom-right'
    //    floats it instead.
    // 4. `toggle_state` is set here for intent, and on this version of
    //    ai_chatbot it does NOTHING at this placement — measured on sts.local
    //    2026-09-04, not assumed. DeepChatFormBlock::build() does attach it to
    //    drupalSettings.ai_deepchat.toggle_state, but the only JS that reads a
    //    toggle_state is form-stream.js, which reads the OTHER block's
    //    drupalSettings.ai_chatbot. The sticky widget used for every non-
    //    toolbar placement initialises purely from
    //    localStorage.deepChatState_<chatId>, defaulting to closed when unset.
    //    So a first-time visitor gets a collapsed bubble, which is what is
    //    wanted; 'close' is kept because it states the intent and will take
    //    effect if contrib wires it up. Do not read it as a working control.
    //
    //    AND THE KEY IS NOT NAMESPACED: it is `deepChatState_bot1` for every
    //    deepchat block on the site, so opening the sibling module's node-form
    //    bar leaves this panel open too, and vice versa. That is a contrib
    //    limitation, not something this module can fix from here — worth
    //    knowing before treating an open panel as a bug in this code.
    $block = $this->blockManager->createInstance(self::BLOCK_ID, [
      'chat_processor_plugin' => self::PROCESSOR_ID,
      'plugin_configuration' => [
        'assistant_id' => $decision->assistant,
        'stream_output' => FALSE,
        'verbose_mode' => FALSE,
      ],
      'bot_name' => 'Views',
      'toggle_state' => 'close',
      'placement' => 'bottom-right',
      'collapse_minimal' => TRUE,
      'first_message' => 'Say what the view should list and how it should look — the content type, the fields, and which VVJ format. New views are created disabled for you to check.',
    ]);

    // The manager's return is only `object` to a static analyser, and a block
    // plugin that is not one would fail at ->build() rather than here.
    if (!$block instanceof BlockPluginInterface) {
      return [];
    }

    return [
      '#type' => 'container',
      '#weight' => 100,
      '#attributes' => ['class' => ['vvj-core-ai-chat']],
      'chat' => $block->build(),
      // The panel depends on a setting, an entity, the installed formats and
      // the user's permissions. Without these a cached page shows one person's
      // answer to another.
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => [
          'config:vvj_core_ai.settings',
          'config:ai_assistant_api.ai_assistant.' . $decision->assistant,
        ],
      ],
    ];
  }

}
