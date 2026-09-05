<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * One setting on a VVJ format, as its own settings form declares it.
 *
 * Everything here comes from the plugin at runtime. Nothing is transcribed:
 * there are 143 settings across the family, and a table of them inside this
 * module would be a second copy of a fact the plugins already own — wrong the
 * first time any format gains an option.
 */
final readonly class FormatSetting {

  /**
   * Constructs a setting.
   *
   * @param string $name
   *   Machine name — what a person types, and what the config key is called.
   * @param string $label
   *   The label shown on the settings form.
   * @param string $section
   *   The form group it sits under, e.g. "Timing Settings". Carried because
   *   the form's own grouping is how a site builder will look for it, and a
   *   flat list of 24 names is a worse answer than a grouped one.
   * @param string $type
   *   Form element type — textfield, checkbox, select, number.
   * @param string $description
   *   The form's own help text, stripped of markup.
   * @param string $default
   *   The default value, rendered as a string.
   * @param list<string> $choices
   *   Allowed values for a select or radios; empty for free-form fields. This
   *   is the part a person most often gets wrong, and the part a model will
   *   otherwise invent.
   */
  public function __construct(
    public string $name,
    public string $label,
    public string $section,
    public string $type,
    public string $description,
    public string $default,
    public array $choices = [],
  ) {}

  /**
   * One line describing this setting.
   *
   * @return string
   *   A sentence naming the machine name, the type and the allowed values.
   */
  public function line(): string {
    $parts = [$this->type];

    if ($this->choices !== []) {
      $parts[] = 'one of: ' . implode(', ', $this->choices);
    }

    if ($this->default !== '') {
      $parts[] = 'default ' . $this->default;
    }

    $line = sprintf('%s (%s) — %s', $this->name, $this->label, implode('; ', $parts));

    return $this->description === '' ? $line : $line . '. ' . $this->description;
  }

}
