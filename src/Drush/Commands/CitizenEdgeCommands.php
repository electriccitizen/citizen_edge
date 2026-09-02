<?php

namespace Drupal\citizen_edge\Drush\Commands;

use Drupal\citizen_edge\EdgePurger;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\Entity\File;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for edge purge operations and rollout verification.
 */
final class CitizenEdgeCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(
    protected EdgePurger $edgePurger,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('citizen_edge.edge_purger'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Applies (or previews) the package's site setup.
   *
   * The same routine hook_install() runs, made re-runnable so a reviewer can
   * see exactly what a site will get before it gets it, and so a site can be
   * re-aligned after a media type or role is added later.
   */
  #[CLI\Command(name: 'citizen_edge:setup', aliases: ['ce:setup', 'cmf:setup'])]
  #[CLI\Option(name: 'dry-run', description: 'Report the decisions without saving anything.')]
  #[CLI\Option(name: 'standard', description: 'Also apply the EC-standard enforcement (requires the citizen_edge_standard submodule to be enabled).')]
  #[CLI\Usage(name: 'drush ce:setup --dry-run', description: 'Preview what the adaptive baseline would change on this site.')]
  #[CLI\Usage(name: 'drush ce:setup --standard', description: 'Re-apply the baseline and the EC standard, e.g. after adding a media type.')]
  public function setup(array $options = ['dry-run' => FALSE, 'standard' => FALSE]): int {
    $dry_run = (bool) $options['dry-run'];
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $module_handler = \Drupal::moduleHandler();
    $module_handler->loadInclude('citizen_edge', 'install');
    $decisions = citizen_edge_setup($dry_run);
    if ($options['standard']) {
      if (!$module_handler->moduleExists('citizen_edge_standard')) {
        $this->logger()->error(dt('--standard requires the citizen_edge_standard submodule to be enabled.'));
        return self::EXIT_FAILURE;
      }
      $module_handler->loadInclude('citizen_edge_standard', 'install');
      $decisions = array_merge($decisions, citizen_edge_standard_setup($dry_run));
    }
    if (!$dry_run) {
      _citizen_edge_log_decisions($decisions);
    }
    $rows = array_map(fn(array $d) => [strtoupper($d[0]), $d[1]], $decisions);
    $this->io()->table(['Level', $dry_run ? 'Decision (dry run, nothing saved)' : 'Decision'], $rows);
    if ($dry_run) {
      $this->logger()->notice(dt('Dry run: nothing was saved.'));
    }
    else {
      $this->logger()->success(dt('Setup applied. Export config (drush cex) and review the diff.'));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Purges a file's URLs from the platform edge cache.
   */
  #[CLI\Command(name: 'citizen_edge:purge', aliases: ['ce:purge', 'cmf:purge'])]
  #[CLI\Argument(name: 'target', description: 'A file ID, a file URI (public://…), a path (/sites/default/files/…), or a full URL.')]
  #[CLI\Usage(name: 'drush ce:purge 2313', description: 'Purge file entity 2313 (includes image style derivatives).')]
  #[CLI\Usage(name: 'drush ce:purge public://docs/report.pdf', description: 'Purge by file URI.')]
  #[CLI\Usage(name: 'drush ce:purge /sites/default/files/docs/report.pdf', description: 'Purge an arbitrary files path (no entity needed).')]
  public function purge(string $target): void {
    // Numeric: a file entity, which gets derivative handling too.
    if (ctype_digit($target)) {
      $file = File::load((int) $target);
      if (!$file) {
        throw new \InvalidArgumentException("No file entity with fid $target.");
      }
      $this->edgePurger->purgeFile($file, 'drush ce:purge fid ' . $target);
      $this->edgePurger->flush();
      $this->logger()->success(dt('Purge dispatched for fid @fid (@uri). See the citizen_edge log channel for the outcome.', [
        '@fid' => $target,
        '@uri' => $file->getFileUri(),
      ]));
      return;
    }
    // Stream URI: purge via the entity when one exists (derivatives), else
    // purge the computed URL directly.
    if (str_contains($target, '://') && !str_starts_with($target, 'http')) {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $target]);
      if ($files) {
        $this->edgePurger->purgeFile(reset($files), 'drush ce:purge uri');
        $this->edgePurger->flush();
      }
      else {
        $this->edgePurger->purgeUrls([$this->fileUrlGenerator->generateAbsoluteString($target)], 'drush ce:purge uri (no entity)');
        $this->edgePurger->flush();
      }
      $this->logger()->success(dt('Purge dispatched for @uri.', ['@uri' => $target]));
      return;
    }
    // Full URL or absolute path.
    if (str_starts_with($target, 'http')) {
      $url = $target;
    }
    elseif (str_starts_with($target, '/')) {
      $base = rtrim(dirname($this->fileUrlGenerator->generateAbsoluteString('public://x')), '/');
      $host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
      $url = $host . $target;
    }
    else {
      throw new \InvalidArgumentException('Target must be a fid, a stream URI, a /path, or a URL.');
    }
    $this->edgePurger->purgeUrls([$url], 'drush ce:purge url');
    $this->edgePurger->flush();
    $this->logger()->success(dt('Purge dispatched for @url.', ['@url' => $url]));
  }

  /**
   * Runs the full edge-cache eviction verification protocol.
   *
   * Creates a throwaway public file, warms the edge, overwrites the file on
   * disk to prove the edge serves stale content, then triggers the module's
   * purge via a file entity save and asserts fresh content, then deletes and
   * asserts 404. Self-cleaning. A curl from a real client machine remains
   * the gold-standard final check; this command's requests originate from
   * the server but do traverse the public edge.
   */
  #[CLI\Command(name: 'citizen_edge:edge-test', aliases: ['ce:edge-test', 'cmf:edge-test'])]
  #[CLI\Option(name: 'base-url', description: 'Public base URL to test against (e.g. https://www.example.com). Defaults to the site URL drush resolves. Run once per host on multi-domain sites that do not redirect to a primary.')]
  #[CLI\Option(name: 'auth', description: 'HTTP Basic auth as user:pass for gated environments (e.g. Cloudways staging).')]
  public function edgeTest(array $options = ['base-url' => NULL, 'auth' => NULL]): int {
    $marker = substr(bin2hex(random_bytes(4)), 0, 8);
    $uri = "public://citizen-edge-test-$marker.txt";
    $filename = "citizen-edge-test-$marker.txt";
    $results = [];
    $inconclusive = FALSE;
    $file = NULL;

    $generated = $this->fileUrlGenerator->generateAbsoluteString($uri);
    $path = parse_url($generated, PHP_URL_PATH);
    if ($options['base-url']) {
      $url = rtrim($options['base-url'], '/') . $path;
    }
    else {
      $url = $generated;
      if (str_contains($url, '://default')) {
        throw new \RuntimeException('Cannot resolve the public URL (host is "default"). Pass --base-url or set --uri.');
      }
      // CLI-generated URLs come out http://, which adds a protocol-redirect
      // hop to every fetch and lets the gone-after-delete check false-pass
      // on the scheme upgrade. Test what production serves: https.
      if (str_starts_with($url, 'http://') && !str_contains($url, '.ddev.site')) {
        $url = 'https://' . substr($url, 7);
      }
    }
    $auth = NULL;
    if ($options['auth']) {
      if (!str_contains($options['auth'], ':')) {
        throw new \InvalidArgumentException('--auth expects user:pass.');
      }
      $auth = explode(':', $options['auth'], 2);
    }
    $client = new Client([
      'http_errors' => FALSE,
      'timeout' => 15,
      'allow_redirects' => ['max' => 5, 'track_redirects' => TRUE],
      'auth' => $auth,
    ]);
    $fetch = function () use ($client, $url): array {
      for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
          $response = $client->get($url);
          $redirects = $response->getHeaderLine('X-Guzzle-Redirect-History');
          if ($redirects && $this->output()->isVerbose()) {
            $this->io()->text("  (redirect trail: $redirects)");
          }
          return [
            'status' => $response->getStatusCode(),
            'body' => trim((string) $response->getBody()),
            'cache_control' => $response->getHeaderLine('Cache-Control'),
            'age' => $response->getHeaderLine('Age'),
            'redirected_to' => $redirects ? trim((string) array_reverse(explode(',', $redirects))[0]) : '',
          ];
        }
        catch (\Exception $e) {
          $this->io()->warning("Fetch attempt $attempt failed: " . $e->getMessage());
          if ($attempt === 2) {
            return [
              'status' => 0,
              'body' => '',
              'cache_control' => '',
              'age' => '',
              'redirected_to' => '',
              'error' => $e->getMessage(),
            ];
          }
          sleep(2);
        }
      }
      return ['status' => 0, 'body' => '', 'cache_control' => '', 'age' => '', 'redirected_to' => ''];
    };

    try {
      // 1. Create.
      file_put_contents($uri, "EDGE-TEST-V1-$marker");
      $file = File::create(['uri' => $uri, 'filename' => $filename, 'status' => 1, 'uid' => 1]);
      $file->save();
      $this->io()->text("Created $uri (fid " . $file->id() . "), testing $url");

      // 2. Warm and inspect.
      $r = $fetch();
      if ($r['redirected_to']) {
        $this->io()->warning('Requests redirect to ' . $r['redirected_to'] . ' — that host is what is being tested. On genuinely multi-host sites run once per host with --base-url.');
      }
      if ($r['status'] !== 200 || !str_contains($r['body'], "EDGE-TEST-V1-$marker")) {
        throw new \RuntimeException("Initial fetch failed (status {$r['status']}). Cannot continue.");
      }
      $results['serve v1'] = 'PASS';
      if (!preg_match('/max-age=(\d{4,})/', $r['cache_control'])) {
        $inconclusive = TRUE;
        $this->io()->warning("Cache-Control is '{$r['cache_control']}' — this environment does not long-cache files (typical off live). Purge behavior cannot be observed here.");
      }
      $fetch();

      // 3. Disk overwrite only: the edge should keep serving stale v1.
      file_put_contents($uri, "EDGE-TEST-V2-$marker");
      sleep(1);
      $r = $fetch();
      if (str_contains($r['body'], "EDGE-TEST-V1-$marker")) {
        $results['stale after disk overwrite (the bug)'] = 'PASS';
      }
      else {
        $inconclusive = TRUE;
        $results['stale after disk overwrite (the bug)'] = 'INCONCLUSIVE (edge did not cache; nothing to purge)';
      }

      // 4. Entity save fires the module purge; drain the queue on Acquia.
      // Re-purge between retries: on Pantheon, the disk write can lag the
      // serving appserver (NFS propagation), so a single purge can evict
      // correctly only for the edge to refetch STALE origin content and
      // re-cache it with a fresh long TTL. A later purge wins once the
      // origin settles. When only a re-purged try passes, that race is
      // real on this environment — flag it, because production replaces
      // are then exposed to the same window.
      $file->save();
      $this->edgePurger->flush();
      $this->drainPurgeQueue();
      $fresh = FALSE;
      $repurged = FALSE;
      for ($try = 1; $try <= 5; $try++) {
        sleep(3);
        $r = $fetch();
        if (str_contains($r['body'], "EDGE-TEST-V2-$marker")) {
          $fresh = TRUE;
          break;
        }
        if ($try >= 2) {
          $this->edgePurger->purgeUrls([$url], 'edge-test re-purge (origin propagation race)');
          $this->edgePurger->flush();
          $this->drainPurgeQueue();
          $repurged = TRUE;
        }
      }
      if ($fresh && $repurged) {
        $results['fresh after purge (replace)'] = 'PASS (after re-purge — origin propagation race detected; see output)';
        $this->io()->warning('The first purge evicted but the edge re-cached stale origin content (origin/NFS propagation lag). A re-purge succeeded once the origin settled. Production file replaces on this environment are exposed to the same race; consider a delayed re-purge.');
      }
      else {
        $results['fresh after purge (replace)'] = $fresh ? 'PASS' : 'FAIL (still serving: ' . substr($r['body'], 0, 40) . ' after 5 tries with re-purges)';
      }

      // 5. Delete fires the purge too. Expect the URL to stop serving the
      // file: a 404/410, or a redirect away (Drupal-handled requests on
      // non-canonical hosts 301 to the primary domain, which equally proves
      // the file is no longer served). No redirect-following here.
      $file->delete();
      $file = NULL;
      $this->edgePurger->flush();
      $this->drainPurgeQueue();
      sleep(2);
      $bare = new Client(['http_errors' => FALSE, 'timeout' => 15, 'allow_redirects' => FALSE, 'auth' => $auth]);
      $check_url = $url;
      $same_host = fn(string $a, string $b): bool =>
        preg_replace('/^www\./', '', (string) parse_url($a, PHP_URL_HOST)) === preg_replace('/^www\./', '', (string) parse_url($b, PHP_URL_HOST));
      // Follow at most 3 redirects by hand, and only scheme/www variants of
      // the SAME host — those do not prove the file is gone. A redirect to a
      // different host or path does (canonical-domain 404 handling etc.).
      for ($hop = 0; $hop <= 3; $hop++) {
        try {
          $response = $bare->get($check_url);
        }
        catch (\Exception $e) {
          $results['gone after delete'] = 'FAIL (transport error at ' . $check_url . ': ' . $e->getMessage() . ')';
          break;
        }
        $status = $response->getStatusCode();
        if (in_array($status, [404, 410], TRUE)) {
          $results['gone after delete'] = 'PASS (404)';
          break;
        }
        if ($status >= 300 && $status < 400) {
          $location = $response->getHeaderLine('Location');
          $same_path = parse_url($location, PHP_URL_PATH) === parse_url($check_url, PHP_URL_PATH);
          if ($same_path && $same_host($location, $check_url)) {
            $check_url = $location;
            continue;
          }
          $results['gone after delete'] = "PASS (redirects away to $location)";
          break;
        }
        $results['gone after delete'] = "FAIL (status $status at $check_url, still serving)";
        break;
      }
      $results['gone after delete'] ??= 'FAIL (redirect loop without resolution)';
    }
    finally {
      if ($file) {
        $file->delete();
      }
    }

    $this->io()->table(['Check', 'Result'], array_map(NULL, array_keys($results), $results));
    $failed = (bool) array_filter($results, fn($v) => str_starts_with($v, 'FAIL'));
    if ($failed) {
      $this->logger()->error(dt('Edge test FAILED. See the citizen_edge log channel.'));
      return self::EXIT_FAILURE;
    }
    if ($inconclusive) {
      $this->logger()->warning(dt('Edge test inconclusive: this environment does not edge-cache files, so purging could not be observed. Run against a live-shaped environment.'));
      return self::EXIT_SUCCESS;
    }
    $this->logger()->success(dt('Edge test PASSED: stale-serve reproduced, purge delivered fresh content, delete returned 404. Confirm once from a real client machine for the gold-standard check.'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Drains the purge queue immediately on Acquia.
   *
   * The late-runtime processor only fires at request termination, which
   * never arrives mid-command, so the queued url invalidations are claimed
   * and executed here. No-op where the purge stack is absent (Pantheon
   * purges synchronously).
   */
  protected function drainPurgeQueue(): void {
    // Optional-module services; cannot be constructor-injected.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $container = \Drupal::getContainer();
    if (!$container->has('purge.queue') || !$container->has('purge.purgers') || !$container->has('purge.processors')) {
      return;
    }
    $queue = $container->get('purge.queue');
    $purgers = $container->get('purge.purgers');
    $processor = NULL;
    foreach ($container->get('purge.processors') as $candidate) {
      $processor = $candidate;
      break;
    }
    if (!$processor) {
      $this->io()->warning('Purge queue present but no processor is enabled; queued purges were not executed.');
      return;
    }
    for ($i = 0; $i < 10; $i++) {
      $claims = $queue->claim();
      if (!$claims) {
        break;
      }
      try {
        $purgers->invalidate($processor, $claims);
      }
      catch (\Exception $e) {
        $this->io()->warning('Queue processing error: ' . $e->getMessage());
      }
      $queue->handleResults($claims);
    }
  }

}
