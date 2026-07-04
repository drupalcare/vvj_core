<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Plugin\views\style;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\vvj_core\Constants\AnimationDirection;
use Drupal\vvj_core\Constants\ValidationBounds;
use Drupal\vvj_core\Service\BreakpointRegistry;
use Drupal\vvj_core\Service\SvgSanitizer;
use Drupal\vvj_core\Service\TokenResolver;
use Drupal\vvj_core\Service\UniqueIdGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract Views Style plugin base for every VVJ pattern.
 *
 * Provides the common skeleton — shared form sections, library list,
 * render array shape, validate(), unique-id generation — that every
 * VVJ pattern (`Accordion`, `BasicCarousel`, `Slideshow`, etc.) needs.
 *
 * Pattern modules implement four small abstract hooks:
 *   - getModuleSlug(): string         e.g. 'vvja'
 *   - getCustomElementTag(): string   e.g. 'vvja-accordion'
 *   - definePatternOptions(array): array  pattern-specific options
 *   - buildPatternSections(array&): void  pattern-specific form sections
 *
 * And optionally override:
 *   - supportsDeeplinking(): bool      default TRUE
 *   - supportsEnableCss(): bool        default TRUE
 *   - getAnimationPresets(): array     default returns shared 7-preset set
 *
 * **CRITICAL**: service properties are `protected` (NOT `readonly`)
 * because `DependencySerializationTrait` (inherited from core
 * PluginBase) writes them back on `__wakeup()` after Views form-state
 * caching. `readonly` would forbid that writeback and surface as the
 * generic "Oops" error on every Views UI form interaction.
 */
abstract class VvjStylePluginBase extends StylePluginBase {

  /**
   * SVG markup sanitizer for admin-pasted icons.
   */
  protected SvgSanitizer $svgSanitizer;

  /**
   * Resolves [vvjX:field] tokens in Views header/footer/empty contexts.
   */
  protected TokenResolver $tokenResolver;

  /**
   * Generates stable 8-digit unique IDs per view display.
   */
  protected UniqueIdGenerator $uniqueIdGenerator;

  /**
   * Canonical breakpoint values + translatable labels.
   */
  protected BreakpointRegistry $breakpointRegistry;

  /**
   * For slug-normalizing the deeplink identifier.
   */
  protected TransliterationInterface $transliteration;

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesRowClass = TRUE;

