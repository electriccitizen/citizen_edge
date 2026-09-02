<?php

namespace Drupal\citizen_edge;

use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Purges file URLs from the hosting platform's edge cache.
 *
 * Host support:
 * - Acquia: queues "url" invalidations through the purge module, which the
 *   site's configured purgers (Acquia Platform CDN and/or Varnish) process.
 * - Pantheon: calls pantheon_clear_edge_paths(), provided by the platform.
 * - Cloudways: nothing to purge — files are served nginx-direct with no
 *   server-side cache (vendor-confirmed by design); logged for clarity.
 * - Anything else (including local): logs what would have been purged.
 * Cloudflare, when configured, is purged as an additional layer on any host.
 *
 * Network purges (Pantheon, Cloudflare) are DEFERRED to kernel terminate so
 * they never block the editor's save request. Acquia stays inline because
 * its purge is only a fast local queue add, and the purge module's own queue
 * and processors give it retry semantics.
 *
 * Failure handling for the deferred layers: a purge that fails is not just
 * logged. The failed URLs go onto the core queue named by QUEUE_NAME, and
 * the module's cron queue worker retries them (up to MAX_ATTEMPTS). Requests
 * that defer more URLs than the inline limit (bulk deletes, migrations) skip
 * the inline attempt entirely and go straight to the queue, so no single
 * request fires an unbounded number of purge API calls at terminate.
 */
class EdgePurger implements DestructableInterface {

  /**
   * Core queue that holds failed or overflow purges for cron retry.
   */
  const QUEUE_NAME = 'citizen_edge_edge_purge';

  /**
   * Attempts before a purge batch is abandoned (logged as an error).
   */
  const MAX_ATTEMPTS = 5;

  /**
   * Default per-request cap on inline deferred URLs.
   *
   * Overridable with $settings['citizen_edge_inline_purge_limit'].
   */
  const DEFAULT_INLINE_LIMIT = 500;

  /**
   * Deferred network purges, executed at kernel terminate.
   *
   * @var array[]
   */
  protected $deferred = [];

  /**
   * URLs already deferred this request, keyed by layer then URL.
   *
   * @var array[]
   */
  protected $seen = [];

  /**
   * Host detector.
   *
   * @var \Drupal\citizen_edge\HostDetector
   */
  protected $hostDetector;

  /**
   * Module logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * File URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Site settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Queue factory (retry queue for failed deferred purges).
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The HTTP client (Cloudflare purge API requests).
   *
   * @var \GuzzleHttp\ClientInterface|null
   */
  protected $httpClient;

  /**
   * Constructs the purger.
   */
  public function __construct(HostDetector $host_detector, LoggerInterface $logger, FileUrlGeneratorInterface $file_url_generator, EntityTypeManagerInterface $entity_type_manager, Settings $settings, QueueFactory $queue_factory, ?ClientInterface $http_client = NULL) {
    $this->hostDetector = $host_detector;
    $this->logger = $logger;
    $this->fileUrlGenerator = $file_url_generator;
    $this->entityTypeManager = $entity_type_manager;
    $this->settings = $settings;
    $this->queueFactory = $queue_factory;
    $this->httpClient = $http_client;
  }

  /**
   * Purges a file's URL (and any image style derivatives) from the edge.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file whose URLs should be purged.
   * @param string $reason
   *   Short human-readable trigger description, used in log messages.
   */
  public function purgeFile(FileInterface $file, string $reason = 'file change'): void {
    $uri = $file->getFileUri();
    // Only public files are served through the edge cache.
    if (!str_starts_with((string) $uri, 'public://')) {
      return;
    }
    $this->purgeUrls($this->collectUrls($file), $reason);
  }

