<?php

declare(strict_types=1);

namespace Drupal\Tests\vvj_core_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\vvj_core_ai\Model\ViewFinding;
use Drupal\vvj_core_ai\Service\ViewDoctorInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Telling apart the several causes of "my view renders an empty box".
 *
 * WHY EACH CAUSE GETS ITS OWN FIXTURE. From the front end, a view with no
 * fields and a view with no results look identical — both are a blank space.
 * They have different fixes, and a diagnosis that cannot separate them has not
 * helped. Each test below builds exactly one of those states and asserts the
 * finding names that one and not the other.
 *
 * @group vvj_core
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('vvj_core')]
#[RunTestsInSeparateProcesses]
final class ViewDoctorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'views',
    'views_ui',
    'vvj_core',
    'vvjtest',
    'vvj_core_ai',
  ];

  /**
   * The service under test.
   */
  private ViewDoctorInterface $doctor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->doctor = \Drupal::service(ViewDoctorInterface::class);
  }

  /**
   * A view that does not exist is NULL, not an empty finding list.
   */
  public function testAnUnknownViewIsNull(): void {
    self::assertNull($this->doctor->diagnose('no_such_view'));
  }

  /**
   * A view with no fields is told that, specifically.
   *
   * The most common blank box, and the one a person is least likely to spot:
   * the format is configured, the query works, and every row renders nothing.
   */
  public function testViewWithNoFieldsIsNamedAsTheCause(): void {
    Node::create(['type' => 'page', 'title' => 'Something', 'status' => 1])->save();
    $this->makeView('no_fields', fields: FALSE);

    $findings = $this->doctor->diagnose('no_fields');

    self::assertIsArray($findings);
    self::assertTrue(
      $this->has($findings, ViewFinding::BROKEN, 'has none configured'),
      'The missing fields must be reported: ' . $this->dump($findings),
    );
  }

  /**
   * A view that matches nothing is told THAT, and told the format is innocent.
   *
   * Same blank box, different cause, different fix. If this reported missing
   * fields the person would go and add fields to a view that already has them.
   */
  public function testViewWithNoResultsIsNamedAsTheCause(): void {
    // Fields present, but no content at all for the query to find.
    $this->makeView('no_rows', fields: TRUE);

    $findings = $this->doctor->diagnose('no_rows');

    self::assertIsArray($findings);
    self::assertTrue(
      $this->has($findings, ViewFinding::BROKEN, 'matches no content'),
      'The empty result must be reported: ' . $this->dump($findings),
    );
    self::assertTrue(
      $this->has($findings, ViewFinding::BROKEN, 'format is not at fault'),
      'It must say the format is not the problem.',
    );
  }

  /**
   * A sound view returns OK findings, never an empty list.
   *
   * "Nothing to report" and "I did not look" must not read the same.
   */
  public function testSoundViewStillReportsFindings(): void {
    Node::create(['type' => 'page', 'title' => 'Live', 'status' => 1])->save();
    $this->makeView('healthy', fields: TRUE);

    $findings = $this->doctor->diagnose('healthy');

    self::assertIsArray($findings);
    self::assertNotEmpty($findings);
    self::assertFalse(
      $this->has($findings, ViewFinding::BROKEN, ''),
      'A sound view has nothing broken: ' . $this->dump($findings),
    );
  }

  /**
   * Every finding carries a fix, except the ones with nothing to fix.
   *
   * A diagnosis without a next step has moved the work rather than done it.
   */
  public function testEveryProblemCarriesItsFix(): void {
    $this->makeView('no_rows_2', fields: TRUE);

    foreach ($this->doctor->diagnose('no_rows_2') ?? [] as $finding) {
      if ($finding->severity !== ViewFinding::OK) {
        self::assertNotSame('', $finding->fix, $finding->summary . ' has no fix.');
      }
    }
  }

  /**
   * Views using a VVJ format are listed; ones that do not are left out.
   */
  public function testOnlyVvjViewsAreListed(): void {
    $this->makeView('vvj_one', fields: TRUE);
    View::create([
      'id' => 'plain_one',
      'label' => 'Plain',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => ['style' => ['type' => 'default']],
        ],
      ],
    ])->save();

    $listed = implode(' ', $this->doctor->usingVvj());

    self::assertStringContainsString('vvj_one', $listed);
    self::assertStringNotContainsString('plain_one', $listed);
  }

  /**
   * Diagnosing writes nothing back to the view.
   *
   * The doctor executes the view, which is a read. If executing ever saved,
   * this module would be modifying a site builder's configuration — the one
   * thing the brief forbids outright.
   */
  public function testDiagnosingDoesNotModifyTheView(): void {
    Node::create(['type' => 'page', 'title' => 'X', 'status' => 1])->save();
    $this->makeView('untouched', fields: TRUE);

    $storage = \Drupal::service('entity_type.manager')->getStorage('view');
    $before = $storage->load('untouched')?->toArray();
    self::assertIsArray($before, 'The fixture view must exist before the check.');

    $this->doctor->diagnose('untouched');

    $storage->resetCache();
    self::assertSame($before, $storage->load('untouched')?->toArray());
  }

  /**
   * Builds a view whose default display uses the test VVJ format.
   *
   * @param string $id
   *   View machine name.
   * @param bool $fields
   *   Whether to configure a field.
   */
  private function makeView(string $id, bool $fields): void {
    $options = [
      // Views stores the FULL plugin id, `views_vvjtest` — not the module name.
      'style' => ['type' => 'views_vvjtest', 'options' => []],
      'row' => ['type' => 'fields'],
    ];

    if ($fields) {
      $options['fields'] = [
        'title' => [
          'id' => 'title',
          'table' => 'node_field_data',
          'field' => 'title',
          'plugin_id' => 'field',
        ],
      ];
    }

    View::create([
      'id' => $id,
      'label' => ucfirst($id),
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => $options,
        ],
      ],
    ])->save();
  }

  /**
   * Whether any finding of a severity contains a phrase.
   *
   * @param list<\Drupal\vvj_core_ai\Model\ViewFinding> $findings
   *   The findings.
   * @param string $severity
   *   Severity to match.
   * @param string $needle
   *   Phrase to look for, or '' to match any finding of that severity.
   *
   * @return bool
   *   TRUE when one matches.
   */
  private function has(array $findings, string $severity, string $needle): bool {
    foreach ($findings as $finding) {
      if ($finding->severity !== $severity) {
        continue;
      }
      if ($needle === '' || str_contains($finding->summary, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Findings as one string, for assertion messages.
   *
   * @param list<\Drupal\vvj_core_ai\Model\ViewFinding> $findings
   *   The findings.
   *
   * @return string
   *   Every line, so a failure shows what was actually reported.
   */
  private function dump(array $findings): string {
    return "\n" . implode("\n", array_map(static fn($f) => $f->line(), $findings));
  }

}