  /**
   * Cached unique ID per view display.
   *
   * Memoized so defineOptions() doesn't regenerate a fresh ID on every
   * form rebuild.
   */
  private ?int $cachedUniqueId = NULL;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration as stored in the display.
   * @param string $plugin_id
   *   The plugin_id for the instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    // @phpstan-ignore new.static
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $svgSanitizer = $container->get('vvj_core.svg_sanitizer');
    assert($svgSanitizer instanceof SvgSanitizer);
    $instance->svgSanitizer = $svgSanitizer;
    $tokenResolver = $container->get('vvj_core.token_resolver');
    assert($tokenResolver instanceof TokenResolver);
    $instance->tokenResolver = $tokenResolver;
    $uniqueIdGenerator = $container->get('vvj_core.unique_id_generator');
    assert($uniqueIdGenerator instanceof UniqueIdGenerator);
    $instance->uniqueIdGenerator = $uniqueIdGenerator;
    $breakpointRegistry = $container->get('vvj_core.breakpoint_registry');
    assert($breakpointRegistry instanceof BreakpointRegistry);
    $instance->breakpointRegistry = $breakpointRegistry;
    $transliteration = $container->get('transliteration');
    assert($transliteration instanceof TransliterationInterface);
    $instance->transliteration = $transliteration;
    return $instance;
  }

  /*
   * Subclass contract — pattern modules implement these.
   */

  /**
   * The pattern's module slug (`vvja`, `vvjb`, `vvjc`, etc.).
   *
   * Used everywhere the pattern's namespace is needed: library names,
   * token namespace, CSS class prefix, custom-element prefix.
   */
  abstract public function getModuleSlug(): string;

  /**
   * The custom-element tag name the rendered markup should use.
   *
   * E.g., `vvja-accordion`, `vvjs-slideshow`, `vvjl-lightbox`. Must
   * contain a hyphen per the Custom Elements spec.
   */
  abstract public function getCustomElementTag(): string;

  /**
   * Add pattern-specific option defaults to the options array.
   *
   * Called from `defineOptions()` after the shared options
   * (`unique_id`, `enable_css`, `enable_deeplink`, `deeplink_identifier`)
   * have been set. Subclasses add their pattern-specific options here
   * and return the augmented array.
   *
   * @param array<string, array{default: mixed}> $options
   *   Options array as built so far, with shared defaults already in.
   *
   * @return array<string, array{default: mixed}>
   *   Augmented options array.
   */
  abstract protected function definePatternOptions(array $options): array;

  /**
   * Add pattern-specific form sections to the options form.
   *
   * Called from `buildOptionsForm()` between the shared warning
   * message and the shared advanced/deeplinking/token-doc sections.
   * Subclasses add their behavior, animation, layout, etc. sections
   * here.
   *
   * @param array<int|string, mixed> $form
   *   The Views style options form, modified in place.
   */
  abstract protected function buildPatternSections(array &$form): void;

  /*
   * Optional overrides — defaults are sensible.
   */

  /**
   * Whether this pattern supports deep-linking (URL-fragment open).
   *
   * Override to FALSE for patterns where deep-linking doesn't apply
   * (e.g., parallax — there's nothing to "open").
   */
  protected function supportsDeeplinking(): bool {
    return TRUE;
  }

  /**
   * Whether this pattern offers an opt-out CSS-library checkbox.
   *
   * Override to FALSE for patterns whose CSS is structurally required
   * (i.e., the JS won't work without it).
   */
  protected function supportsEnableCss(): bool {
    return TRUE;
  }

  /**
   * Whether this pattern requires the "fields" row plugin.
   *
   * Most VVJ patterns render individual fields and require the Fields row
   * style, so the default is TRUE — the row is enforced, the "requires
   * Fields" validation fires, and the informational warning shows.
   *
   * Patterns that can render any row plugin (e.g. vvjs's regular slideshow,
   * which only needs Fields in hero mode) override this and return FALSE
   * when Fields is not required for the current options — so Views like an
   * `entity:node` teaser slideshow are neither rejected nor force-switched.
   *
   * @return bool
   *   TRUE when the Fields row plugin is required for the current options.
   */
  protected function requiresFieldsRow(): bool {
    return TRUE;
  }

  /**
   * Reserved words the deeplink identifier cannot equal.
   *
   * Default returns the shared list from `ValidationBounds`. Patterns
   * with their own URL-fragment prefixes (e.g. `#carousel-`, `#tabs-`)
   * should override and add those slugs so the validator rejects them.
   *
   * @return list<string>
   *   Lower-case reserved words.
   */
  protected function getDeeplinkReservedWords(): array {
    return ValidationBounds::DEEPLINK_RESERVED_WORDS;
  }

  /**
   * Animation presets the pattern offers in its animation select.
   *
   * Default returns the shared 7-preset set (None / Fade / Zoom / four
   * slide directions). Subclasses can return a subset or add presets.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Preset key → translatable label.
   */
  protected function getAnimationPresets(): array {
    /** @var array<string, \Drupal\Core\StringTranslation\TranslatableMarkup> $presets */
    $presets = [
      AnimationDirection::NONE => $this->t('None'),
      AnimationDirection::FADE => $this->t('Fade'),
      AnimationDirection::ZOOM => $this->t('Zoom'),
      AnimationDirection::TOP => $this->t('Slide from Top'),
      AnimationDirection::BOTTOM => $this->t('Slide from Bottom'),
      AnimationDirection::LEFT => $this->t('Slide from Left'),
      AnimationDirection::RIGHT => $this->t('Slide from Right'),
    ];
    return $presets;
  }

  /*
   * defineOptions() — shared options + pattern-specific via subclass.
   */

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{default: mixed}>
   *   Views style option defaults keyed by option name.
   */
  protected function defineOptions(): array {
    /** @var array<string, array{default: mixed}> $options */
    $options = parent::defineOptions();
    $options['unique_id'] = ['default' => $this->generateUniqueId()];

    if ($this->supportsEnableCss()) {
      $options['enable_css'] = ['default' => TRUE];
    }

    if ($this->supportsDeeplinking()) {
      $options['enable_deeplink'] = ['default' => FALSE];
      $options['deeplink_identifier'] = ['default' => ''];
    }

    return $this->definePatternOptions($options);
  }

  /*
   * buildOptionsForm() — orchestrates shared + pattern sections.
   */

  /**
   * {@inheritdoc}
   *
   * @param mixed $form
   *   The Views style options form fragment.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the views display form.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    if (!is_array($form)) {
      return;
    }

    $this->enforceFieldsRowPlugin();
    $this->setDefaultElementWeights($form);
    $this->buildWarningMessage($form);

    // Pattern-specific sections (subclass-defined order).
    $this->buildPatternSections($form);

    // Shared trailing sections — same in every pattern.
    if ($this->supportsEnableCss()) {
      $this->buildAdvancedSection($form);
    }
    if ($this->supportsDeeplinking()) {
      $this->buildDeepLinkingSection($form);
    }
    $this->buildTokenDocumentation($form);
    $this->attachAdminAssets($form);
  }

  /*
   * Shared form-section builders (used by every pattern).
   */

  /**
   * Push parent Views form fields below the pattern's own sections.
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function setDefaultElementWeights(array &$form): void {
    $weights = [
      'grouping' => -100,
      'row_class' => -90,
      'default_row_class' => -85,
      'uses_fields' => -80,
      'class' => -75,
      'wrapper_class' => -70,
    ];
    foreach ($weights as $key => $weight) {
      if (isset($form[$key]) && is_array($form[$key])) {
        $form[$key]['#weight'] = $weight;
      }
    }
  }

  /**
   * Friendly nudge to use an example view as a starting point.
   *
   * Looks up `<slug>_example` and links to it. Skipped when the user
   * is already editing that example view.
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function buildWarningMessage(array &$form): void {
    if (!$this->requiresFieldsRow()) {
      return;
    }

    $slug = $this->getModuleSlug();
    $example_id = $slug . '_example';

    if ($this->view->storage->id() === $example_id) {
      return;
    }

    $form['warning_message'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status">' . $this->t(
        'Note: The @slug component requires Fields as row style. To see an example, check the @example view by clicking <a href="@url">here</a> to edit it.',
        [
          '@slug' => $slug,
          '@example' => $example_id,
          '@url' => Url::fromRoute('entity.view.edit_form', ['view' => $example_id])->toString(),
        ],
      ) . '</div>',
      '#weight' => -50,
    ];
  }

  /**
   * Standard "Advanced Options" section — `enable_css` checkbox only.
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function buildAdvancedSection(array &$form): void {
    $form['advanced_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
      '#weight' => -10,
    ];
    $form['advanced_section']['enable_css'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CSS Library'),
      '#default_value' => $this->options['enable_css'] ?? TRUE,
      '#description' => $this->getEnableCssDescription(),
    ];
  }

  /**
   * The "Enable CSS Library" checkbox description.
   *
   * Patterns override this to restore v1's pattern-specific wording (e.g.
   * "...for styling the accordion").
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  protected function getEnableCssDescription(): TranslatableMarkup {
    return $this->t(
      'Include the default %slug stylesheet. Uncheck to provide your own theme CSS.',
      ['%slug' => $this->getModuleSlug()],
    );
  }

  /**
   * The "Enable Deep Linking" checkbox description.
   *
   * Patterns override this to restore v1's pattern-specific wording (fragment
   * example + any navigation requirement note).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  protected function getDeeplinkEnableDescription(): TranslatableMarkup {
    return $this->t('Generate shareable URL fragments for each panel/slide/section.');
  }

  /**
   * The deep-link "URL Identifier" field description.
   *
   * Patterns override this to restore v1's pattern-specific wording (fragment
   * example).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  protected function getDeeplinkIdentifierDescription(): TranslatableMarkup {
    return $this->t(
      'Short identifier used in URL fragments. Will be slug-normalized: lowercased, spaces → hyphens, special chars stripped.',
    );
  }

  /**
   * Standard "Deep Linking Settings" section — enable + identifier.
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function buildDeepLinkingSection(array &$form): void {
    $form['deeplink_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Deep Linking Settings'),
      '#open' => FALSE,
      '#weight' => -5,
    ];

    $form['deeplink_section']['enable_deeplink'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Deep Linking'),
      '#description' => $this->getDeeplinkEnableDescription(),
      '#default_value' => $this->options['enable_deeplink'] ?? FALSE,
      '#attributes' => [
        'data-' . $this->getModuleSlug() . '-deeplink-toggle' => 'true',
      ],
    ];

    $form['deeplink_section']['deeplink_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL Identifier'),
      '#description' => $this->getDeeplinkIdentifierDescription(),
      '#default_value' => $this->options['deeplink_identifier'] ?? '',
      '#maxlength' => ValidationBounds::DEEPLINK_IDENTIFIER_MAX_LENGTH,
      '#size' => 20,
      '#placeholder' => 'my-' . $this->getModuleSlug(),
      '#wrapper_attributes' => [
        'class' => ['deeplink-identifier-wrapper'],
        'data-' . $this->getModuleSlug() . '-deeplink-field' => 'true',
      ],
      '#element_validate' => [[$this, 'validateDeeplinkIdentifier']],
    ];
  }

  /**
   * Validate + slug-normalize the deeplink identifier.
   *
   * @param array<string, mixed> $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateDeeplinkIdentifier(array $element, FormStateInterface $form_state): void {
    $values = $form_state->getValue(['style_options', 'deeplink_section']);
    if (!is_array($values)) {
      return;
    }
    $enabled = (bool) ($values['enable_deeplink'] ?? FALSE);
    $raw_identifier = $values['deeplink_identifier'] ?? '';
    $identifier = is_scalar($raw_identifier) ? (string) $raw_identifier : '';

    if (!$enabled) {
      // Clear any stored identifier so a stale value doesn't persist in
      // config or reappear pre-filled if deep linking is re-enabled later
      // (v1 parity).
      $form_state->setValue(
        ['style_options', 'deeplink_section', 'deeplink_identifier'],
        '',
      );
      return;
    }

    if ($identifier === '') {
      $form_state->setError($element, $this->t('URL Identifier is required when Deep Linking is enabled.'));
      return;
    }

    $clean = $this->slugifyIdentifier($identifier);

    if ($clean === '') {
      $form_state->setError($element, $this->t('URL Identifier must contain at least one letter.'));
      return;
    }

    if (in_array($clean, $this->getDeeplinkReservedWords(), TRUE)) {
      $form_state->setError(
        $element,
        $this->t('Please choose a more specific identifier. "@id" is reserved.', ['@id' => $clean]),
      );
      return;
    }

    $form_state->setValue(['style_options', 'deeplink_section', 'deeplink_identifier'], $clean);
  }

  /**
   * Standard "Token Documentation" section — uses module slug for examples.
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function buildTokenDocumentation(array &$form): void {
    $slug = $this->getModuleSlug();

    $form['token_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Token Documentation'),
      '#open' => FALSE,
      '#weight' => 100,
    ];
    $form['token_section']['description'] = [
      '#markup' => $this->t('<p>When using <em>Global: Text area</em> or <em>Global: Unfiltered text</em> in the Views header, footer, or empty text areas, the default Twig-style tokens (e.g., <code>{{ title }}</code>) will not work with the @slug style plugin.</p>
        <p>Instead, use the custom @slug token format to access field values from the <strong>first row</strong> of the View result:</p>
        <ul>
          <li><code>[@slug:field_name]</code> — The rendered output of the field (e.g., linked title, image, formatted text).</li>
          <li><code>[@slug:field_name:plain]</code> — A plain-text version of the field, with all HTML stripped.</li>
        </ul>
        <p>Examples:</p>
        <ul>
          <li><code>{{ title }}</code> ➜ <code>[@slug:title]</code></li>
          <li><code>{{ field_image }}</code> ➜ <code>[@slug:field_image]</code></li>
          <li><code>{{ body }}</code> ➜ <code>[@slug:body:plain]</code></li>
        </ul>
        <p>These tokens offer safe and flexible field output for dynamic headings, summaries, and fallback messages in @upper-enabled Views.</p>', [
          '@slug' => $slug,
          '@upper' => strtoupper($slug),
        ]),
    ];
  }

  /**
   * Get a standard breakpoint select render array.
   *
   * Pattern modules call this from their pattern-section builders to
   * include a consistent breakpoint dropdown. The values come from
   * `BreakpointRegistry`.
   *
   * @param string $option_key
   *   The option key the value is stored under (typically `breakpoints`
   *   or `available_breakpoints`).
   * @param string|null $default
   *   Optional default override. Falls back to BreakpointRegistry.
   *
   * @return array<string, mixed>
   *   Render array suitable for use in a `details` section.
   */
  protected function buildBreakpointSelect(string $option_key, ?string $default = NULL): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('Active Breakpoint'),
      '#options' => $this->breakpointRegistry->getOptions(),
      '#default_value' => $this->options[$option_key]
      ?? $default
      ?? $this->breakpointRegistry->getDefault(),
      '#description' => $this->t('Switch responsive behavior at this viewport width.'),
    ];
  }

  /**
   * Get a standard animation-direction select render array.
   *
   * @param string $option_key
   *   The option key (typically `animation`).
   *
   * @return array<string, mixed>
   *   Render array.
   */
  protected function buildAnimationSelect(string $option_key = 'animation'): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('Animation'),
      '#options' => $this->getAnimationPresets(),
      '#default_value' => $this->options[$option_key] ?? AnimationDirection::DEFAULT_VALUE,
      '#description' => $this->t('Animation for state transitions. Reduced-motion users see no animation regardless of this setting.'),
    ];
  }

  /**
   * Force the row plugin to "fields" so VVJ structure works.
   */
  protected function enforceFieldsRowPlugin(): void {
    if (!$this->requiresFieldsRow()) {
      return;
    }
    if ($this->view && $this->view->rowPlugin && $this->view->rowPlugin->getPluginId() !== 'fields') {
      $this->view->display_handler->setOption('row', [
        'type' => 'fields',
        'options' => [],
      ]);
    }
  }

  /**
   * Attach admin-only form assets (per-module admin library + core.ajax).
   *
   * @param array<int|string, mixed> $form
   *   The form, modified in place.
   */
  protected function attachAdminAssets(array &$form): void {
    $slug = $this->getModuleSlug();
    $attached = isset($form['#attached']) && is_array($form['#attached'])
      ? $form['#attached']
      : [];
    $library = isset($attached['library']) && is_array($attached['library'])
      ? $attached['library']
      : [];
    $library[] = 'core/drupal.ajax';
    $library[] = $slug . '/' . $slug . '-admin';
    $attached['library'] = $library;
    $form['#attached'] = $attached;
  }

  /*
   * Helpers.
   */

  /**
   * Slug-normalize a deeplink identifier (transliterate + clean).
   */
  private function slugifyIdentifier(string $identifier): string {
    $clean = (string) $this->transliteration->transliterate($identifier, 'en');
    $clean = strtolower($clean);
    $clean = (string) preg_replace('/[\s_]+/', '-', $clean);
    $clean = (string) preg_replace('/[^a-z0-9-]/', '', $clean);
    $clean = (string) preg_replace('/-+/', '-', $clean);
    $clean = trim($clean, '-');
    return (string) preg_replace('/^[0-9-]+/', '', $clean);
  }

  /**
   * Generate a unique numeric ID for this view display.
   *
   * Cached per plugin instance so `defineOptions()` doesn't generate a
   * fresh ID every time the form rebuilds.
   */
  protected function generateUniqueId(): int {
    if ($this->cachedUniqueId !== NULL) {
      return $this->cachedUniqueId;
    }
    // The generator service may not be available during early
    // bootstrap (e.g., views config schema discovery). Fall back to
    // direct random_int() in that case.
    if (isset($this->uniqueIdGenerator)) {
      $this->cachedUniqueId = $this->uniqueIdGenerator->generate();
    }
    else {
      $this->cachedUniqueId = random_int(
        ValidationBounds::MIN_UNIQUE_ID,
        ValidationBounds::MAX_UNIQUE_ID,
      );
    }
    return $this->cachedUniqueId;
  }

  /*
   * render() + library list + validate().
   */

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array for the pattern's theme hook.
   */
  public function render(): array {
    /** @var list<array<string, mixed>> $rows */
    $rows = [];

    if (!empty($this->view->result)) {
      foreach ($this->view->result as $row) {
        $rendered = $this->view->rowPlugin->render($row);
        if ($rendered !== NULL) {
          $rows[] = $rendered;
        }
      }
    }

    $cache_metadata = $this->view->display_handler->getCacheMetadata();

    return [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#rows' => $rows,
      '#unique_id' => $this->options['unique_id'] ?? $this->generateUniqueId(),
      '#attached' => [
        'library' => $this->buildLibraryList(),
      ],
      '#cache' => [
        'tags' => $cache_metadata->getCacheTags(),
        'contexts' => $cache_metadata->getCacheContexts(),
        'max-age' => $cache_metadata->getCacheMaxAge(),
      ],
    ];
  }

  /**
   * Build the libraries-to-attach list.
   *
   * - Always: `<slug>/<slug>` (the pattern's main JS+CSS library)
   * - When enable_css is on: `<slug>/<slug>-style` (opt-in visual layer)
   * - Always: `vvj_core/tokens`, `vvj_core/base`, `vvj_core/a11y`,
   *   `vvj_core/element-base` (foundation libraries)
   *
   * @return list<string>
   *   Library identifiers.
   */
  protected function buildLibraryList(): array {
    $slug = $this->getModuleSlug();
    /** @var list<string> $libraries */
    $libraries = [
      'vvj_core/tokens',
      'vvj_core/base',
      'vvj_core/a11y',
      'vvj_core/element-base',
      $slug . '/' . $slug,
    ];
    if ($this->supportsEnableCss() && !empty($this->options['enable_css'])) {
      $libraries[] = $slug . '/' . $slug . '-style';
    }
    return $libraries;
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Validation errors. Empty list means valid.
   */
  public function validate(): array {
    $parent_errors = parent::validate();
    /** @var list<string|\Drupal\Core\StringTranslation\TranslatableMarkup> $errors */
    $errors = [];
    if (is_array($parent_errors)) {
      foreach ($parent_errors as $message) {
        if (is_string($message) || $message instanceof TranslatableMarkup) {
          $errors[] = $message;
        }
      }
    }
    if ($this->requiresFieldsRow() && !$this->usesFields()) {
      $errors[] = $this->t(
        '@slug requires Fields as row style.',
        ['@slug' => $this->getModuleSlug()],
      );
    }
    return $errors;
  }

}
