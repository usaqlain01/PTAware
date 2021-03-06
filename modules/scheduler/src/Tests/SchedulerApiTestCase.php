<?php

/**
 * @file
 * Contains \Drupal\scheduler\Tests\SchedulerApiTestCase.
 */

namespace Drupal\scheduler\Tests;

use Drupal\node\Entity\NodeType;

/**
 * Tests the API of the Scheduler module.
 *
 * @group scheduler
 */
class SchedulerApiTestCase extends SchedulerTestBase {

  /**
   * The additional modules to be loaded for this test.
   */
  public static $modules = ['scheduler_api_test', 'menu_ui', 'path'];
  // @todo 'menu_ui' is in the exported node.type definition, and 'path' is in
  // the entity_form_display. Could these be removed from the config files and
  // then not needed here?

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Load the custom node type and check it .
    $this->customName = 'scheduler_api_test';
    $this->customNodetype = NodeType::load($this->customName);
    $this->assertNotNull($this->customNodetype, 'Custom node type "' . $this->customName . '"  was created during install');
    // Do not need to enable this node type for scheduler as that is already
    // pre-configured in node.type.scheduler_api_test.yml

    // Create a web user for this content type.
    $this->webUser = $this->drupalCreateUser([
      'create ' . $this->customName . ' content',
      'edit any ' . $this->customName . ' content',
      'schedule publishing of nodes',
    ]);

