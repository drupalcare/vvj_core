<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies vvj_core installs cleanly and registers its services.
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class InstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * `help` is required by testHelpPageRenders: /admin/help/{name} is a route
   * owned by core's help module, so without it that path is a 404 whatever
   * this module does. The test asked for the page without enabling the thing
   * that serves it, and had never been run to find out — Functional tests
   * need SIMPLETEST_BASE_URL, which nothing set until 2026-09-04.
   *
   * @var list<string>
   */
  protected static $modules = ['views', 'filter', 'help', 'vvj_core'];

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
    // No assertNotFalse() here: drupalCreateUser() returns UserInterface, so
    // the assertion could never fail and asserted nothing. drupalLogin() is
    // the real check — it fails loudly if the account is unusable.
    // `access help pages` is the permission the route actually requires;
    // `access administration pages` alone yields 403. Checked against
    // core/modules/help/help.routing.yml rather than assumed.
    $admin = $this->drupalCreateUser([
      'access administration pages',
      'access help pages',
    ]);
    $this->drupalLogin($admin);

    $this->drupalGet('admin/help/vvj_core');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('VVJ Core');
  }

}
