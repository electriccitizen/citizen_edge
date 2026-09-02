<?php

namespace Drupal\Tests\citizen_edge\Kernel;

use Drupal\citizen_edge\EdgePurger;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests edge purge collection, deferral, dedupe, limits, and retry.
 *
 * @group citizen_edge
 */
#[RunTestsInSeparateProcesses]
class EdgePurgerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'citizen_edge',
  ];

  /**
   * Mocked Cloudflare responses.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $mock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    foreach (['thumbnail', 'medium', 'large'] as $id) {
      ImageStyle::create(['name' => $id, 'label' => $id])->save();
    }
    $this->mock = new MockHandler();
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($this->mock)]));
  }

  /**
   * Returns the purger, after any settings changes.
   */
  protected function purger(): EdgePurger {
    return $this->container->get('citizen_edge.edge_purger');
  }

  /**
   * Creates a permanent public file entity.
   */
  protected function createFile(string $uri = 'public://doc.pdf', string $mime = 'application/pdf', bool $permanent = TRUE): File {
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => $mime,
      'status' => $permanent ? 1 : 0,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Non-public files are never purged.
   */
  public function testPrivateFilesAreIgnored(): void {
    $this->setSetting('citizen_edge_host', 'pantheon');
    $purger = $this->purger();
    $purger->purgeFile($this->createFile('private://secret.pdf'), 'test');
    $this->assertSame([], $purger->getPending());
  }

  /**
   * Images collect one URL per supported style, without touching disk.
   */
  public function testImageDerivativeUrlsAreCollectedBlindly(): void {
    $file = $this->createFile('public://pic.png', 'image/png');
    $urls = $this->purger()->collectUrls($file);
    $this->assertCount(4, $urls, 'Original plus one URL per image style.');
    $styled = array_filter($urls, fn(string $url) => str_contains($url, '/styles/') && str_contains($url, 'itok='));
    $this->assertCount(3, $styled);
    $this->assertFileDoesNotExist($file->getFileUri(), 'The file never existed on disk; URLs were built without a stat.');
  }

  /**
   * Documents collect only their own URL.
   */
  public function testDocumentCollectsSingleUrl(): void {
    $this->assertCount(1, $this->purger()->collectUrls($this->createFile()));
  }

  /**
   * The same URL is deferred once per request, however often it is purged.
   */
  public function testDeferredPurgesAreDeduplicated(): void {
    $this->setSetting('citizen_edge_host', 'pantheon');
    $purger = $this->purger();
    $file = $this->createFile();
    $purger->purgeFile($file, 'first');
    $purger->purgeFile($file, 'second');
    $pending = $purger->getPending();
    $this->assertCount(1, $pending);
    $this->assertSame('pantheon', $pending[0][0]);
    $this->assertCount(1, $pending[0][1]);
  }

  /**
   * A fresh upload's temporary-to-permanent save does not purge.
   */
  public function testFirstSaveOfNewUploadIsNotPurged(): void {
    $this->setSetting('citizen_edge_host', 'pantheon');
    $purger = $this->purger();
    $file = $this->createFile('public://new.pdf', 'application/pdf', FALSE);
    $this->assertSame([], $purger->getPending(), 'Creating a temporary file purges nothing.');

    $file->setPermanent();
    $file->save();
    $this->assertSame([], $purger->getPending(), 'Promoting the upload to permanent purges nothing: nothing stale exists at that URL.');

    $file->save();
    $this->assertCount(1, $purger->getPending(), 'Re-saving an already-permanent file (a replace) purges.');
  }

  /**
   * Deleting a file purges it.
   */
  public function testDeleteIsPurged(): void {
    $this->setSetting('citizen_edge_host', 'pantheon');
    $purger = $this->purger();
    $file = $this->createFile();
    $file->delete();
    $this->assertCount(1, $purger->getPending());
  }

  /**
   * Requests over the inline limit go straight to the retry queue.
   */
  public function testInlineLimitDivertsToQueue(): void {
    $this->setSetting('citizen_edge_host', 'pantheon');
    $this->setSetting('citizen_edge_inline_purge_limit', 2);
    $purger = $this->purger();
    $purger->purgeFile($this->createFile('public://pic.png', 'image/png'), 'bulk');
    $purger->flush();
    $queue = $this->container->get('queue')->get(EdgePurger::QUEUE_NAME);
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame('pantheon', $item->data['layer']);
    $this->assertCount(4, $item->data['urls']);
    $this->assertSame(0, $item->data['attempts']);
    $this->assertSame([], $purger->getPending());
  }

  /**
   * A failed Cloudflare purge is queued and retried until it succeeds.
   */
  public function testFailedPurgeIsQueuedAndRetried(): void {
    $this->setSetting('citizen_edge_host', 'none');
    $this->setSetting('citizen_edge_cloudflare', ['zone_id' => 'zone', 'api_token' => 'token']);
    $purger = $this->purger();
    $queue = $this->container->get('queue')->get(EdgePurger::QUEUE_NAME);

    $this->mock->append(new Response(500, [], '{"success":false,"errors":[{"message":"boom"}]}'));
    $purger->purgeUrls(['https://example.com/sites/default/files/a.pdf'], 'test');
    $purger->flush();
    $this->assertSame(1, $queue->numberOfItems(), 'The failed batch was queued.');

    // Cron worker: fails again, so the item is re-queued with attempts=2.
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(EdgePurger::QUEUE_NAME);
    $this->mock->append(new Response(502, [], 'bad gateway'));
    $item = $queue->claimItem();
    $this->assertSame(1, $item->data['attempts']);
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame(2, $item->data['attempts']);
    $this->assertSame(['https://example.com/sites/default/files/a.pdf'], $item->data['urls']);

    // Success: nothing new is queued.
    $this->mock->append(new Response(200, [], '{"success":true}'));
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * A batch is abandoned after the maximum number of attempts.
   */
  public function testPurgeIsAbandonedAfterMaxAttempts(): void {
    $this->setSetting('citizen_edge_host', 'none');
    $this->setSetting('citizen_edge_cloudflare', ['zone_id' => 'zone', 'api_token' => 'token']);
    $purger = $this->purger();
    $queue = $this->container->get('queue')->get(EdgePurger::QUEUE_NAME);
    $this->mock->append(new Response(500, [], '{"success":false}'));
    $purger->retry([
      'layer' => 'cloudflare',
      'urls' => ['https://example.com/x.pdf'],
      'reason' => 'test',
      'attempts' => EdgePurger::MAX_ATTEMPTS - 1,
    ]);
    $this->assertSame(0, $queue->numberOfItems(), 'The last allowed attempt failing does not re-queue.');
  }

  /**
   * Successful Cloudflare purges are chunked and not queued.
   */
  public function testSuccessfulPurgeLeavesQueueEmpty(): void {
    $this->setSetting('citizen_edge_host', 'none');
    $this->setSetting('citizen_edge_cloudflare', ['zone_id' => 'zone', 'api_token' => 'token']);
    $purger = $this->purger();
    $urls = [];
    for ($i = 0; $i < 35; $i++) {
      $urls[] = "https://example.com/f$i.pdf";
    }
    $this->mock->append(new Response(200, [], '{"success":true}'), new Response(200, [], '{"success":true}'));
    $purger->purgeUrls($urls, 'test');
    $purger->flush();
    $this->assertSame(0, $this->mock->count(), 'Two chunks (30 + 5) were sent.');
    $this->assertSame(0, $this->container->get('queue')->get(EdgePurger::QUEUE_NAME)->numberOfItems());
  }

}
