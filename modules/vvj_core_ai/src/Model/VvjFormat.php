<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * One VVJ display format, as the site actually has it.
 *
 * WHY THIS DESCRIBES THE SITE AND NOT THE FAMILY. "VVJS is the slideshow" is a
 * fact about the project; "VVJS is installed here and holds 24 settings" is a
 * fact about this site, and only the second one is safe to answer with. A model
 * that has read the VVJ documentation will otherwise describe formats that are
 * not present, confidently and in detail.
 */
final readonly class VvjFormat {

  /**
   * Constructs a format.
   *
   * @param string $pluginId
   *   Views style plugin id, e.g. `views_vvjs`. This is the value a site
   *   builder picks in the Views UI, so it is what gets quoted back to them.
   * @param string $module
   *   The module providing it, e.g. `vvjs`.
   * @param string $label
   *   Human title from the plugin definition.
   * @param string $summary
   *   The plugin's own short description.
   * @param int $settingCount
   *   How many settings it exposes. Counted, not estimated — the spread across
   *   the family is 7 to 24, and that spread is most of the reason a person
   *   needs help choosing.
   */
  public function __construct(
    public string $pluginId,
    public string $module,
    public string $label,
    public string $summary,
    public int $settingCount,
  ) {}

  /**
   * One line naming this format to a person.
   *
   * @return string
   *   A sentence with the id they will have to type.
   */
  public function line(): string {
    return sprintf(
      '%s (%s) — %s. %d settings.',
      $this->label,
      $this->pluginId,
      rtrim($this->summary, '.'),
      $this->settingCount,
    );
  }

}
