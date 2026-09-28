<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Form;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\vvj_core_ai\Model\ViewRequest;
use Drupal\vvj_core_ai\Service\BriefsInterface;
use Drupal\vvj_core_ai\Service\FormatRegistryInterface;
use Drupal\vvj_core_ai\Service\RowRulesInterface;
use Drupal\vvj_core_ai\Service\ViewCreatorInterface;
use Drupal\vvj_core_ai\Service\ViewDrafterInterface;
use Drupal\vvj_core_ai\Service\ViewFeaturesInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The views assistant: choose a format and content, say what to show.
 *
 * The PB-AI shape, on the maintainer's instruction of 2026-09-27 — no chat,
 * no agent. The person decides the format, the content type, the row and the
 * count; the model only names the view and picks its fields and sort; the
 * creator re-checks all of it and saves the view disabled.
 */
final class VvjAiViewForm extends FormBase {

  /**
   * The formats.
   */
  protected FormatRegistryInterface $formats;

  /**
   * The per-format briefs.
   */
  protected BriefsInterface $briefs;

  /**
   * Which formats keep a view-mode row.
   */
  protected RowRulesInterface $rowRules;

  /**
   * The features each format is created with.
   */
  protected ViewFeaturesInterface $features;

  /**
   * Asks the model for the name, fields and sort.
   */
  protected ViewDrafterInterface $drafter;

  /**
   * Builds and saves the view.
   */
  protected ViewCreatorInterface $creator;

  /**
   * The content types.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The view modes.
   */
  protected EntityDisplayRepositoryInterface $displayRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static();
    $form->formats = $container->get(FormatRegistryInterface::class);
    $form->briefs = $container->get(BriefsInterface::class);
    $form->rowRules = $container->get(RowRulesInterface::class);
    $form->features = $container->get(ViewFeaturesInterface::class);
    $form->drafter = $container->get(ViewDrafterInterface::class);
    $form->creator = $container->get(ViewCreatorInterface::class);
    $form->bundleInfo = $container->get('entity_type.bundle.info');
    $form->displayRepository = $container->get('entity_display.repository');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vvj_core_ai_view_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $formats = [];
    $viewModeFormats = [];

    foreach ($this->formats->all() as $format) {
      $formats[$format->pluginId] = $format->label;

      // Asked with the features the view will really have: Slideshow keeps a
      // view mode only outside hero mode.
      if ($this->rowRules->allowsViewMode($format->pluginId, $this->features->valuesFor($format->pluginId))) {
        $viewModeFormats[] = $format->pluginId;
      }
    }

    $form['#attributes'] = ['class' => ['vvj-ai-panel']];

    // No chat provider: say where to choose one, rather than a form that can
    // only fail on submit.
    $chat = $this->config('ai.settings')->get('default_providers.chat.provider_id');

