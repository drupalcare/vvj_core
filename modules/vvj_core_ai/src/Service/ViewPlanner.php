<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\vvj_core_ai\Model\ViewPlan;

/**
 * Works out whether a view can be created, or what is still missing.
 *
 * THE FIVE PRECONDITIONS, and this list IS the feature:
 *
 *   1. An entity type and bundle that exist here.
 *   2. A VVJ format installed here.
 *   3. Fields that exist on that bundle.
 *   4. A machine name nothing else is using.
 *   5. Permission to administer views.
 *
 * Every unmet one comes back together, so the assistant asks a single question
 * rather than four. A tool that cannot proceed, and whose refusal names what is
 * missing, forces the conversation in a way a system prompt never reliably
 * does — and unlike a prompt, it can be tested.
 *
 * IT WRITES NOTHING. Planning is reading; `ViewCreator` does the writing, and
 * calls this again rather than trusting it was called.
 */
final class ViewPlanner implements ViewPlannerInterface {

  /**
   * Constructs the planner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Checks entity types and existing view machine names.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   Lists the bundles of an entity type.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   Lists the fields available on a bundle.
   * @param \Drupal\vvj_core_ai\Service\FormatRegistryInterface $formats
   *   Names the VVJ formats this site has.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Checked for permission, so the tool cannot exceed what the person
   *   operating it may do by hand.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly FormatRegistryInterface $formats,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function plan(
    ?string $entityType,
    ?string $bundle,
    ?string $format,
    array $fields,
    ?string $label,
    bool $needsFields = TRUE,
  ): ViewPlan {
    $questions = [];

    // 5 · Permission first. Telling someone which fields to pick and then
    // refusing on permission wastes their time.
    if (!$this->currentUser->hasPermission('administer views')) {
      return ViewPlan::blocked([
        'You do not have the "administer views" permission, so a view cannot be created for you.',
      ]);
    }

    // 1 · Entity type.
    $entityType = trim((string) $entityType);

    if ($entityType === '') {
      $entityType = 'node';
    }

    if (!$this->entityTypeManager->hasDefinition($entityType)) {
      return ViewPlan::blocked([
        sprintf('There is no "%s" content on this site. Views here usually list "node" content.', $entityType),
      ]);
    }

    // 1 · Bundle. Empty is allowed and means every bundle, which is a real
    // choice rather than a missing answer — but an unknown one is a typo.
    $bundle = trim((string) $bundle);
    $bundles = array_keys($this->bundleInfo->getBundleInfo($entityType));
    $bundleIsReal = $bundle === '' || in_array($bundle, $bundles, TRUE);

    if (!$bundleIsReal) {
      $questions[] = sprintf(
        'There is no "%s" content type.%s These exist: %s.',
        $bundle,
        $this->didYouMean($bundle, $bundles),
        $bundles === [] ? 'none' : implode(', ', $bundles),
      );
    }

    // 2 · Format.
    $format = trim((string) $format);
    $known = $format === '' ? NULL : $this->formats->get($format);

    if ($format === '') {
      $available = array_keys($this->formats->all());
      $questions[] = $available === []
        ? 'No VVJ display format is installed on this site, so there is none to build the view with.'
        : sprintf('Which VVJ format should it use? Installed here: %s.', implode(', ', $available));
    }
    elseif ($known === NULL) {
      $available = array_keys($this->formats->all());
      $questions[] = sprintf(
        'This site does not have the "%s" format. Installed here: %s.',
        $format,
        $available === [] ? 'none' : implode(', ', $available),
      );
    }

    // 3 · Fields.
    $fields = array_values(array_filter(array_map(
      static fn($f): string => is_string($f) ? trim($f) : '',
      $fields,
    )));

    if (!$bundleIsReal) {
      // Say nothing about fields. getFieldDefinitions() answers for a bundle
      // that does not exist by returning the entity's base fields, so any list
      // built here would describe a content type the previous question has
      // just said is not on this site — see the note above this block.
      $questions[] = 'Once the content type is settled, ask again and the fields available on it can be listed.';
    }
    elseif ($fields === [] && $needsFields) {
      $questions[] = sprintf(
        'Which fields should each item show? %s',
        $this->fieldHint($entityType, $bundle ?: ($bundles[0] ?? '')),
      );
    }
    else {
      $available = $this->fieldsOn($entityType, $bundle ?: ($bundles[0] ?? ''));
      $missing = array_values(array_diff($fields, $available));

      if ($missing !== [] && $available !== []) {
        $questions[] = sprintf(
          'These are not fields on that content: %s.%s Available: %s.',
          implode(', ', $missing),
          $this->didYouMean($missing[0], $available),
          implode(', ', array_slice($available, 0, 25)),
        );
      }
    }

    // 4 · Name.
    $label = trim((string) $label);

    if ($label === '') {
      $questions[] = 'What should the view be called? A short human name, such as "Featured case studies".';
    }

    // A NAME ALREADY TAKEN GETS THE NEXT FREE ONE — taken_name_2, _3 — and the
    // existing view is never touched. It used to be a refusal, which after a
    // paid AI call wasted the call over a name the person never chose.
    $machineName = $label === '' ? '' : $this->freeMachineName($this->machineName($label));

    if ($label !== '' && $machineName === '') {
      $questions[] = 'Every name like that is taken. Say a different name for the view.';
    }

    if ($questions !== []) {
      return ViewPlan::blocked($questions);
    }

    return ViewPlan::ready(
      $entityType,
      $bundle,
      (string) $known?->pluginId,
      $fields,
      $label,
      $machineName,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fieldsOn(string $entityType, string $bundle): array {
    if ($bundle === '' || !$this->entityTypeManager->hasDefinition($entityType)) {
      return [];
    }

    $out = [];

    foreach ($this->fieldManager->getFieldDefinitions($entityType, $bundle) as $name => $definition) {
      // Machinery — uuid, revision ids, the parent pointers — is not something
      // a person puts on a card. Offering it invites a view full of numbers.
      if ($definition->getFieldStorageDefinition()->isBaseField()
        && !in_array($name, ['title', 'name', 'created', 'changed', 'status'], TRUE)) {
        continue;
      }

      $out[] = (string) $name;
    }

    sort($out);

    return $out;
  }

  /**
   * A " Did you mean X?" clause when one candidate is an obvious near-match.
   *
   * MEASURED, and this is why it exists. In a live model conversation on
   * sts.local 2026-09-04 the person asked for "a listing of the FAQs". The
   * model sent `faq`, the site has `apex_faq`, and the refusal listed six
   * content types and left the model to pick — so it asked the person a
   * question whose answer was already on screen. Prefixed and suffixed names
   * are exactly what a site with a prefix convention produces, and they are
   * the easiest case to resolve and the most annoying to be asked about.
   *
   * Deliberately conservative: it suggests only when EXACTLY ONE candidate
   * matches. Offering three guesses is not help, and a wrong single guess that
   * the model then acts on is worse than no guess — which is why this only
   * ever appends a question, and never substitutes the value itself.
   *
   * @param string $wanted
   *   What the caller asked for.
   * @param list<string> $available
   *   What actually exists.
   *
   * @return string
   *   " Did you mean \"x\"?", or '' when there is no single obvious answer.
   */
  private function didYouMean(string $wanted, array $available): string {
    $wanted = strtolower(trim($wanted));

    if ($wanted === '' || in_array($wanted, $available, TRUE)) {
      return '';
    }

    $hits = [];

    foreach ($available as $candidate) {
      $lower = strtolower($candidate);

      // A prefix or suffix difference only — apex_faq for faq, or the reverse.
      if (str_ends_with($lower, '_' . $wanted) || str_starts_with($lower, $wanted . '_')) {
        $hits[] = $candidate;
      }
    }

    return count($hits) === 1 ? sprintf(' Did you mean "%s"?', $hits[0]) : '';
  }

