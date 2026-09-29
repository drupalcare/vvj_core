<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai\Service;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
        'description' => 'The heading a visitor sees above the block, two to four words, such as "Latest news" or "Our team". Never a description of the view.',
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
      'Answer only with the properties in the schema. The label is the heading a visitor reads, not a description: "Latest news", not "Six latest articles accordion". No counts, no format names.',
      $withFields ? 'Choose only the fields the person asked for, or the few that suit the format. Never invent a field.' : '',
      'Pick the sort the person asked for; newest when they did not say.',
    ];

    $input = new ChatInput([new ChatMessage('user', $words)]);
    $input->setSystemPrompt(implode("\n", array_filter($lines)));
    $input->setChatStructuredJsonSchema(new StructuredOutputSchema(
      name: 'vvj_view',
      description: 'The parts of one view.',
      strict: FALSE,
      json_schema: ['type' => 'object', 'properties' => $properties, 'required' => ['label']],
    ));

    try {
      $provider = $this->providerManager->createInstance($providerId);
      $answer = $provider->chat($input, $modelId, ['vvj_core_ai'])->getNormalized();
      $decoded = json_decode(method_exists($answer, 'getText') ? (string) $answer->getText() : '', TRUE);
    }
    catch (\Throwable $error) {
      return DraftedView::refused([(string) $this->providerFailure($error)]);
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

    // NO NAME IS NOT A REASON TO THROW AWAY A PAID ANSWER. Measured live
    // 2026-09-27: Slideshow came back with fields and a sort but no label, and
    // was refused. The format's own short name stands in; the person renames
    // it in one click, and the planner keeps the machine name free.
    if ($label === '') {
      $label = $this->shortName($known?->label ?? $format);
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

  /**
   * A format's label without the product prefix: "Slideshow".
   *
   * @param string $label
   *   The format label, e.g. "Views Vanilla JavaScript Slideshow".
   *
   * @return string
   *   The short name, or the label itself when there is no prefix.
   */
  private function shortName(string $label): string {
    $short = trim((string) preg_replace('/^Views Vanilla JavaScript\\s+/i', '', $label));

    return mb_substr($short === '' ? $label : $short, 0, self::LABEL_MAX);
  }

  /**
   * What to tell the person when the provider did not answer.
   *
   * A timeout gets its own sentence. Measured 2026-09-28: a 3D carousel of
   * seven items took longer than the site's 60-second limit twice, and the
   * person saw raw cURL text with nothing saying where the limit lives.
   *
   * @param \Throwable $error
   *   What the provider threw.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   One plain sentence.
   */
  private function providerFailure(\Throwable $error): TranslatableMarkup {
    if (preg_match('/cURL error 28|timed out/i', $error->getMessage())) {
      $set = $this->configFactory->get('ai.settings')->get('request_timeout');
      $limit = is_numeric($set) && (int) $set > 0 ? (int) $set : 60;

      return $this->t('The AI took longer than this site allows (@seconds seconds) and the answer was lost. Ask for fewer items, or raise "Request timeout" at Configuration › AI › Settings, under Advanced.', [
        '@seconds' => $limit,
      ]);
    }

    // The provider's own message is relayed rather than replaced: "model not
    // found" and "rate limited" need different actions from the person.
    return $this->t('The AI provider could not answer: @error', ['@error' => $error->getMessage()]);
  }

}
