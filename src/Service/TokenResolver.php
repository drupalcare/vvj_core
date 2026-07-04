<?php

declare(strict_types=1);

namespace Drupal\vvj_core\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\ViewExecutable;
use Drupal\vvj_core\Constants\ValidationBounds;
use Psr\Log\LoggerInterface;

/**
 * Centralised `[vvjX:field]` token resolution for every VVJ pattern.
 *
 * Each VVJ pattern declares its own token namespace (`vvja`, `vvjs`,
 * etc.). v1 modules each shipped near-identical `<module>_tokens()`
 * implementations — this service consolidates the logic so a fix in
 * the token resolver lands once and applies to every pattern.
 *
 * Token forms:
 *   [{ns}:FIELD]        — rendered HTML (Xss::filterAdmin)
 *   [{ns}:FIELD:plain]  — plain text (HTML stripped + entities decoded)
 *
 * Tokens read from the FIRST row of the view result so they work in
 * Views header / footer / empty-text contexts.
 */
final class TokenResolver {

  /**
   * Logger for token errors / invalid-format warnings.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Resolves the `vvj_core` channel.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   For rendering field render arrays into HTML strings.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly RendererInterface $renderer,
  ) {
    $this->logger = $loggerFactory->get('vvj_core');
  }

  /**
   * Resolve VVJ tokens for a given namespace and view.
   *
   * @param string $namespace
   *   Token namespace (the pattern slug, e.g., `vvja`, `vvjs`). Passed
   *   in by the pattern module's hook_tokens implementation.
   * @param array<string, string> $tokens
   *   Token name → original placeholder map (Drupal token engine).
   * @param array<string, mixed> $data
   *   Token data; expects a `view` key with a ViewExecutable.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata
   *   For propagating cache metadata.
   * @param string $expected_style_plugin_class
   *   FQCN of the pattern's Style plugin class. Tokens only resolve
   *   when the view's style plugin is of this class — so e.g. a vvja
   *   token inside a vvjs view doesn't accidentally fire.
   *
   * @return array<string, string|\Drupal\Component\Render\MarkupInterface>
   *   Map of original placeholder → resolved value. Empty when the
   *   namespace doesn't apply or the view isn't ready.
   */
  public function resolve(
    string $namespace,
    array $tokens,
    array $data,
    BubbleableMetadata $bubbleable_metadata,
    string $expected_style_plugin_class,
  ): array {
    /** @var array<string, string|\Drupal\Component\Render\MarkupInterface> $replacements */
    $replacements = [];

    $view = $data['view'] ?? NULL;
    if (!($view instanceof ViewExecutable)) {
      return $replacements;
    }

    $style = $view->style_plugin;
    if (!($style instanceof StylePluginBase) || !($style instanceof $expected_style_plugin_class)) {
      return $replacements;
    }

    if (empty($view->result)) {
      return $replacements;
    }

    // Bubble the view's cacheability. A ViewExecutable is not itself a
    // CacheableDependencyInterface (passing one is deprecated in 11.2 and
    // unsupported in 12.0); use the display's computed cache metadata.
    $bubbleable_metadata->addCacheableDependency(
      $view->display_handler->getCacheMetadata(),
    );

    $first_row = $view->result[0];
    /** @var array<string, \Drupal\views\Plugin\views\field\FieldPluginBase> $field_handlers */
    $field_handlers = $view->display_handler->getHandlers('field');

    foreach ($tokens as $token => $original) {
      // Recursive Domain Guard: token engine always passes string keys,
      // but defensive — non-string keys can't be valid token names.
      if (!is_string($token)) {
        continue;
      }
      if (!preg_match(ValidationBounds::TOKEN_PATTERN, $token)) {
        $this->logger->warning('Invalid @ns token format: @token', [
          '@ns' => $namespace,
          '@token' => $token,
        ]);
        continue;
      }

      $plain = FALSE;
      $field_id = $token;

      if (str_ends_with($token, ':plain')) {
        $plain = TRUE;
        $field_id = substr($token, 0, -6);
      }

      if (!isset($field_handlers[$field_id])) {
        continue;
      }

      try {
        $handler = $field_handlers[$field_id];
        $value = $handler->advancedRender($first_row);

        $rendered = is_array($value)
          ? (string) $this->renderer->renderInIsolation($value)
          : (string) $value;

        $replacements[$original] = $plain
          ? Html::decodeEntities(strip_tags($rendered))
          : Markup::create(Xss::filterAdmin($rendered));
      }
      catch (\Throwable $e) {
        $this->logger->error('Token replacement failed for [@ns:@token]: @message', [
          '@ns' => $namespace,
          '@token' => $token,
          '@message' => $e->getMessage(),
        ]);
        $replacements[$original] = '';
      }
    }

    return $replacements;
  }

}