    if (!is_string($chat) || $chat === '') {
      $form['no_provider'] = [
        '#markup' => '<p>' . $this->t('No default AI provider is set for chat. Choose one at Configuration › AI › Settings, then come back.') . '</p>',
      ];
      $form['#cache'] = ['tags' => ['config:ai.settings']];

      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose a VVJ format and the content, then say what it should show. The view is created as a block and left disabled, so you can check it before enabling it.') . '</p>',
    ];

    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Format'),
      '#options' => $formats,
      '#empty_option' => $this->t('- Choose a format -'),
      '#required' => TRUE,
    ];

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => array_map(
        static fn(array $info): string => (string) $info['label'],
        $this->bundleInfo->getBundleInfo('node'),
      ),
      '#required' => TRUE,
    ];

    // FIELDS OR A VIEW MODE — maintainer, 2026-09-27: "we must know when we
    // can field or view modes". Offered only where the format keeps it.
    $modes = ['fields' => $this->t('Fields')];

    foreach ($this->displayRepository->getViewModeOptions('node') as $id => $label) {
      $modes[$id] = $this->t('View mode: @mode', ['@mode' => $label]);
    }

    $form['show_as'] = [
      '#type' => 'select',
      '#title' => $this->t('Show each item as'),
      '#options' => $modes,
      '#default_value' => 'fields',
      '#states' => ['visible' => $this->formatIs($viewModeFormats)],
      '#description' => $this->t('Only these formats can show whole items in a view mode; the others always show fields.'),
    ];

    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('How many'),
      '#min' => 0,
      '#max' => 50,
      '#default_value' => 0,
      '#description' => $this->t('0 uses the best number for the format. There is no pager.'),
    ];

    $form['words'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What should it show?'),
      '#rows' => 2,
      '#required' => TRUE,
      '#placeholder' => $this->t('The newest articles, image and title, newest first.'),
    ];

    $form['block_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block title'),
      '#maxlength' => 255,
      '#description' => $this->t('Shown above the block. Leave empty to use the name the view is given.'),
    ];

    $form['page'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also make a page'),
    ];

    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page path'),
      '#field_prefix' => '/',
      '#states' => ['visible' => [':input[name="page"]' => ['checked' => TRUE]]],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create the view'),
      '#button_type' => 'primary',
      // Said before the click, not after: the words go to a paid service.
      '#suffix' => '<p class="vvj-ai-cost">' . $this->t('Creating the view sends your words to the AI provider chosen for this site, which may charge for each request.') . '</p>',
    ];

    // THE SAME TWO SWITCHES AS PB: they show or hide the brief panel, which
    // appears when a format is chosen. Remembered per browser by the script.
    $form['actions']['prompts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prompts'),
      '#default_value' => TRUE,
      '#wrapper_attributes' => ['class' => ['vvj-ai-switch']],
    ];
    $form['actions']['best_practices'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Best practices'),
      '#default_value' => TRUE,
      '#wrapper_attributes' => ['class' => ['vvj-ai-switch']],
    ];

    // Each format's brief for the panel. The text is the site's own config and
    // is written into the page as text by the script, never as markup.
    $panel = [];

    foreach (array_keys($formats) as $pluginId) {
      $brief = $this->briefs->forFormat($pluginId);

      if ($brief === NULL) {
        continue;
      }

      $prompts = [];

      foreach ($brief['prompts'] as $name => $text) {
        $prompts[] = ['name' => (string) $name, 'text' => $text];
      }

      $panel[$pluginId] = [
        'good_for' => $brief['good_for'],
        'practices' => $brief['practices'],
        'prompts' => $prompts,
      ];
    }

    $form['#attached'] = [
      'library' => ['vvj_core_ai/brief'],
      'drupalSettings' => ['vvjAi' => ['briefs' => $panel]],
    ];
    $form['#cache'] = ['tags' => ['config:vvj_core_ai.briefs', 'config:ai.settings']];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $format = self::text($form_state->getValue('format'));

    if ($this->formats->get($format) === NULL) {
      $form_state->setErrorByName('format', $this->t('Choose a format.'));
    }

    if ($form_state->getValue('page') && trim(self::text($form_state->getValue('path')), ' /') === '') {
      $form_state->setErrorByName('path', $this->t('Give the page a path.'));
    }

    if ($form_state->hasAnyErrors()) {
      return;
    }

    // WHAT CAN ONLY BE REFUSED IS REFUSED HERE, before the paid AI call: no
    // image field for an image-first format, a path already in use.
    $elements = ['format' => 'format', 'view_mode' => 'show_as', 'path' => 'path'];

    foreach ($this->creator->precheck($this->request($form_state, '', [])) as $part => $sentence) {
      $form_state->setErrorByName($elements[$part], $sentence);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $asked = $this->request($form_state, '', []);
    $draft = $this->drafter->draft($asked->format, 'node', $asked->bundle, $asked->viewMode === '', self::text($form_state->getValue('words')));

    if (!$draft->usable) {
      array_map(fn(string $sentence) => $this->messenger()->addError($sentence), $draft->refusals);
      $form_state->setRebuild();

      return;
    }

    $result = $this->creator->create($this->request($form_state, $draft->label, $draft->fields, $draft->sort));

    if (!$result['created']) {
      array_map(fn(string $sentence) => $this->messenger()->addError($sentence), $result['refusals']);
      $form_state->setRebuild();

      return;
    }

    foreach ($result['notes'] as $note) {
      $this->messenger()->addStatus($note);
    }

    $switchedOn = $this->features->describe($asked->format, $asked->styleOptions);

    if ($switchedOn !== []) {
      $this->messenger()->addStatus($this->t('Switched on: @features. Change or turn any of them off in the format settings.', [
        '@features' => implode('; ', $switchedOn),
      ]));
    }

    $form_state->setRedirectUrl(Url::fromRoute('entity.view.edit_form', ['view' => $result['id']]));
  }

  /**
   * The request the form describes, with the parts the model decides.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $label
   *   The view's name, or '' before the model has answered.
   * @param list<string> $fields
   *   The fields to show, or [] before the model has answered.
   * @param string $sort
   *   One of ViewRequest::SORTS.
   *
   * @return \Drupal\vvj_core_ai\Model\ViewRequest
   *   The request.
   */
  private function request(FormStateInterface $form_state, string $label, array $fields, string $sort = 'newest'): ViewRequest {
    $format = self::text($form_state->getValue('format'));
    $features = $this->features->valuesFor($format);
    $showAs = self::text($form_state->getValue('show_as'));
    $count = (int) self::text($form_state->getValue('count'));

    return new ViewRequest(
      entityType: 'node',
      bundle: self::text($form_state->getValue('bundle')),
      format: $format,
      label: $label,
      fields: $fields,
      // Only where the format keeps a view mode; the field is hidden for the
      // others and may still hold an old choice. The creator re-checks.
      viewMode: $showAs !== 'fields' && $this->rowRules->allowsViewMode($format, $features) ? $showAs : '',
      count: $count > 0 ? $count : ($this->briefs->forFormat($format)['count'] ?? 6),
      pagePath: $form_state->getValue('page') ? self::text($form_state->getValue('path')) : '',
      sort: $sort,
      styleOptions: $features,
      blockTitle: trim(self::text($form_state->getValue('block_title'))),
    );
  }

  /**
   * A submitted value as text; anything that is not a scalar is empty.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return string
   *   The value as a string.
   */
  private static function text(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * A #states condition: the chosen format is one of these.
   *
   * @param list<string> $pluginIds
   *   Format plugin ids.
   *
   * @return list<mixed>
   *   The condition, in the `or` form #states takes.
   */
  private function formatIs(array $pluginIds): array {
    $out = [];

    foreach ($pluginIds as $pluginId) {
      if ($out !== []) {
        $out[] = 'or';
      }
      $out[] = [':input[name="format"]' => ['value' => $pluginId]];
    }

    return $out;
  }

}