  /**
   * Purges a set of absolute URLs from the edge via the detected host.
   *
   * URLs are expanded across the configured public base URLs (or forced to
   * https) before purging. Public so tooling (drush ce:purge) can purge
   * arbitrary URLs, not only file entities.
   *
   * @param string[] $urls
   *   Absolute URLs.
   * @param string $reason
   *   Short trigger description for log messages.
   */
  public function purgeUrls(array $urls, string $reason): void {
    $urls = $this->expandUrls($urls);
    if (!$urls) {
      return;
    }
    switch ($this->hostDetector->getHost()) {
      case HostDetector::ACQUIA:
        // Inline: adding to the purge queue is a fast local operation.
        $this->purgeAcquia($urls, $reason);
        break;

      case HostDetector::PANTHEON:
        // Deferred: the platform purge API is a blocking network call.
        // Executed at kernel terminate (destruct), after the editor's save
        // response has been sent.
        $this->defer('pantheon', $urls, $reason);
        break;

      case HostDetector::CLOUDWAYS:
        // Cloudways serves static files nginx-direct by design (vendor
        // confirmed 2026-08-25): they bypass Varnish and Apache entirely, so
        // there is no server-side file cache to purge. Stale-file exposure
        // is browser/CDN TTL only, governed by the panel's Server Settings >
        // Advanced > Nginx > Static Cache Expiry. The chained Cloudflare
        // purge below still applies where configured.
        $this->logger->info('Cloudways host: files are served nginx-direct with no server-side cache; nothing to purge (@reason): @urls', [
          '@reason' => $reason,
          '@urls' => $this->summarizeUrls($urls),
        ]);
        break;

      default:
        $this->logger->info('No edge cache host detected; would purge (@reason): @urls', [
          '@reason' => $reason,
          '@urls' => $this->summarizeUrls($urls),
        ]);
    }
    // Cloudflare is a LAYER, not a host: when a site sits behind it,
    // its edge caches files independently of whatever the hosting platform
    // does, so it is purged in addition to the host purge above. Deferred
    // for the same reason as Pantheon: it is a network call.
    if ($this->cloudflareConfig()) {
      $this->defer('cloudflare', $urls, $reason);
    }
  }

  /**
   * Adds URLs to the deferred set for a layer, skipping ones already queued.
   *
   * A single request can purge the same URL several times (a media save
   * that re-saves its file, a bulk operation touching shared files). Each
   * URL is purged once per layer per request.
   */
  protected function defer(string $layer, array $urls, string $reason): void {
    $new = [];
    foreach ($urls as $url) {
      if (!isset($this->seen[$layer][$url])) {
        $this->seen[$layer][$url] = TRUE;
        $new[] = $url;
      }
    }
    if ($new) {
      $this->deferred[] = [$layer, $new, $reason];
    }
  }

  /**
   * Returns the purges deferred so far in this request.
   *
   * @return array[]
   *   A list of [layer, urls, reason] triples.
   */
  public function getPending(): array {
    return $this->deferred;
  }

  /**
   * Executes deferred network purges. Runs at kernel terminate.
   *
   * By the time the container destructs, Symfony's Response::send() has
   * already flushed the response and called fastcgi_finish_request(), so the
   * editor's browser is not waiting on anything done here.
   */
  public function destruct(): void {
    $this->flush();
  }

  /**
   * Executes any deferred purges immediately.
   *
   * Public for drush tooling (cmf:purge, cmf:edge-test) that must complete
   * and verify purges mid-process instead of waiting for terminate.
   *
   * Batches whose combined URL count exceeds the inline limit are handed to
   * the retry queue unexecuted and drained by cron; anything that fails
   * inline is queued for retry the same way.
   */
  public function flush(): void {
    $deferred = $this->deferred;
    $this->deferred = [];
    $this->seen = [];
    if (!$deferred) {
      return;
    }
    $total = array_sum(array_map(fn(array $item) => count($item[1]), $deferred));
    $limit = (int) ($this->settings->get('citizen_edge_inline_purge_limit') ?? self::DEFAULT_INLINE_LIMIT);
    if ($limit > 0 && $total > $limit) {
      foreach ($deferred as [$layer, $urls, $reason]) {
        $this->enqueue($layer, $urls, $reason, 0);
      }
      $this->logger->notice('@total URL(s) deferred in one request exceed the inline purge limit of @limit; queued for cron (@queue) instead of purging inline.', [
        '@total' => $total,
        '@limit' => $limit,
        '@queue' => self::QUEUE_NAME,
      ]);
      return;
    }
    foreach ($deferred as [$layer, $urls, $reason]) {
      $this->execute($layer, $urls, $reason, 0);
    }
  }

