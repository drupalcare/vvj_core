<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\vvj_core_ai\Service\ChatSurfaceInterface;

/**
 * Puts the chat panel on the Views administration pages.
 *
 * WHY page_bottom AND NOT form_alter. The sibling module hangs its bar off the
 * node form because that is where sections are added. Views is not one form —
 * it is a listing, an add wizard, and an edit screen made of a dozen ajax
 * subforms — so hooking a form would either miss most of it or attach to
 * subforms that are replaced by ajax the moment anything is clicked. The page
 * is the stable thing here.
 *
 * WHY A ROUTE LIST AND NOT A PATH PREFIX. `/admin/structure/views` matched by
 * string would also catch any module that puts a page underneath it, and would
 * miss a site that has moved the Views UI. Route names are the identity Drupal
 * itself uses.
 *
 * The delete, disable and break-lock confirmation forms are deliberately not
 * in the list: a confirmation is not a place to start a conversation, and the
 * assistant has nothing to offer there.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
final class VvjAiPageHook {

  /**
   * The Views pages where a conversation about building a view makes sense.
   *
   * @var list<string>
   */
  private const ROUTES = [
    // The list of views.
    'entity.view.collection',
    // The add wizard.
    'views_ui.add',
    // Editing a view, and editing one of its displays.
    'entity.view.edit_form',
    'entity.view.edit_display_form',
  ];

  /**
   * Constructs the hook.
   *
   * @param \Drupal\vvj_core_ai\Service\ChatSurfaceInterface $surface
   *   Decides whether the panel appears, and builds it.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   Names the page being rendered.
   */
  public function __construct(
    private readonly ChatSurfaceInterface $surface,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Appends the chat panel to the Views administration pages.
   *
   * @param array<string, mixed> $page_bottom
   *   The page bottom render array.
   */
  #[Hook('page_bottom')]
  public function pageBottom(array &$page_bottom): void {
    if (!in_array((string) $this->routeMatch->getRouteName(), self::ROUTES, TRUE)) {
      return;
    }

    $panel = $this->surface->build();

    if ($panel === []) {
      return;
    }

    $page_bottom['vvj_core_ai_chat'] = $panel;
  }

}
