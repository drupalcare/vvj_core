<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Tool;

/**
 * Reads a tool context without throwing when the model left it out.
 *
 * THE DEFECT THIS CLOSES, measured in a sibling project (apex_ai_bridge) on
 * 2026-09-03 by a test that expected a refusal and got an exception instead.
 * The pattern is carried here deliberately, because the cause is in
 * `drupal/ai` and core, not in either module.
 *
 * `Context::getContextValue()` throws `ContextException` when a context is
 * REQUIRED and carries no value. Optional contexts return NULL quietly, which
 * is why this goes unnoticed: every hand-run of a tool supplies the required
 * arguments, so the throwing branch never executes.
 *
 * A model is not so reliable. `FunctionCallBase::populateValues()` iterates
 * only over the arguments the model actually returned:
 *
 * @code
 * foreach ($output->getArguments() as $argument) {
 *   if (array_key_exists($property_name, $this->getContextDefinitions())) {
 *     $this->setContextValue($property_name, $argument->getValue());
 *   }
 * }
 * @endcode
 *
 * An omitted parameter is therefore never set, and the first read of it throws
 * from inside `execute()` — and an omitted argument is one of the most
 * ordinary things a model does.
 *
 * WORSE THAN THE EXCEPTION IS WHAT IT BYPASSES. The tools in this module
 * answer a missing argument in words, by design: that is the whole point of
 * `recommend_format`. Read a required context directly and the sentence can
 * never be reached, because reading the absent context throws first. This
 * trait is what keeps those refusals live.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
trait SafeContextTrait {

  /**
   * A context's value, or NULL when the caller did not supply one.
   *
   * @param string $name
   *   The context name.
   *
   * @return mixed
   *   The value, or NULL when the context is absent or holds nothing.
   */
  protected function contextValue(string $name): mixed {
    $contexts = $this->getContexts();

    // Checked rather than caught: an empty catch would hide a genuinely
    // broken context definition behind the same NULL as an absent argument,
    // and those need different fixes.
    if (!isset($contexts[$name]) || !$contexts[$name]->hasContextValue()) {
      return NULL;
    }

    return $contexts[$name]->getContextValue();
  }

}
