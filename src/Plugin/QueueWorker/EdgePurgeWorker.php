<?php

namespace Drupal\citizen_edge\Plugin\QueueWorker;

use Drupal\citizen_edge\EdgePurger;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries edge purges that failed inline or overflowed the inline limit.
 *
 * Items are created by EdgePurger and carry their own attempt counter, so a
 * batch that keeps failing is re-queued with an incremented count and
 * abandoned (with an error log) after EdgePurger::MAX_ATTEMPTS. Drain by
 * cron or on demand with `drush queue:run citizen_edge_edge_purge`.
 */
#[QueueWorker(
  id: EdgePurger::QUEUE_NAME,
  title: new TranslatableMarkup('Citizen Edge edge purge retries'),
  cron: ['time' => 30],
)]
class EdgePurgeWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The edge purger.
   *
   * @var \Drupal\citizen_edge\EdgePurger
   */
  protected $edgePurger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->edgePurger = $container->get('citizen_edge.edge_purger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || empty($data['layer']) || empty($data['urls'])) {
      // Malformed item: drop it rather than loop on it forever.
      return;
    }
    $this->edgePurger->retry($data + ['reason' => 'queued retry', 'attempts' => 0]);
  }

}
