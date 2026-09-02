<?php

namespace Drupal\citizen_edge;

use Drupal\Core\Site\Settings;

/**
 * Detects which hosting platform the site is running on.
 */
class HostDetector {

  const ACQUIA = 'acquia';
  const PANTHEON = 'pantheon';
  const CLOUDWAYS = 'cloudways';
  const NONE = 'none';

  /**
   * Site settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructs the host detector.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   Site settings.
   */
  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * Returns the detected host platform.
   *
   * A `citizen_edge_host` entry in settings.php overrides detection,
   * which allows forcing a backend for testing.
   *
   * @return string
   *   One of the ACQUIA, PANTHEON, or NONE constants.
   */
  public function getHost(): string {
    $override = $this->settings->get('citizen_edge_host');
    if (in_array($override, [self::ACQUIA, self::PANTHEON, self::CLOUDWAYS, self::NONE], TRUE)) {
      return $override;
    }
    if (getenv('AH_SITE_ENVIRONMENT') !== FALSE) {
      return self::ACQUIA;
    }
    if (getenv('PANTHEON_ENVIRONMENT') !== FALSE) {
      return self::PANTHEON;
    }
    // Cloudways sets no marker env var, but its filesystem layout is
    // unmistakable (confirmed from EC's Cloudways deploy targets).
    if (str_starts_with(DRUPAL_ROOT, '/home/master/applications/')) {
      return self::CLOUDWAYS;
    }
    return self::NONE;
  }

}
