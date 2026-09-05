<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\vvj_core_ai\Model\ViewFinding;

/**
 * Explains why a view using a VVJ format is not doing what was expected.
 *
 * THE QUESTION THIS ANSWERS is the one in the brief: "why does my Featured Work
 * view render an empty box?" An empty box has several causes that look
 * identical from the front end, and the point of this service is to tell them
 * apart:
 *
 *   - the display has no fields, so every row renders as nothing;
 *   - the query matches no content, so there are no rows at all;
 *   - the display is disabled;
 *   - the format's CSS was switched off, so it renders unstyled rather than
 *     not at all.
 *
 * A person cannot distinguish those by looking. Each has a different fix, and
 * guessing between them is how an afternoon disappears.
 *
 * IT EXECUTES THE VIEW, WHICH IS A READ. Running a view issues a SELECT and
 * builds a result set; it writes nothing. That is the only way to answer "are
 * there any rows" honestly — inspecting filters and inferring would be a guess
 * dressed as a diagnosis.
 *
 * NOTHING HERE SAVES. No storage handler is held that could write, and the
 * view is loaded, executed and discarded.
 */
final class ViewDoctor implements ViewDoctorInterface {

  /**
   * Constructs the doctor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads views. Loading only — nothing is saved.
   * @param \Drupal\vvj_core_ai\Service\FormatRegistryInterface $registry
   *   Recognises which style plugins are VVJ formats.
   * @param \Drupal\vvj_core_ai\Service\SettingsReaderInterface $reader
   *   Supplies the settings a format actually declares, so stored options that
   *   no longer exist can be spotted.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormatRegistryInterface $registry,
    private readonly SettingsReaderInterface $reader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function usingVvj(): array {
    $out = [];

    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view instanceof ViewEntityInterface) {
        continue;
      }

      foreach ($this->displays($view) as $displayId => $format) {
        $out[] = sprintf('%s:%s (%s)', $view->id(), $displayId, $format);
      }
    }

    sort($out);

    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function diagnose(string $viewId, ?string $displayId = NULL): ?array {
    $view = $this->entityTypeManager->getStorage('view')->load(trim($viewId));

    if (!$view instanceof ViewEntityInterface) {
      return NULL;
    }

    $displays = $this->displays($view);

    if ($displays === []) {
      return [ViewFinding::warning(
        sprintf('The view "%s" does not use a VVJ format on any display.', $view->id()),
        'Nothing here to diagnose. Check the view is the one you meant.',
        ),
      ];
    }

    $displayId = $displayId !== NULL && trim($displayId) !== '' ? trim($displayId) : array_key_first($displays);

    if (!isset($displays[$displayId])) {
      return [ViewFinding::warning(
        sprintf('The display "%s" on "%s" does not use a VVJ format.', $displayId, $view->id()),
        sprintf('VVJ formats are on: %s.', implode(', ', array_keys($displays))),
        ),
      ];
    }

    return $this->check($view, $displayId, $displays[$displayId]);
  }

  /**
   * Runs every check against one display.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view.
   * @param string $displayId
   *   The display to check.
   * @param string $format
   *   The VVJ style plugin id it uses.
   *
   * @return list<\Drupal\vvj_core_ai\Model\ViewFinding>
   *   Findings, worst first — a person reads the top of the list.
   */
  private function check(ViewEntityInterface $view, string $displayId, string $format): array {
    // `View::get()` returns mixed, so every step down into the display array
    // is narrowed rather than assumed. A view whose stored display is not the
    // shape Views normally writes — hand-edited, half-migrated, written by
    // another module — is exactly the sort this tool is asked to diagnose, so
    // it must not fatal on one.
    $displays = $this->arr($view->get('display'));
    $display = $this->arr($displays[$displayId] ?? NULL);
    $options = $this->arr($display['display_options'] ?? NULL);
    $default = $this->arr($this->arr($displays['default'] ?? NULL)['display_options'] ?? NULL);

    $findings = [ViewFinding::ok(sprintf('Display "%s" uses %s.', $displayId, $format))];

    if ($view->status() === FALSE) {
      $findings[] = ViewFinding::broken(
        sprintf('The view "%s" is disabled, so nothing renders anywhere.', $view->id()),
        'Enable it at Structure › Views.',
      );
    }

    // Fields. A VVJ format renders each row from the display's fields; with
    // none, every row is empty markup and the result looks like a blank box.
    $fields = $this->arr($options['fields'] ?? $default['fields'] ?? NULL);
    $row = $this->arr($options['row'] ?? $default['row'] ?? NULL)['type'] ?? '';
    $row = is_string($row) ? $row : '';

    if ($row === 'fields' && $fields === []) {
      $findings[] = ViewFinding::broken(
        'The display shows fields but has none configured, so every row renders empty — this is the usual cause of a blank box.',
        'Edit the view and add at least one field under FIELDS.',
      );
    }
    elseif ($fields !== []) {
      $findings[] = ViewFinding::ok(sprintf('%d field(s) configured.', count($fields)));
    }

    // Rows. Executing is the only honest way to answer "is there anything to
    // show"; reading the filters and inferring would be a guess.
    $findings[] = $this->countRows($view, $displayId);

    // Settings that are stored but no longer declared. These are leftovers
    // from an earlier version of the format and do nothing at all, which is
    // exactly the kind of silent no-op a person cannot see.
    $findings[] = $this->checkStoredOptions(
      $this->arr($this->arr($options['style'] ?? NULL)['options'] ?? NULL),
      $format,
    );

    return $findings;
  }

