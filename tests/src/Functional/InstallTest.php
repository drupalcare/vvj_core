<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies vvj_core installs cleanly and registers its services.
 */
#[Group('vvj_core')]
final class InstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['views', 'filter', 'vvj_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Verifies the module is enabled after test bootstrap.
   */
  public function testModuleInstalls(): void {
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('vvj_core'));
  }

  /**
   * Verifies shared services are registered in the container.
   */
  public function testServicesAreRegistered(): void {
    $container = \Drupal::getContainer();
    $this->assertTrue($container->has('vvj_core.svg_sanitizer'));
    $this->assertTrue($container->has('vvj_core.token_resolver'));
    $this->assertTrue($container->has('vvj_core.unique_id_generator'));
    $this->assertTrue($container->has('vvj_core.breakpoint_registry'));
    $this->assertTrue($container->has('vvj_core.twig_extension'));
  }

  /**
   * Verifies the module help page renders for administrators.
   */
  public function testHelpPageRenders(): void {
    $admin = $this->drupalCreateUser(['access administration pages']);
    $this->assertNotFalse($admin);
    $this->drupalLogin($admin);

    $this->drupalGet('admin/help/vvj_core');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('VVJ Core');
  }

}