    // Create node_storage property.
    $this->node_storage = $this->container->get('entity.manager')->getStorage('node');

  }

  /**
   * Covers hook_scheduler_allow_publishing() and hook_scheduler_allow_unpublishing()
   *
   * These hooks can allow or deny the (un)publication of individual nodes. This
   * test uses a content type which has checkboxes 'Approved for publication'
   * and 'Approved for unpublication'. The node may only be published or
   * unpublished if the appropriate checkbox is ticked.
   *
   * @todo Create and update the nodes through the interface so we can check if
   *   the correct messages are displayed.
   */
  public function testAllowedPublishingAndUnpublishing() {
    // Check that the approved fields are shown on the node/add form.
    $this->drupalLogin($this->webUser);
    $this->drupalGet('node/add/' . $this->customName);
    $this->assertFieldById('edit-field-approved-publishing-value', '', 'The "Approved for publishing" field is shown on the node form');
    $this->assertFieldById('edit-field-approved-unpublishing-value', '', 'The "Approved for unpublishing" field is shown on the node form');

    // Create a node that is scheduled but not approved for publication. Then
    // simulate a cron run, and check that the node is still not published.
    $node = $this->createUnapprovedNode('publish_on');
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertFalse($node->isPublished(), 'An unapproved node is not published during cron processing.');

    // Create a node and approve it for publication, simulate a cron run and
    // check that the node is published. This is a stronger test than simply
    // approving the previously used node above, as we do not know what publish
    // state that may be in after the cron run above.
    $node = $this->createUnapprovedNode('publish_on');
    $this->approveNode($node->id(), 'field_approved_publishing');
    $this->assertFalse($node->isPublished(), 'A new approved node is initially not published.');
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isPublished(), 'An approved node is published during cron processing.');

    // Turn on immediate publication of nodes with publication dates in the past
    // and repeat the tests. It is not needed to simulate cron runs here.
    $this->customNodetype->setThirdPartySetting('scheduler', 'publish_past_date', 'publish')->save();
    $node = $this->createUnapprovedNode('publish_on');
    $this->assertFalse($node->isPublished(), 'An unapproved node with a date in the past is not published immediately after saving.');

    // Check that the node can be approved and published programatically.
    $this->approveNode($node->id(), 'field_approved_publishing');
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isPublished(), 'An approved node with a date in the past is published immediately via $node->set()->save().');

    // Check that a node can be approved and published via edit form.
    $node = $this->createUnapprovedNode('publish_on');
    $this->drupalPostForm('node/' . $node->id() . '/edit', ['field_approved_publishing[value]' => '1'], t('Save'));
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isPublished(), 'An approved node with a date in the past is published immediately after saving via edit form.');

    // Test approval for unpublishing. This is simpler than the test sequence
    // for publishing, because the past date 'publish' option is not applicable.
    // Create a node that is scheduled but not approved for unpublication. Then
    // simulate a cron run, and check that the node is still published.
    $node = $this->createUnapprovedNode('unpublish_on');
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isPublished(), 'An unapproved node is not unpublished during cron processing.');

    // Create a node, then approve it for unpublishing, simulate a cron run and
    // check that the node is now unpublished.
    $node = $this->createUnapprovedNode('unpublish_on');
    $this->approveNode($node->id(), 'field_approved_unpublishing');
    $this->assertTrue($node->isPublished(), 'A new approved node is initially published.');
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertFalse($node->isPublished(), 'An approved node is unpublished during cron processing.');
  }

  /**
   * Creates a new node that is not approved.
   *
   * The node has a publish/unpublish date in the past to make sure it will be
   * included in the next cron run.
   *
   * @param string $date_field
   *   The Scheduler date field to set, either 'publish_on' or 'unpublish_on'.
   *
   * @return \Drupal\node\NodeInterface
   *   A node object.
   */
  protected function createUnapprovedNode($date_field) {
    $settings = array(
      'status' => ($date_field == 'unpublish_on'),
      $date_field => strtotime('-1 day'),
      'field_approved_publishing' => 0,
      'field_approved_unpublishing' => 0,
      'type' => $this->customName,
    );
    return $this->drupalCreateNode($settings);
  }

  /**
   * Approves a node for publication or unpublication.
   *
   * @param int $nid
   *   The id of the node to approve.
   *
   * @param string $field_name
   *   The name of the field to set, either 'field_approved_publishing' or
   *   'field_approved_unpublishing'.
   */
  protected function approveNode($nid, $field_name) {
    $this->node_storage->resetCache(array($nid));
    $node = $this->node_storage->load($nid);
    $node->set($field_name, TRUE)->save();
  }

  /**
   * Covers hook_scheduler_api($node, $action)
   *
   * This hook allows other modules to react to the Scheduler process being run.
   * The API test implementation of this hook alters the nodes 'promote' and
   * 'sticky' settings.
   */
  public function testApiNodeAction() {
    $this->drupalLogin($this->adminUser);

    // Create a test node. Having the 'approved' fields here would complicate
    // the tests, so use the ordinary 'page' type.
    $settings = array(
      'publish_on' => strtotime('-1 day'),
      'type' => $this->nodetype->get('type'),
      'promote' => FALSE,
      'sticky' => FALSE,
      'title' => 'API TEST node action',
    );
    $node = $this->drupalCreateNode($settings);

    // Check that the 'sticky' and 'promote' fields are off for the new node.
    $this->assertFalse($node->isSticky(), 'The unpublished node is not sticky.');
    $this->assertFalse($node->isPromoted(), 'The unpublished node is not promoted.');

    // Run cron and check that hook_scheduler_api() has executed correctly, by
    // verifying that the node has become promoted and is sticky.
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isSticky(), "API action 'PRE_PUBLISH' has changed the node to sticky.");
    $this->assertTrue($node->isPromoted(), "API action 'PUBLISH' has changed the node to promoted.");

    // Now set a date for unpublishing the node. Ensure 'sticky' and 'promote'
    // are set, so that the assertions are not affected by any failures above.
    $node->set('unpublish_on', strtotime('-1 day'))
      ->set('sticky', TRUE)->set('promote', TRUE)->save();

    // Run cron and check that hook_scheduler_api() has executed correctly, by
    // verifying that the node is not promoted and no longer sticky.
    scheduler_cron();
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertFalse($node->isSticky(), "API action 'PRE_UNPUBLISH' has changed the node to not sticky.");
    $this->assertFalse($node->isPromoted(), "API action 'UNPUBLISH' has changed the node to not promoted.");

    // Turn on immediate publication when a publish date is in the past.
    $this->nodetype->setThirdPartySetting('scheduler', 'publish_past_date', 'publish')->save();
    $edit = [
      'publish_on[0][value][date]' => date('Y-m-d', strtotime('-2 day', REQUEST_TIME)),
      'publish_on[0][value][time]' => date('H:i:s', strtotime('-2 day', REQUEST_TIME)),
      'promote[value]' => FALSE,
      'sticky[value]' => FALSE,
    ];
    // Edit the node and verify that the values have been altered as expected.
    $this->drupalPostForm('node/' . $node->id() . '/edit', $edit, t('Save and keep unpublished'));
    $this->node_storage->resetCache(array($node->id()));
    $node = $this->node_storage->load($node->id());
    $this->assertTrue($node->isSticky(), "API action 'PUBLISH_IMMEDIATELY' has changed the node to sticky.");
    $this->assertTrue($node->isPromoted(), "API action 'PUBLISH_IMMEDIATELY' has changed the node to promoted.");
    $this->assertEqual($node->title->value, 'Published immediately', "API action 'PUBLISH_IMMEDIATELY' has changed the node title correctly.");
  }

  /**
   * Covers hook_scheduler_nid_list($action)
   *
   * hook_scheduler_nid_list() allows other modules to add more node ids into
   * the list to be processed. In real scenarios, the third-party module would
   * likely have more complex data structures and/or tables from which to
   * identify nodes to add. In this test, to keep it simple, we identify nodes
   * by the text of the title.
   */
  public function testNidList() {
    $this->drupalLogin($this->adminUser);

    // Create test nodes. Use the ordinary 'page' type for this test, as having
    // the 'approved' fields here would unnecessarily complicate the processing.
    $type = $this->nodetype->get('type');
    // Node 1 is not published and has no publishing date set. The test API
    // module will add node 1 into the list to be published.
    $node1 = $this->drupalCreateNode(['type' => $type, 'status' => FALSE, 'title' => 'API TEST nid_list publish me']);
    // Node 2 is published and has no unpublishing date set. The test API module
    // will add node 2 into the list to be unpublished.
    $node2 = $this->drupalCreateNode(['type' => $type, 'status' => TRUE, 'title' => 'API TEST nid_list unpublish me']);

    // Before cron, check node 1 is unpublished and node 2 is published.
    $this->assertFalse($node1->isPublished(), 'Before cron, node 1 "' . $node1->title->value . '" is unpublished.');
    $this->assertTrue($node2->isPublished(), 'Before cron, node 2 "' . $node2->title->value . '" is published.');

    // Run cron and refresh the nodes.
    scheduler_cron();
    $this->node_storage->resetCache();
    $node1 = $this->node_storage->load($node1->id());
    $node2 = $this->node_storage->load($node2->id());

    // Check node 1 is published and node 2 is unpublished.
    $this->assertTrue($node1->isPublished(), 'After cron, node 1 "' . $node1->title->value . '" is published.');
    $this->assertFalse($node2->isPublished(), 'After cron, node 2 "' . $node2->title->value . '" is unpublished.');
  }

  /**
   * Covers hook_scheduler_nid_list_alter($action)
   *
   * This hook allows other modules to add or remove node ids from the list to
   * be processed. As in testNidList() we make it simple by using the title text
   * to identify which nodes to act on.
   */
  public function testNidListAlter() {
    $this->drupalLogin($this->adminUser);

    // Create test nodes. Use the ordinary 'page' type for this test, as having
    // the 'approved' fields here would unnecessarily complicate the processing.
    $type = $this->nodetype->get('type');
    // Node 1 is set for scheduled publishing, but will be removed by the test
    // API hook_nid_list_alter().
    $node1 = $this->drupalCreateNode(['type' => $type, 'status' => FALSE, 'title' => 'API TEST nid_list_alter do not publish me', 'publish_on' => strtotime('-1 day')]);
    // Node 2 is not published and has no publishing date set. The test API
    // module will add node 2 into the list to be published.
    $node2 = $this->drupalCreateNode(['type' => $type, 'status' => FALSE, 'title' => 'API TEST nid_list_alter publish me']);
    // Node 3 is set for scheduled unpublishing, but will be removed by the test
    // API hook_nid_list_alter().
    $node3 = $this->drupalCreateNode(['type' => $type, 'status' => TRUE, 'title' => 'API TEST nid_list_alter do not unpublish me', 'unpublish_on' => strtotime('-1 day')]);
    // Node 4 is published and has no unpublishing date set. The test API module
    // will add node 4 into the list to be unpublished.
    $node4 = $this->drupalCreateNode(['type' => $type, 'status' => TRUE, 'title' => 'API TEST nid_list_alter unpublish me']);

    // Check node 1 and 2 are unpublished and node 3 and 4 are published.
    $this->assertFalse($node1->isPublished(), 'Before cron, node 1 "' . $node1->title->value . '" is unpublished.');
    $this->assertFalse($node2->isPublished(), 'Before cron, node 2 "' . $node2->title->value . '" is unpublished.');
    $this->assertTrue($node3->isPublished(), 'Before cron, node 3 "' . $node3->title->value . '" is published.');
    $this->assertTrue($node4->isPublished(), 'Before cron, node 4 "' . $node4->title->value . '" is published.');

    // Run cron and refresh the nodes.
    scheduler_cron();
    $this->node_storage->resetCache();
    $node1 = $this->node_storage->load($node1->id());
    $node2 = $this->node_storage->load($node2->id());
    $node3 = $this->node_storage->load($node3->id());
    $node4 = $this->node_storage->load($node4->id());

    // Check node 2 and 3 are published and node 1 and 4 are unpublished.
    $this->assertFalse($node1->isPublished(), 'After cron, node 1 "' . $node1->title->value . '" is still unpublished.');
    $this->assertTrue($node2->isPublished(), 'After cron, node 2 "' . $node2->title->value . '" is published.');
    $this->assertTrue($node3->isPublished(), 'After cron, node 3 "' . $node3->title->value . '" is still published.');
    $this->assertFalse($node4->isPublished(), 'After cron, node 4 "' . $node4->title->value . '" is unpublished.');
  }
}