  /**
   * A value as an array, or an empty array when it is anything else.
   *
   * `ConfigEntityBase::get()` is typed `mixed`, and a view's display structure
   * is several levels of nested array underneath it. Reading down through that
   * without checking each step is an assumption about a stored structure this
   * tool exists precisely to find fault with — a hand-edited or half-migrated
   * view is the likeliest thing anyone points it at.
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

  /**
   * Whether the view returns any rows.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view.
   * @param string $displayId
   *   The display.
   *
   * @return \Drupal\vvj_core_ai\Model\ViewFinding
   *   What the execution showed.
   */
  private function countRows(ViewEntityInterface $view, string $displayId): ViewFinding {
    try {
      $executable = $view->getExecutable();
      $executable->setDisplay($displayId);
      $executable->preExecute();
      $executable->execute();
      $rows = count($executable->result);
    }
    catch (\Throwable $error) {
      return ViewFinding::warning(
        'The view could not be run to see whether it returns anything: ' . $error->getMessage(),
        'Open it in the Views UI and use Update preview.',
      );
    }

    if ($rows === 0) {
      return ViewFinding::broken(
        'The view runs but matches no content, so there is nothing for the format to display. The format is not at fault here.',
        'Check the filters, and check content of the right type exists and is published.',
      );
    }

    return ViewFinding::ok(sprintf('The view returns %d row(s).', $rows));
  }

  /**
   * Stored style options the format no longer declares.
   *
   * @param mixed $stored
   *   The style options saved on the display.
   * @param string $format
   *   The VVJ style plugin id.
   *
   * @return \Drupal\vvj_core_ai\Model\ViewFinding
   *   What the comparison showed.
   */
  private function checkStoredOptions(mixed $stored, string $format): ViewFinding {
    $declared = $this->reader->read($format);

    if (!is_array($stored) || $declared === NULL || $declared === []) {
      return ViewFinding::ok('Stored format settings were not compared — the format\'s settings could not be read.');
    }

    $names = array_map(static fn($setting) => $setting->name, $declared);
    $unknown = array_diff(array_keys($stored), $names);

    if ($unknown !== []) {
      return ViewFinding::warning(
        sprintf(
          'The display stores %d setting(s) this format no longer has: %s. They are saved but do nothing.',
          count($unknown),
          implode(', ', $unknown),
        ),
        'Re-save the format settings in the Views UI to clear them.',
      );
    }

    return ViewFinding::ok(sprintf('All %d stored format setting(s) are ones the format declares.', count($stored)));
  }

  /**
   * The displays of a view that use a VVJ format.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view.
   *
   * @return array<string, string>
   *   Display id => VVJ style plugin id.
   */
  private function displays(ViewEntityInterface $view): array {
    $out = [];
    $displays = $this->arr($view->get('display'));
    $fallback = $this->arr(
      $this->arr($this->arr($displays['default'] ?? NULL)['display_options'] ?? NULL)['style'] ?? NULL,
    )['type'] ?? NULL;

    foreach ($displays as $id => $display) {
      $style = $this->arr(
        $this->arr($this->arr($display)['display_options'] ?? NULL)['style'] ?? NULL,
      )['type'] ?? $fallback;

      if (!is_string($style) || $style === '') {
        continue;
      }

      // Views stores the full plugin id (`views_vvja`) — verified 2026-09-04,
      // after a test fixture written the other way failed with
      // PluginNotFoundException. The module-name form is still accepted below
      // because a hand-edited or migrated view can carry it, and refusing to
      // recognise such a display would silently drop it from the diagnosis.
      if ($this->registry->get($style) !== NULL) {
        $out[(string) $id] = $style;
      }
      elseif ($this->registry->get('views_' . $style) !== NULL) {
        $out[(string) $id] = 'views_' . $style;
      }
    }

    return $out;
  }

}
