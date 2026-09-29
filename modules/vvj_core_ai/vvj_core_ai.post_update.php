<?php

/**
 * @file
 * Post update functions for VVJ Core AI.
 */

declare(strict_types=1);

/**
 * Removes the configuration the retired chat tools left behind.
 *
 * The chat tools were replaced by the "Build a VVJ view with AI" page. A site
 * that ran the earlier development version still holds their settings and,
 * where AI Agents is installed, their agent, which names tools that no longer
 * exist. Neither is read by anything now.
 */
function vvj_core_ai_post_update_remove_chat_tools_config(): string {
  $removed = [];
  $config = \Drupal::configFactory();

  if (!$config->get('vvj_core_ai.settings')->isNew()) {
    $config->getEditable('vvj_core_ai.settings')->delete();
    $removed[] = 'vvj_core_ai.settings';
  }

  $types = \Drupal::entityTypeManager();

  if ($types->hasDefinition('ai_agent')) {
    $agent = $types->getStorage('ai_agent')->load('vvj_core_agent');

    if ($agent !== NULL) {
      $agent->delete();
      $removed[] = 'ai_agents.ai_agent.vvj_core_agent';
    }
  }
  elseif (!$config->get('ai_agents.ai_agent.vvj_core_agent')->isNew()) {
    $config->getEditable('ai_agents.ai_agent.vvj_core_agent')->delete();
    $removed[] = 'ai_agents.ai_agent.vvj_core_agent';
  }

  return $removed === []
    ? (string) t('Nothing to remove: the retired chat tools left no configuration on this site.')
    : (string) t('Removed the retired chat tools configuration: @names.', ['@names' => implode(', ', $removed)]);
}
