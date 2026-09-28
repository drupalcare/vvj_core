<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\vvj_core_ai\Model\DraftedView;
use Drupal\vvj_core_ai\Model\ViewRequest;

/**
 * Default drafter: one structured request to the site's chat provider.
 */
final class ViewDrafter implements ViewDrafterInterface {

  use StringTranslationTrait;

  /**
   * The longest label kept: asked of the model, and enforced on its answer.
   */
  private const LABEL_MAX = 60;

  /**
   * Constructs the drafter.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads which provider and model the site chose.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $providerManager
   *   Builds the provider. Typed through the core interface because drupal/ai
   *   is absent whenever this project is built from its own composer.json.
   * @param \Drupal\vvj_core_ai\Service\ViewPlannerInterface $planner
   *   The fields a person could sensibly show for a content type.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   Field labels, so the model reads "Image", not a machine name.
   * @param \Drupal\vvj_core_ai\Service\BriefsInterface $briefs
   *   What the chosen format is good for.
   * @param \Drupal\vvj_core_ai\Service\FormatRegistryInterface $formats
   *   The format's label.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PluginManagerInterface $providerManager,
    private readonly ViewPlannerInterface $planner,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly BriefsInterface $briefs,
    private readonly FormatRegistryInterface $formats,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function draft(string $format, string $entityType, string $bundle, bool $withFields, string $words): DraftedView {
    $words = trim($words);

    if ($words === '') {
      return DraftedView::refused([(string) $this->t('Say what the view should show.')]);
    }

    [$providerId, $modelId] = $this->chosenProvider();

    if ($providerId === '') {
      return DraftedView::refused([(string) $this->t('No default AI provider is set for chat. Choose one at Configuration › AI › Settings.')]);
    }

    $fields = $withFields ? $this->fieldLabels($entityType, $bundle) : [];

    if ($withFields && $fields === []) {
      return DraftedView::refused([(string) $this->t('This content type has no fields a view could show.')]);
    }

    $properties = [
      'label' => [
        'type' => 'string',
        'maxLength' => self::LABEL_MAX,
        'description' => 'A short human name for the view, such as "Latest articles".',
      ],
      'sort' => [
        'type' => 'string',
        'enum' => ViewRequest::SORTS,
        'description' => 'The order: newest, oldest, title_az or title_za.',
      ],
    ];

    if ($withFields) {
      $properties['fields'] = [
        'type' => 'array',
        'description' => 'The fields each item shows, in order. Only these exist: ' . implode(', ', array_map(
          static fn(string $name, string $label): string => sprintf('%s (%s)', $name, $label),
          array_keys($fields),
          $fields,
        )),
        'items' => ['type' => 'string', 'enum' => array_keys($fields)],
      ];
    }

    $known = $this->formats->get($format);
    $goodFor = $this->briefs->forFormat($format)['good_for'] ?? '';
    $lines = [
      sprintf('You are choosing the parts of one Drupal view shown as "%s".', $known?->label ?? $format),
      $goodFor === '' ? '' : 'That format is good for: ' . $goodFor,
      'Answer only with the properties in the schema. Name the view in a few words.',
      $withFields ? 'Choose only the fields the person asked for, or the few that suit the format. Never invent a field.' : '',
      'Pick the sort the person asked for; newest when they did not say.',
    ];

    $input = new ChatInput([new ChatMessage('user', $words)]);
    $input->setSystemPrompt(implode("\n", array_filter($lines)));
    $input->setChatStructuredJsonSchema(new StructuredOutputSchema(
      name: 'vvj_view',
      description: 'The parts of one view.',
      strict: FALSE,
      json_schema: ['type' => 'object', 'properties' => $properties],
    ));

    try {
      $provider = $this->providerManager->createInstance($providerId);
      $answer = $provider->chat($input, $modelId, ['vvj_core_ai'])->getNormalized();
      $decoded = json_decode(method_exists($answer, 'getText') ? (string) $answer->getText() : '', TRUE);
    }
    catch (\Throwable $error) {
      // The provider's own message is relayed: "rate limited" and "model not
      // found" need different actions from the person.
      return DraftedView::refused([(string) $this->t('The AI provider could not answer: @error', ['@error' => $error->getMessage()])]);
    }

    if (!is_array($decoded)) {
      return DraftedView::refused([(string) $this->t('The model did not answer in the shape a view needs. Try again, or say it differently.')]);
    }

    // The schema's maxLength is a request to the model, not a guarantee.
    $label = is_string($decoded['label'] ?? NULL) ? mb_substr(trim($decoded['label']), 0, self::LABEL_MAX) : '';
    $sort = in_array($decoded['sort'] ?? NULL, ViewRequest::SORTS, TRUE) ? (string) $decoded['sort'] : 'newest';
    $chosen = [];

    // HELD TO THE LIST, in the model's order, once each.
    foreach (is_array($decoded['fields'] ?? NULL) ? $decoded['fields'] : [] as $name) {
      if (is_string($name) && isset($fields[$name]) && !in_array($name, $chosen, TRUE)) {
        $chosen[] = $name;
      }
    }

    if ($label === '') {
      return DraftedView::refused([(string) $this->t('The model did not name the view. Try again, or say what it should be called.')]);
    }

    if ($withFields && $chosen === []) {
      return DraftedView::refused([(string) $this->t('The model chose no fields that exist on this content type. Name the fields you want.')]);
    }

    return DraftedView::of($label, $chosen, $sort);
  }

  /**
   * The provider and model the site chose, by name.
   *
   * @return array{0: string, 1: string}
   *   Provider id and model id, both empty when none is set.
   */
  private function chosenProvider(): array {
    $providers = $this->configFactory->get('ai.settings')->get('default_providers');
    $chat = is_array($providers) ? ($providers['chat'] ?? NULL) : NULL;

    if (!is_array($chat)) {
      return ['', ''];
    }

    return [
      is_string($chat['provider_id'] ?? NULL) ? $chat['provider_id'] : '',
      is_string($chat['model_id'] ?? NULL) ? $chat['model_id'] : '',
    ];
  }

  /**
   * The fields the planner offers, with their labels.
   *
   * @return array<string, string>
   *   Field machine name => label.
   */
  private function fieldLabels(string $entityType, string $bundle): array {
    $definitions = $this->fieldManager->getFieldDefinitions($entityType, $bundle);
    $out = [];

    foreach ($this->planner->fieldsOn($entityType, $bundle) as $name) {
      $out[$name] = isset($definitions[$name]) ? (string) $definitions[$name]->getLabel() : $name;
    }

    return $out;
  }

}
