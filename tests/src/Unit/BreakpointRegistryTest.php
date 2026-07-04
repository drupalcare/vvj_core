<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vvj_core\Constants\Breakpoints;
use Drupal\vvj_core\Service\BreakpointRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for BreakpointRegistry option validation.
 */
#[CoversClass(BreakpointRegistry::class)]
#[Group('vvj_core')]
final class BreakpointRegistryTest extends UnitTestCase {

  /**
   * The registry under test.
   */
  private BreakpointRegistry $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->registry = new BreakpointRegistry();
    $this->registry->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * GetOptions() returns a label for every known breakpoint value.
   */
  public function testGetOptionsReturnsAllKnownBreakpoints(): void {
    $options = $this->registry->getOptions();
    foreach (Breakpoints::ALL_VALUES as $value) {
      $this->assertArrayHasKey($value, $options);
    }
  }

  /**
   * IsValid() accepts every canonical breakpoint value.
   */
  public function testIsValidAcceptsKnownValues(): void {
    foreach (Breakpoints::ALL_VALUES as $value) {
      $this->assertTrue($this->registry->isValid($value));
    }
  }

  /**
   * IsValid() rejects unknown breakpoint strings.
   */
  public function testIsValidRejectsUnknownValues(): void {
    $this->assertFalse($this->registry->isValid('800'));
    $this->assertFalse($this->registry->isValid(''));
    $this->assertFalse($this->registry->isValid('foo'));
  }

  /**
   * GetDefault() returns the canonical default breakpoint.
   */
  public function testGetDefaultReturnsCanonicalDefault(): void {
    $this->assertSame(Breakpoints::DEFAULT_VALUE, $this->registry->getDefault());
  }

}
