<?php

namespace Drupal\citizen_edge\Plugin\Purge\Queuer;

use Drupal\purge\Plugin\Purge\Queuer\QueuerBase;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;

/**
 * Queues file URL invalidations when media files are replaced or deleted.
 *
 * @PurgeQueuer(
 *   id = "citizen_edge",
 *   label = @Translation("Citizen Edge"),
 *   description = @Translation("Queues edge cache purges for file URLs when files are replaced or deleted."),
 *   enable_by_default = true,
 *   configform = "",
 * )
 */
class EdgeQueuer extends QueuerBase implements QueuerInterface {

}
