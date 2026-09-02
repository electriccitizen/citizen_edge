<?php

namespace Drupal\Tests\citizen_edge\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\media\Entity\MediaType;
use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;

/**
 * Tests the adaptive setup routine and the EC-standard enforcement.
 *
 * @group citizen_edge
 */
#[RunTestsInSeparateProcesses]
class SetupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'views',
    'entity_usage',
    'media_entity_file_replace',
    'media_file_delete',
    'citizen_edge',
    'citizen_edge_standard',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig(['system', 'image', 'media', 'entity_usage', 'media_file_delete']);
    $this->container->get('module_handler')->loadInclude('citizen_edge', 'install');
    $this->container->get('module_handler')->loadInclude('citizen_edge_standard', 'install');
  }

  /**
   * Creates a file-sourced media type with its source field.
   */
  protected function createFileMediaType(string $id = 'document'): MediaType {
    $type = MediaType::create(['id' => $id, 'label' => $id, 'source' => 'file']);
    $type->save();
    $source_field = $type->getSource()->createSourceField($type);
    $source_field->getFieldStorageDefinition()->save();
    $source_field->save();
    $type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
    return $type;
  }

  /**
   * Returns core media's admin view, installing it if absent.
   *
   * With views enabled, installing media's config already brings in its
   * optional views.view.media, so this normally just loads it.
   */
  protected function installMediaView(): View {
    if ($view = View::load('media')) {
      return $view;
    }
    $data = Yaml::decode(file_get_contents($this->container->get('extension.list.module')->getPath('media') . '/config/optional/views.view.media.yml'));
    $view = View::create($data);
    $view->save();
    return $view;
  }

  /**
   * Flattens decisions to their messages.
   */
  protected function messages(array $decisions): array {
    return array_map(fn(array $d) => $d[1], $decisions);
  }

  /**
   * Asserts that some decision message contains the needle.
   */
  protected function assertDecision(array $decisions, string $needle, ?string $level = NULL): void {
    foreach ($decisions as [$l, $message]) {
      if (str_contains($message, $needle) && ($level === NULL || $l === $level)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail("No decision containing '$needle'. Got: " . implode(' | ', $this->messages($decisions)));
  }

  /**
   * The baseline applies into a vacuum and reports each decision.
   */
  public function testBaselineAppliesOnFreshSite(): void {
    $this->createFileMediaType();
    Role::create(['id' => 'site_admin', 'label' => 'Site admin'])->save();

    $decisions = citizen_edge_setup();

    $this->assertDecision($decisions, 'Applied EC baseline entity_usage settings');
    $this->assertDecision($decisions, 'Enabled the Replace file widget on the document media form');
    $this->assertDecision($decisions, 'Granted to role site_admin: access entity usage statistics, delete any file');
    $this->assertDecision($decisions, 'Added the "Usage count" column');
    $this->assertDecision($decisions, 'Defaulted Media File Delete');

    $this->assertSame(['media', 'node'], $this->config('entity_usage.settings')->get('track_enabled_target_entity_types'));
    $display = $this->container->get('entity_display.repository')->getFormDisplay('media', 'document', 'default');
    $this->assertNotNull($display->getComponent('replace_file'));
    $role = Role::load('site_admin');
    $this->assertTrue($role->hasPermission('delete any file'));
    $this->assertTrue($role->hasPermission('access entity usage statistics'));
    $this->assertTrue($this->config('media_file_delete.settings')->get('delete_file_default'));
  }

  /**
   * Without a media view, setup warns instead of failing.
   */
  public function testMissingMediaViewIsReported(): void {
    View::load('media')->delete();
    $decisions = citizen_edge_setup();
    $this->assertDecision($decisions, 'No views.view.media found', 'warning');
    $this->assertNull(View::load('media'), 'Setup never creates a view on its own.');
  }

  /**
   * A dry run reports the same decisions and changes nothing.
   */
  public function testDryRunChangesNothing(): void {
    $this->createFileMediaType();
    Role::create(['id' => 'site_manager', 'label' => 'Site manager'])->save();

    $decisions = citizen_edge_setup(TRUE);

    $this->assertDecision($decisions, 'Would apply EC baseline entity_usage settings');
    $this->assertDecision($decisions, 'Would enable the Replace file widget on the document media form');
    $this->assertDecision($decisions, 'Would grant to role site_manager');
    $this->assertNull($this->config('entity_usage.settings')->get('track_enabled_target_entity_types'));
    $this->assertNull($this->container->get('entity_display.repository')->getFormDisplay('media', 'document', 'default')->getComponent('replace_file'));
    $this->assertFalse(Role::load('site_manager')->hasPermission('delete any file'));
    $this->assertEmpty($this->config('media_file_delete.settings')->get('delete_file_default'));
  }

  /**
   * A second run finds everything in place and changes nothing.
   */
  public function testSetupIsIdempotent(): void {
    $this->createFileMediaType();
    Role::create(['id' => 'manager', 'label' => 'Manager'])->save();
    $this->installMediaView();

    citizen_edge_setup();
    $view_after_first = View::load('media')->toArray();
    $second = citizen_edge_setup();

    foreach ($this->messages($second) as $message) {
      $this->assertDoesNotMatchRegularExpression('/^(Applied|Enabled|Granted|Added|Defaulted)/', $message, "Second run should only skip, got: $message");
    }
    $this->assertDecision($second, 'already has the usage count field');
    $this->assertDecision($second, 'already holds the package permissions');
    $this->assertSame($view_after_first, View::load('media')->toArray());
  }

  /**
   * Existing site choices (configured entity_usage, hidden widget) are kept.
   */
  public function testExistingChoicesAreLeftAlone(): void {
    $this->createFileMediaType();
    $this->config('entity_usage.settings')->set('track_enabled_target_entity_types', ['node'])->save();
    $display = $this->container->get('entity_display.repository')->getFormDisplay('media', 'document', 'default');
    $display->removeComponent('replace_file')->save();
    $this->config('core.entity_form_display.media.document.default')->set('hidden', ['replace_file' => TRUE])->save();

    $decisions = citizen_edge_setup();

    $this->assertDecision($decisions, 'entity_usage is already configured');
    $this->assertDecision($decisions, 'explicitly hides the Replace file widget');
    $this->assertDecision($decisions, 'No site-manager-style role found', 'warning');
    $this->assertSame(['node'], $this->config('entity_usage.settings')->get('track_enabled_target_entity_types'));
  }

  /**
   * The usage column is added to the media view and survives re-runs.
   */
  public function testUsageColumnAddedToMediaView(): void {
    $this->installMediaView();
    $decisions = citizen_edge_setup();
    $this->assertDecision($decisions, 'Added the "Usage count" column');
    $display = View::load('media')->getDisplay('default');
    $this->assertArrayHasKey('citizen_edge_usage_count', $display['display_options']['fields']);
    $this->assertArrayNotHasKey('group_by', array_filter($display['display_options']), 'Aggregation is never enabled.');
  }

  /**
   * The standard applies the permission matrix and grandfathers custom roles.
   */
  public function testStandardPermissionMatrix(): void {
    $this->createFileMediaType();
    Role::create(['id' => 'site_manager', 'label' => 'Site manager'])->save();
    $editor = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $editor->grantPermission('delete any file')->grantPermission('delete any media')->save();
    $plus = Role::create(['id' => 'editor_plus', 'label' => 'Editor plus']);
    $plus->grantPermission('delete any media')->save();
    $viewer = Role::create(['id' => 'viewer', 'label' => 'Viewer']);
    $viewer->save();

    $decisions = citizen_edge_standard_setup();

    $this->assertDecision($decisions, 'granted site_manager: delete any file, replace media files, perform batch updates entity usage, access entity usage statistics');
    $this->assertDecision($decisions, 'revoked from editor: delete any file, delete any media');
    $this->assertDecision($decisions, 'role editor_plus already holds delete permissions');

    $site_manager = Role::load('site_manager');
    $manager_set = [
      'delete any file',
      'replace media files',
      'perform batch updates entity usage',
      'access entity usage statistics',
    ];
    foreach ($manager_set as $perm) {
      $this->assertTrue($site_manager->hasPermission($perm), "site_manager has $perm");
    }
    $editor = Role::load('editor');
    $this->assertFalse($editor->hasPermission('delete any file'));
    $this->assertFalse($editor->hasPermission('delete any media'));
    $this->assertTrue($editor->hasPermission('access entity usage statistics'));
    $plus = Role::load('editor_plus');
    $this->assertTrue($plus->hasPermission('delete any media'), 'Grandfathered roles lose nothing.');
    $this->assertTrue($plus->hasPermission('replace media files'));
    $this->assertTrue($plus->hasPermission('delete any file'));
    $this->assertFalse(Role::load('viewer')->hasPermission('replace media files'), 'Roles without delete powers are untouched.');
  }

  /**
   * Without a site_manager role the matrix is skipped but grandfathering runs.
   */
  public function testStandardWithoutSiteManagerRole(): void {
    $this->createFileMediaType();
    $manager = Role::create(['id' => 'manager', 'label' => 'Manager']);
    $manager->grantPermission('delete any media')->save();
    $editor = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $editor->grantPermission('delete any media')->save();

    $decisions = citizen_edge_standard_setup();

    $this->assertDecision($decisions, 'no site_manager role on this site', 'warning');
    $this->assertTrue(Role::load('manager')->hasPermission('replace media files'), 'manager-tier role grandfathered.');
    $this->assertTrue(Role::load('editor')->hasPermission('delete any media'), 'editor is NOT demoted when the matrix is skipped.');
  }

  /**
   * A standard dry run changes no role.
   */
  public function testStandardDryRunChangesNothing(): void {
    Role::create(['id' => 'site_manager', 'label' => 'Site manager'])->save();
    $editor = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $editor->grantPermission('delete any file')->save();

    $decisions = citizen_edge_standard_setup(TRUE);

    $this->assertDecision($decisions, 'would granted site_manager');
    $this->assertDecision($decisions, 'would revoked from editor: delete any file');
    $this->assertFalse(Role::load('site_manager')->hasPermission('delete any file'));
    $this->assertTrue(Role::load('editor')->hasPermission('delete any file'));
  }

  /**
   * The standard view merge replaces columns and keeps filters.
   */
  public function testStandardMediaViewMergeKeepsFilters(): void {
    $view = $this->installMediaView();
    $filters_before = $view->getDisplay('default')['display_options']['filters'];
    $this->assertNotEmpty($filters_before);

    $decisions = citizen_edge_standard_setup();
    $this->assertDecision($decisions, 'merged EC-standard columns into the media view');

    $display = View::load('media')->getDisplay('default');
    $this->assertSame($filters_before, $display['display_options']['filters'], 'Filters are untouched.');
    $this->assertArrayHasKey('citizen_edge_usage_count', $display['display_options']['fields']);
    $this->assertArrayNotHasKey('filesize', $display['display_options']['fields'], 'Image size column pruned without field_media_image.');
    $this->assertNotEmpty($this->container->get('state')->get('citizen_edge_standard.previous_view'));

    $second = citizen_edge_standard_setup();
    $this->assertDecision($second, 'already carries the EC-standard columns');
  }

}
