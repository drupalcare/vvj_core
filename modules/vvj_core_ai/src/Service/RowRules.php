<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

/**
 * Default row rules: the format's own requiresFieldsRow(), asked in memory.
 */
final class RowRules implements RowRulesInterface {

  /**
   * Constructs the rules.
   *
   * @param \Drupal\vvj_core_ai\Service\FormatProbeInterface $probe
   *   Builds the format's plugin with the options the view will have.
   */
  public function __construct(
    private readonly FormatProbeInterface $probe,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function allowsViewMode(string $format, array $options = []): bool {
    try {
      $style = $this->probe->style($format, $options);
    }
    catch (\Throwable) {
      // Not a style plugin at all: fields is the only row that is safe.
      return FALSE;
    }

    // PROTECTED IN THE BASE, AND ASKED ANYWAY. It is the one place the answer
    // lives, and reading it by reflection is the price of not keeping a second
    // copy of that answer here. A style without the method never forces
    // fields, which is what core Views does.
    if (!method_exists($style, 'requiresFieldsRow')) {
      return TRUE;
    }

    return !(new \ReflectionMethod($style, 'requiresFieldsRow'))->invoke($style);
  }

}
