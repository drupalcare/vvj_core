<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\vvj_core\Constants\ValidationBounds;
use Drupal\vvj_core\Service\UniqueIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for UniqueIdGenerator range and uniqueness.
 */
#[CoversClass(UniqueIdGenerator::class)]
#[Group('vvj_core')]
final class UniqueIdGeneratorTest extends UnitTestCase {

  /**
   * Generate() returns integers within the configured unique-ID bounds.
   */
  public function testGeneratesIdInExpectedRange(): void {
    $gen = new UniqueIdGenerator();
    for ($i = 0; $i < 50; $i++) {
      $id = $gen->generate();
      $this->assertGreaterThanOrEqual(ValidationBounds::MIN_UNIQUE_ID, $id);
      $this->assertLessThanOrEqual(ValidationBounds::MAX_UNIQUE_ID, $id);
    }
  }

  /**
   * Generate() produces distinct values across repeated calls.
   */
  public function testGeneratesUniqueValuesAcrossCalls(): void {
    $gen = new UniqueIdGenerator();
    $ids = [];
    for ($i = 0; $i < 100; $i++) {
      $ids[] = $gen->generate();
    }
    // Not strictly guaranteed but with a 90M-value range, 100 samples
    // colliding would imply randomness is broken.
    $this->assertCount(count(array_unique($ids)), $ids);
  }

}
