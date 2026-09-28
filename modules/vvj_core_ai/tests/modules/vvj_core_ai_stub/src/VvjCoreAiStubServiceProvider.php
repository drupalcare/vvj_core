<?php

declare(strict_types=1);

namespace Drupal\vvj_core_ai_stub;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the views drafter the stub provider manager instead of drupal/ai's.
 */
final class VvjCoreAiStubServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('vvj_core_ai.view_drafter')) {
      $container->getDefinition('vvj_core_ai.view_drafter')
        ->replaceArgument(1, new Reference('vvj_core_ai_stub.provider_manager'));
    }
  }

}
