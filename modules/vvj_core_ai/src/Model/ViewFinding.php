<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Model;

/**
 * One thing observed about a view, and what to do about it.
 *
 * WHY EVERY FINDING CARRIES A FIX. "This view has no fields" is a diagnosis a
 * person still has to act on; "add at least one field under Format › Show" is
 * the answer. A diagnostic tool that names problems without naming the place
 * to solve them has moved the work rather than done it — and this module will
 * never do the solving itself.
 */
final readonly class ViewFinding {

  /**
   * Nothing wrong; reported so a clean check reads as a result.
   */
  public const string OK = 'ok';

  /**
   * Renders, but probably not as intended.
   */
  public const string WARNING = 'warning';

  /**
   * Will not render anything useful.
   */
  public const string BROKEN = 'broken';

  /**
   * Constructs a finding.
   *
   * @param string $severity
   *   One of OK, WARNING, BROKEN.
   * @param string $summary
   *   What was observed, as a complete sentence.
   * @param string $fix
   *   Where the person goes and what they change. Empty only for OK findings,
   *   where there is nothing to fix.
   */
  public function __construct(
    public string $severity,
    public string $summary,
    public string $fix = '',
  ) {}

  /**
   * A finding that something is wrong and the view will not work.
   *
   * @param string $summary
   *   What is wrong.
   * @param string $fix
   *   What to do.
   *
   * @return self
   *   The finding.
   */
  public static function broken(string $summary, string $fix): self {
    return new self(self::BROKEN, $summary, $fix);
  }

  /**
   * A finding that something looks unintended but still renders.
   *
   * @param string $summary
   *   What was observed.
   * @param string $fix
   *   What to do if it was not intended.
   *
   * @return self
   *   The finding.
   */
  public static function warning(string $summary, string $fix): self {
    return new self(self::WARNING, $summary, $fix);
  }

  /**
   * A finding that something checked out.
   *
   * @param string $summary
   *   What was checked and found sound.
   *
   * @return self
   *   The finding.
   */
  public static function ok(string $summary): self {
    return new self(self::OK, $summary);
  }

  /**
   * This finding as a line of text.
   *
   * @return string
   *   Severity, observation, and the fix where there is one.
   */
  public function line(): string {
    $marks = [self::OK => 'OK', self::WARNING => 'CHECK', self::BROKEN => 'BROKEN'];
    $line = sprintf('[%s] %s', $marks[$this->severity] ?? '?', $this->summary);

    return $this->fix === '' ? $line : $line . ' → ' . $this->fix;
  }

}
