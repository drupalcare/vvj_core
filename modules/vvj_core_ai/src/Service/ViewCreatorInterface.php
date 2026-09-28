<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\vvj_core_ai\Model\ViewRequest;

/**
 * Creates a new view using a VVJ format. The only thing here that writes.
 *
 * THREE RULES IT CANNOT BE CONFIGURED OUT OF:
 * 1. A new view is created DISABLED; a person looks at it and enables it.
 * 2. An existing view is never touched; a name in use is a refusal.
 * 3. Every precondition is re-checked here, and the finished view goes
 *    through Views' own validate() before it is saved — so a format's own
 *    rules (Hero's image first, Parallax's image URL) decide, not a copy.
 */
interface ViewCreatorInterface {

  /**
   * Refusals that are certain before anything is asked of the model.
   *
   * The form runs this before the AI call, so a request that can only be
   * refused costs nothing; create() runs it again and never trusts the form.
   *
   * @param \Drupal\vvj_core_ai\Model\ViewRequest $request
   *   What to build. Label and fields are not needed yet.
   *
   * @return array<'format'|'view_mode'|'path', string>
   *   A plain sentence keyed by the part of the request it is about, or an
   *   empty array when nothing is known to be wrong.
   */
  public function precheck(ViewRequest $request): array;

  /**
   * Creates the view: a block by default, a page too when a path is given.
   *
   * @param \Drupal\vvj_core_ai\Model\ViewRequest $request
   *   What to build.
   *
   * @return array{created: true, id: string, label: string, format: string, refusals: list<string>, notes: list<string>}|array{created: false, refusals: list<string>, notes: list<string>}
   *   What was built, or why nothing was.
   */
  public function create(ViewRequest $request): array;

}
