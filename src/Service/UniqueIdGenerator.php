<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

use Drupal\vvj_core\Constants\ValidationBounds;

/**
 * Generates stable, collision-resistant numeric IDs for VVJ instances.
 *
 * Every VVJ pattern needs a unique ID per Views display so that DOM
 * IDs (`vvja-12345678`, `vvjs-87654321`, etc.) don't collide when
 * multiple instances render on the same page. The 8-digit range
 * (10,000,000 - 99,999,999) gives 90 million possibilities — enough
 * to make collisions vanishingly unlikely while keeping the ID short
 * enough to be readable in DevTools.
 *
 * Wraps `random_int()` so it can be mocked in tests.
 */
final class UniqueIdGenerator {

  /**
   * Generate a fresh 8-digit ID.
   *
   * @return int
   *   An integer in the range
   *   [ValidationBounds::MIN_UNIQUE_ID, ValidationBounds::MAX_UNIQUE_ID].
   *
   * @throws \Random\RandomException
   *   If a sufficient source of randomness is unavailable (extremely rare).
   */
  public function generate(): int {
    return random_int(
      ValidationBounds::MIN_UNIQUE_ID,
      ValidationBounds::MAX_UNIQUE_ID,
    );
  }

}
