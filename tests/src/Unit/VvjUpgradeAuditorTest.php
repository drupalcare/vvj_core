<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewEntityInterface;
use Drupal\vvj_core\Service\VvjUpgradeAuditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for VvjUpgradeAuditor view discovery and drift detection.
 */
#[CoversClass(VvjUpgradeAuditor::class)]
#[Group('vvj_core')]
final class VvjUpgradeAuditorTest extends UnitTestCase {

  /**
   * A view display using the accordion (vvja) style plugin.
   *
   * @var array<string, mixed>
   */
  private const array VVJA_DISPLAY = [
    'default' => [
      'display_options' => ['style' => ['type' => 'views_vvja']],
    ],
  ];

  /**
   * A view display using a core (non-VVJ) style plugin.
   *
   * @var array<string, mixed>
   */
  private const array CORE_DISPLAY = [
    'default' => [
      'display_options' => ['style' => ['type' => 'default']],
    ],
  ];

  /**
   * Only views using a VVJ style plugin are reported.
   */
  public function testFindsOnlyVvjViews(): void {
    $auditor = $this->auditorWithViews([
      'accordion_view' => $this->mockView('accordion_view', self::VVJA_DISPLAY),
      'plain_view' => $this->mockView('plain_view', self::CORE_DISPLAY),
    ]);

    $this->assertSame(
      ['accordion_view' => ['views_vvja']],
      $auditor->findVvjViews(),
    );
  }

  /**
   * The module filter limits the scan to the requested pattern slugs.
   */
  public function testModuleFilterLimitsScan(): void {
    $auditor = $this->auditorWithViews([
      'accordion_view' => $this->mockView('accordion_view', self::VVJA_DISPLAY),
    ]);

    $this->assertSame([], $auditor->findVvjViews(['vvjs']));
    $this->assertSame(
      ['accordion_view' => ['views_vvja']],
      $auditor->findVvjViews(['vvja']),
    );
  }

  /**
   * V2.0 has no breaking renames, so no view reports drift.
   */
  public function testNoDriftForV2Release(): void {
    $auditor = $this->auditorWithViews([]);
    $this->assertSame(
      [],
      $auditor->detectDrift('accordion_view', ['views_vvja']),
    );
  }

  /**
   * Build an auditor whose view storage returns the given entities.
   *
   * @param array<string, \Drupal\views\ViewEntityInterface> $views
   *   Mock view entities keyed by ID.
   *
   * @return \Drupal\vvj_core\Service\VvjUpgradeAuditor
   *   The auditor under test.
   */
  private function auditorWithViews(array $views): VvjUpgradeAuditor {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($views);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('view')
      ->willReturn($storage);

    return new VvjUpgradeAuditor($entityTypeManager);
  }

  /**
   * Build a mock View config entity.
   *
   * @param string $id
   *   The view config ID.
   * @param array<string, mixed> $display
   *   The `display` config value.
   *
   * @return \Drupal\views\ViewEntityInterface
   *   The mock view.
   */
  private function mockView(string $id, array $display): ViewEntityInterface {
    $view = $this->createMock(ViewEntityInterface::class);
    $view->method('id')->willReturn($id);
    $view->method('get')->with('display')->willReturn($display);
    return $view;
  }

}