  /**
   * Retries a queued purge item. Called by the cron queue worker.
   *
   * @param array $item
   *   A queue item as produced by enqueue().
   */
  public function retry(array $item): void {
    $this->execute((string) $item['layer'], (array) $item['urls'], (string) $item['reason'], (int) $item['attempts']);
  }

  /**
   * Runs one layer's purge and queues whatever failed for another attempt.
   */
  protected function execute(string $layer, array $urls, string $reason, int $attempts): void {
    $failed = match ($layer) {
      'pantheon' => $this->purgePantheon($urls, $reason),
      'cloudflare' => $this->purgeCloudflare($urls, $reason),
      default => [],
    };
    if (!$failed) {
      return;
    }
    $attempts++;
    if ($attempts >= self::MAX_ATTEMPTS) {
      $this->logger->error('Giving up on @count @layer purge(s) after @attempts attempts (@reason). Purge manually with drush ce:purge: @urls', [
        '@count' => count($failed),
        '@layer' => $layer,
        '@attempts' => $attempts,
        '@reason' => $reason,
        '@urls' => $this->summarizeUrls($failed),
      ]);
      return;
    }
    $this->enqueue($layer, $failed, $reason, $attempts);
    $this->logger->warning('Queued @count failed @layer purge(s) for retry on cron (attempt @attempts of @max, @reason): @urls', [
      '@count' => count($failed),
      '@layer' => $layer,
      '@attempts' => $attempts,
      '@max' => self::MAX_ATTEMPTS,
      '@reason' => $reason,
      '@urls' => $this->summarizeUrls($failed),
    ]);
  }

  /**
   * Puts a purge batch on the retry queue.
   */
  protected function enqueue(string $layer, array $urls, string $reason, int $attempts): void {
    $this->queueFactory->get(self::QUEUE_NAME)->createItem([
      'layer' => $layer,
      'urls' => array_values($urls),
      'reason' => $reason,
      'attempts' => $attempts,
    ]);
  }

  /**
   * Builds the absolute URLs for a file, including image style derivatives.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   *
   * @return string[]
   *   Absolute URLs.
   */
  public function collectUrls(FileInterface $file): array {
    $uri = $file->getFileUri();
    $urls = [$this->fileUrlGenerator->generateAbsoluteString($uri)];
    // Image derivatives live at their own edge-cached URLs. Purge every
    // supported style's derivative URL WITHOUT checking the derivative
    // exists on disk: on network filesystems (Pantheon Valhalla, Acquia
    // Gluster) each file_exists() is a slow remote stat, and at 100+
    // styles that adds many seconds INSIDE the editor's save request
    // (hook_file_update runs synchronously; only the HTTP purging is
    // deferred to destruct). Purging a URL the edge never cached is a
    // no-op there, so blind purging trades a bounded amount of queue
    // noise for seconds of editor-facing latency.
    if (str_starts_with((string) $file->getMimeType(), 'image/')) {
      try {
        $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
        foreach ($styles as $style) {
          /** @var \Drupal\image\ImageStyleInterface $style */
          if ($style->supportsUri($uri)) {
            $urls[] = $style->buildUrl($uri);
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not build image style URLs for @uri: @message', [
          '@uri' => $uri,
          '@message' => $e->getMessage(),
        ]);
      }
    }
    return array_unique($urls);
  }