  /**
   * A sentence naming some fields the person could pick.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   *
   * @return string
   *   A hint listing real field names, or a prompt for the content type first.
   */
  private function fieldHint(string $entityType, string $bundle): string {
    $available = $this->fieldsOn($entityType, $bundle);

    if ($available === []) {
      return 'Say which content type first, and the available fields can be listed.';
    }

    return sprintf('Available on %s: %s.', $bundle, implode(', ', array_slice($available, 0, 25)));
  }

  /**
   * The machine name, or the first free one with a number after it.
   *
   * @param string $base
   *   The machine name derived from the label.
   *
   * @return string
   *   A machine name no view uses, or '' when 99 are taken.
   */
  private function freeMachineName(string $base): string {
    $storage = $this->entityTypeManager->getStorage('view');

    if ($storage->load($base) === NULL) {
      return $base;
    }

    for ($n = 2; $n <= 99; $n++) {
      $suffix = '_' . $n;
      $candidate = substr($base, 0, 32 - strlen($suffix)) . $suffix;

      if ($storage->load($candidate) === NULL) {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * A machine name derived from a human one.
   *
   * @param string $label
   *   The human name.
   *
   * @return string
   *   A valid, lowercase, underscore-separated machine name.
   */
  private function machineName(string $label): string {
    $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $label));
    $name = trim((string) preg_replace('/_+/', '_', $name), '_');

    // Views machine names are limited to 32 characters.
    return substr($name === '' ? 'vvj_view' : $name, 0, 32);
  }

}
