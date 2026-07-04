<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vvj_core\Service\SvgSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for SvgSanitizer allowlist and rejection rules.
 */
#[CoversClass(SvgSanitizer::class)]
#[Group('vvj_core')]
final class SvgSanitizerTest extends UnitTestCase {

  /**
   * The sanitizer under test.
   */
  private SvgSanitizer $sanitizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sanitizer = new SvgSanitizer();
  }

  /**
   * Sanitize preserves well-formed SVG markup.
   */
  #[DataProvider('providerCleanInputs')]
  public function testSanitizeKeepsCleanSvg(string $input): void {
    $result = $this->sanitizer->sanitize($input);
    $this->assertNotSame('', $result);
    $this->assertStringStartsWith('<svg', $result);
  }

  /**
   * Data provider for clean SVG inputs.
   *
   * @return list<array{string}>
   *   Test cases: one SVG string per row.
   */
  public static function providerCleanInputs(): array {
    return [
      ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/></svg>'],
      ['<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>'],
      ['<svg xmlns="http://www.w3.org/2000/svg"><g><rect x="0" y="0" width="10" height="10"/></g></svg>'],
    ];
  }

  /**
   * Sanitize returns an empty string for dangerous or invalid markup.
   */
  #[DataProvider('providerDangerousInputs')]
  public function testSanitizeRejectsDangerousMarkup(string $input): void {
    $this->assertSame('', $this->sanitizer->sanitize($input));
  }

  /**
   * Data provider for dangerous or invalid SVG inputs.
   *
   * @return list<array{string}>
   *   Test cases: one markup string per row.
   */
  public static function providerDangerousInputs(): array {
    return [
      ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
      ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>'],
      ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body><script>x</script></body></foreignObject></svg>'],
      ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>'],
      ['<div><svg xmlns="http://www.w3.org/2000/svg"></svg></div>'],
      [''],
      ['Hello world'],
    ];
  }

  /**
   * Validate returns no errors for clean SVG input.
   */
  public function testValidateReturnsEmptyForCleanSvg(): void {
    $result = $this->sanitizer->validate(
      '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
    );
    $this->assertSame([], $result);
  }

  /**
   * Validate reports errors for dangerous SVG attributes.
   */
  public function testValidateFlagsDangerousMarkup(): void {
    $errors = $this->sanitizer->validate(
      '<svg xmlns="http://www.w3.org/2000/svg" onclick="x()"></svg>',
    );
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('dangerous', strtolower($errors[0]));
  }

  /**
   * Sanitize strips non-whitelisted attributes while keeping safe elements.
   */
  public function testSanitizeStripsDisallowedAttributes(): void {
    // A non-whitelisted but benign attribute (data-foo) is stripped while the
    // path element is preserved. Dangerous markup (on* handlers, scripts) is
    // rejected outright instead — see testSanitizeRejectsDangerousMarkup().
    $input = '<svg xmlns="http://www.w3.org/2000/svg" data-foo="bar"><path d="M0 0"/></svg>';
    $output = $this->sanitizer->sanitize($input);
    $this->assertStringNotContainsString('data-foo', $output);
    $this->assertStringContainsString('<path', $output);
  }

  /**
   * Sanitize preserves fragment-reference href values on use elements.
   */
  public function testSanitizePreservesFragmentRefHref(): void {
    $input = '<svg xmlns="http://www.w3.org/2000/svg"><use href="#icon"/></svg>';
    $output = $this->sanitizer->sanitize($input);
    $this->assertStringContainsString('href="#icon"', $output);
  }

}