  /**
   * Queues URL invalidations through the purge module on Acquia.
   *
   * @param string[] $urls
   *   Absolute URLs to purge.
   * @param string $reason
   *   Trigger description for logging.
   */
  protected function purgeAcquia(array $urls, string $reason): void {
    // The purge module is an optional dependency (absent on Pantheon sites),
    // so its services cannot be constructor-injected here.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $container = \Drupal::getContainer();
    if (!$container->has('purge.invalidation.factory') || !$container->has('purge.queue') || !$container->has('purge.queuers')) {
      $this->logger->warning('Acquia host detected but the purge module is not available; NOT purged (@reason): @urls', [
        '@reason' => $reason,
        '@urls' => $this->summarizeUrls($urls),
      ]);
      return;
    }
    try {
      $queuer = $container->get('purge.queuers')->get('citizen_edge');
      if (!$queuer) {
        $this->logger->warning('The citizen_edge purge queuer is not enabled; NOT purged: @urls', [
          '@urls' => $this->summarizeUrls($urls),
        ]);
        return;
      }
      $factory = $container->get('purge.invalidation.factory');
      $invalidations = [];
      foreach ($urls as $url) {
        $invalidations[] = $factory->get('url', $url);
      }
      $container->get('purge.queue')->add($queuer, $invalidations);
      $this->logger->notice('Queued @count edge purge(s) (@reason): @urls', [
        '@count' => count($invalidations),
        '@reason' => $reason,
        '@urls' => $this->summarizeUrls($urls),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to queue edge purges (@reason): @class @message. URLs not purged: @urls', [
        '@reason' => $reason,
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
        '@urls' => $this->summarizeUrls($urls),
      ]);
    }
  }

  /**
   * Clears edge paths via Pantheon's platform function.
   *
   * Pantheon's Global CDN keys derivative URLs on their itok query string
   * and pantheon_clear_edge_paths() honors it (verified on a Pantheon live
   * environment: a purged derivative reset to age 0 while an untouched
   * control URL kept its age), so paths are sent with their query strings.
   *
   * @param string[] $urls
   *   Absolute URLs to purge.
   * @param string $reason
   *   Trigger description for logging.
   *
   * @return string[]
   *   URLs whose purge failed and should be retried.
   */
  protected function purgePantheon(array $urls, string $reason): array {
    if (!function_exists('pantheon_clear_edge_paths')) {
      // Not transient: retrying cannot help until the platform provides it.
      $this->logger->warning('Pantheon host detected but pantheon_clear_edge_paths() is unavailable; NOT purged (@reason): @urls. The platform API may have changed.', [
        '@reason' => $reason,
        '@urls' => $this->summarizeUrls($urls),
      ]);
      return [];
    }
    $failed = [];
    $cleared = 0;
    // Pantheon caps how many paths one call accepts; chunk defensively.
    foreach (array_chunk($urls, 25) as $chunk) {
      $paths = [];
      foreach ($chunk as $url) {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if (!empty($parts['query'])) {
          $path .= '?' . $parts['query'];
        }
        if ($path) {
          $paths[] = $path;
        }
      }
      if (!$paths) {
        continue;
      }
      try {
        pantheon_clear_edge_paths($paths);
        $cleared += count($paths);
      }
      catch (\Exception $e) {
        $this->logger->error('Pantheon edge clear failed (@reason): @message. Paths: @paths', [
          '@reason' => $reason,
          '@message' => $e->getMessage(),
          '@paths' => $this->summarizeUrls($paths),
        ]);
        $failed = array_merge($failed, $chunk);
      }
    }
    if ($cleared) {
      $this->logger->notice('Cleared @count Pantheon edge path(s) (@reason): @paths', [
        '@count' => $cleared,
        '@reason' => $reason,
        '@paths' => $this->summarizeUrls($urls),
      ]);
    }
    return $failed;
  }

