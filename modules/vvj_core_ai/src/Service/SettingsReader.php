<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Form\FormState;
use Psr\Log\LoggerInterface;
use Drupal\vvj_core_ai\Model\FormatSetting;

/**
 * Reads a format's real settings out of its own settings form.
 *
 * WHY THE FORM AND NOT defineOptions(). Measured on sts.local 2026-09-04:
 * `defineOptions()` is protected, and `->options` is empty until a view
 * initialises the plugin. `buildOptionsForm()` is public — and is the better
 * source anyway, because it carries the human label, the help text and the
 * ALLOWED VALUES for each setting. `defineOptions()` would have given machine
 * names and defaults, which is the half a person already has.
 *
 * WHY THE WALK IS RECURSIVE. The same probe showed the real settings are not
 * at the top level: VVJS returns 14 top-level keys, of which nine are `details`
 * groups — "Timing Settings", "Navigation Settings", "Deep Linking Settings"
 * and so on — with the actual fields inside them. A flat read finds two
 * settings out of twenty-four and reports the rest as absent, which is the
 * worst kind of wrong: confident and short.
 *
 * NOTHING IS SAVED. The view built here exists only in memory to give the
 * plugin a display to attach to; it is never written, and this class holds no
 * storage it could write with.
 */
final class SettingsReader implements SettingsReaderInterface {

  /**
   * Element types that group other elements rather than hold a value.
   */
  private const CONTAINERS = ['details', 'fieldset', 'container', 'item', 'actions', 'vertical_tabs'];

  /**
   * Element types that display something rather than accept a value.
   */
  private const NOT_SETTINGS = ['markup', 'value', 'hidden', 'help', 'link'];

  /**
   * Constructs the reader.
   *
   * @param \Drupal\vvj_core_ai\Service\FormatProbeInterface $probe
   *   Builds the format's plugin against a throwaway view.
   * @param \Drupal\vvj_core_ai\Service\FormatRegistryInterface $registry
   *   Confirms the format is installed before its form is built.
   * @param \Psr\Log\LoggerInterface $logger
   *   Records why a form could not be built. An unreadable form is reported to
   *   the caller as a bare empty array, which says nothing about the cause —
   *   the cause goes here, because a swallowed error is worse than a loud one.
   */
  public function __construct(
    private readonly FormatProbeInterface $probe,
    private readonly FormatRegistryInterface $registry,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function read(string $format): ?array {
    $known = $this->registry->get($format);

    if ($known === NULL) {
      return NULL;
    }

    try {
      $form = $this->buildForm($known->pluginId);
    }
    catch (\Throwable $error) {
      // A format whose form cannot be built is a finding, not a fatal: the
      // caller reports that the settings could not be read rather than the
      // whole tool dying. Distinguished from "no such format" by [] vs NULL.
      //
      // THE KNOWN CAUSE, measured 2026-09-04: some formats build a link to
      // the view's edit form inside their settings form, which needs the
      // `views_ui` route `entity.view.edit_form`. On a site with Views UI
      // disabled — normal in production — the whole form build throws and no
      // settings can be read. That is a genuine limit of reading settings
      // through the form, not a defect in the format, and the caller says so.
      $this->logger->warning(
        'Could not read the settings form for @format: @message',
        ['@format' => $known->pluginId, '@message' => $error->getMessage()],
      );

      return [];
    }

    $out = [];
    $this->collect($form, '', $out);
    ksort($out);

    return array_values($out);
  }

  /**
   * Builds a format's settings form against a throwaway view.
   *
   * @param string $pluginId
   *   Views style plugin id.
   *
   * @return array<string|int, mixed>
   *   The form array. Not array<string, mixed>: a Views settings form mixes
   *   machine-name keys with numeric ones, so the narrower type was wishful.
   */
  private function buildForm(string $pluginId): array {
    // A style plugin that is not a StylePluginBase has no settings form to
    // read; the probe refuses it, and read() turns that into a sentence.
    $style = $this->probe->style($pluginId);

    $form = [];
    $state = new FormState();
    // Untyped and by-reference in Views, so $form is `mixed` afterwards. What
    // the caller needs is that it is an array; a plugin that leaves it as
    // anything else has no readable settings, and '' of them is the honest
    // answer rather than a fatal one level up.
    $style->buildOptionsForm($form, $state);

    return is_array($form) ? $form : [];
  }

  /**
   * Walks a form array, collecting the elements that hold a value.
   *
   * @param array<string|int, mixed> $elements
   *   The form or a branch of it.
   * @param string $section
   *   Title of the nearest enclosing group, or '' at the top level.
   * @param array<string, \Drupal\vvj_core_ai\Model\FormatSetting> $out
   *   Collected settings, keyed by machine name.
   */
  private function collect(array $elements, string $section, array &$out): void {
    foreach ($elements as $key => $element) {
      // Render-array properties (#type, #title, …) are not children.
      if (!is_array($element) || (is_string($key) && str_starts_with($key, '#'))) {
        continue;
      }

      $type = $this->text($element['#type'] ?? '');
      $title = $this->text($element['#title'] ?? '');

      if (in_array($type, self::CONTAINERS, TRUE) || $type === '') {
        // Recurse, carrying this group's title down as the section name.
        $this->collect($element, $title !== '' ? $title : $section, $out);
        continue;
      }

      if (in_array($type, self::NOT_SETTINGS, TRUE) || !is_string($key)) {
        continue;
      }

      $out[$key] = new FormatSetting(
        $key,
        $title,
        $section,
        $type,
        $this->text($element['#description'] ?? ''),
        $this->scalar($element['#default_value'] ?? NULL),
        $this->choices($element['#options'] ?? NULL),
      );

      // A field can still nest children; keep walking.
      $this->collect($element, $section, $out);
    }
  }

  /**
   * A render value as plain text.
   *
   * @param mixed $value
   *   Anything a form title or description can be, TranslatableMarkup
   *   included.
   *
   * @return string
   *   Plain text, markup stripped and whitespace collapsed.
   */
  private function text(mixed $value): string {
    if (is_string($value)) {
      $raw = $value;
    }
    elseif ($value instanceof \Stringable) {
      // A form title or description is routinely TranslatableMarkup.
      $raw = (string) $value;
    }
    elseif (is_scalar($value)) {
      $raw = (string) $value;
    }
    else {
      return '';
    }

    $text = strip_tags($raw);

    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * A default value as a string.
   *
   * @param mixed $value
   *   The declared default.
   *
   * @return string
   *   A readable rendering, or '' when there is nothing useful to show.
   */
  private function scalar(mixed $value): string {
    if (is_bool($value)) {
      return $value ? 'on' : 'off';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }

    return '';
  }

  /**
   * The allowed values of a select or radios.
   *
   * @param mixed $options
   *   The element's #options.
   *
   * @return list<string>
   *   The allowed keys — what actually gets stored, not the visible labels.
   */
  private function choices(mixed $options): array {
    if (!is_array($options)) {
      return [];
    }

    $out = [];

    foreach (array_keys($options) as $key) {
      $out[] = (string) $key;
    }

    return $out;
  }

}