  /**
   * Purges URLs from Cloudflare's edge by API, when configured.
   *
   * Cloudflare respects origin cache-control and caches files at its edge
   * (confirmed in production: cf-cache-status HIT with the origin's
   * year-long max-age), so sites behind EC-controlled Cloudflare need this
   * layer purged too. Configure per site in settings.php, keeping the token
   * OUT of committed code (read it from the environment):
   *
   * @code
   * $settings['citizen_edge_cloudflare'] = [
   *   'zone_id' => '<zone id>',
   *   'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
   * ];
   * @endcode
   *
   * The token needs the Zone > Cache Purge permission only. Unconfigured
   * sites skip this silently; a configured site that fails to purge logs an
   * error and queues the URLs for retry. Do NOT configure it for
   * client-owned Cloudflare accounts: that edge belongs to the client.
   *
   * @param string[] $urls
   *   Absolute URLs to purge.
   * @param string $reason
   *   Trigger description for logging.
   *
   * @return string[]
   *   URLs whose purge failed and should be retried.
   */
  protected function purgeCloudflare(array $urls, string $reason): array {
    $config = $this->cloudflareConfig();
    if (!$config) {
      return [];
    }
    if (!$this->httpClient) {
      // Configuration problem, not transient.
      $this->logger->error('Cloudflare purge configured but no HTTP client available; NOT purged (@reason): @urls', [
        '@reason' => $reason,
        '@urls' => $this->summarizeUrls($urls),
      ]);
      return [];
    }
    $failed = [];
    // Cloudflare accepts at most 30 URLs per purge request.
    foreach (array_chunk($urls, 30) as $chunk) {
      try {
        $response = $this->httpClient->request('POST', 'https://api.cloudflare.com/client/v4/zones/' . $config['zone_id'] . '/purge_cache', [
          'headers' => [
            'Authorization' => 'Bearer ' . $config['api_token'],
            'Content-Type' => 'application/json',
          ],
          'json' => ['files' => array_values($chunk)],
          'http_errors' => FALSE,
          'timeout' => 10,
        ]);
        $body = json_decode((string) $response->getBody(), TRUE);
        if (!empty($body['success'])) {
          $this->logger->notice('Purged @count URL(s) from Cloudflare (@reason): @urls', [
            '@count' => count($chunk),
            '@reason' => $reason,
            '@urls' => $this->summarizeUrls($chunk),
          ]);
        }
        else {
          $this->logger->error('Cloudflare purge FAILED (@reason, HTTP @status): @errors. URLs: @urls', [
            '@reason' => $reason,
            '@status' => $response->getStatusCode(),
            '@errors' => json_encode($body['errors'] ?? 'no error detail'),
            '@urls' => $this->summarizeUrls($chunk),
          ]);
          $failed = array_merge($failed, $chunk);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Cloudflare purge request failed (@reason): @message. URLs: @urls', [
          '@reason' => $reason,
          '@message' => $e->getMessage(),
          '@urls' => $this->summarizeUrls($chunk),
        ]);
        $failed = array_merge($failed, $chunk);
      }
    }
    return $failed;
  }

  /**
   * Returns the Cloudflare settings when fully configured, else NULL.
   */
  protected function cloudflareConfig(): ?array {
    $config = $this->settings->get('citizen_edge_cloudflare');
    if ($config && !empty($config['zone_id']) && !empty($config['api_token'])) {
      return $config;
    }
    return NULL;
  }

  /**
   * Expands each URL across the site's real public base URLs.
   *
   * Varnish caches per scheme and host, so a purge only clears the exact
   * variant it names. URLs generated in CLI/cron context come out http:// —
   * purging that clears a redirect object while the real https:// file object
   * survives (confirmed on Acquia dev) — and multi-domain sites cache a copy
   * per domain. Sites list their public base URLs in settings.php:
   *
   * @code
   * $settings['citizen_edge_base_urls'] = [
   *   'https://www.example.org',
   *   'https://exampleprod.prod.acquia-sites.com',
   * ];
   * @endcode
   *
   * Without the setting, the generated URL's host is kept and the scheme is
   * forced to https.
   *
   * @param string[] $urls
   *   Absolute URLs as generated.
   *
   * @return string[]
   *   URLs expanded across base URLs.
   */
  protected function expandUrls(array $urls): array {
    $bases = $this->settings->get('citizen_edge_base_urls') ?: [];
    $out = [];
    foreach ($urls as $url) {
      $parts = parse_url($url);
      $path_query = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
      if ($bases) {
        foreach ($bases as $base) {
          $out[] = rtrim($base, '/') . $path_query;
        }
      }
      else {
        $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $out[] = 'https://' . $host . $path_query;
      }
    }
    return array_unique($out);
  }

  /**
   * Renders a URL list for logging without flooding the log.
   *
   * @param string[] $urls
   *   URLs or paths.
   *
   * @return string
   *   The first few entries plus a count of the rest.
   */
  protected function summarizeUrls(array $urls): string {
    $shown = array_slice($urls, 0, 5);
    $rest = count($urls) - count($shown);
    $summary = implode(', ', $shown);
    if ($rest > 0) {
      $summary .= " (+ $rest more)";
    }
    return $summary;
  }

}
