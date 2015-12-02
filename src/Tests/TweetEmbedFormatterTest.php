<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Tests\TweetEmbedFormatterTest.
 */

namespace Drupal\media_entity_twitter\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\media_entity\Tests\MediaTestTrait;

/**
 * Tests for Twitter embed formatter.
 *
 * @group media_entity_twitter
 */
class TweetEmbedFormatterTest extends WebTestBase {

  use MediaTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array(
    'media_entity_twitter',
    'media_entity',
    'node',
    'field_ui',
    'views_ui',
    'block',
    'link',
  );

  /**
   * The test user.
   *
   * @var \Drupal\User\UserInterface
   */
  protected $adminUser;

  /**
   * Media entity machine id.
   *
   * @var string
   */
  protected $media_id = 'Twitter';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->testBundle = $this->drupalCreateMediaBundle();
    $this->drupalPlaceBlock('local_actions_block');
    $this->adminUser = $this->drupalCreateUser([
      'administer media',
      'administer media fields',
      'administer media form display',
      'administer media display',
      // Media entity permissions.
      'view media',
      'create media',
      'update media',
      'update any media',
      'delete media',
      'delete any media',
      // Other permissions.
      'administer views',
    ]);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests adding and editing a link type twitter embed formatter.
   */
  public function dtestManageLinkFormatter() {
    // Test and create one media bundle.
    $bundle = $this->createMediaBundle();

    // Assert that the media bundle has the expected values before proceeding.
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id']);
    $this->assertFieldByName('label', $bundle['label']);
    $this->assertFieldByName('type', $bundle['type']);

    // Add and save link settings (Embed code).
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id'] . '/fields/add-field');
    $edit_conf = [
      'new_storage_type' => 'link',
      'label' => 'Link URL',
      'field_name' => 'link_url',
    ];
    $this->drupalPostForm(NULL, $edit_conf, t('Save and continue'));
    $this->assertText('These settings apply to the ' . $edit_conf['label'] . ' field everywhere it is used.');
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => '1',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save field settings'));
    $this->assertText('Updated field ' . $edit_conf['label'] . ' field settings.');

    // Set the new field as required.
    $edit = [
      'required' => TRUE,
      'settings[link_type]' => '16',
      'settings[title]' => '0',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save settings'));
    $this->assertText('Saved ' . $edit_conf['label'] . ' configuration.');

    // Assert that the new field configuration has been successfully saved.
    $xpath = $this->xpath('//*[@id="field-link-url"]');
    $this->assertEqual((string) $xpath[0]->td[0], 'Link URL');
    $this->assertEqual((string) $xpath[0]->td[1], 'field_link_url');
    $this->assertEqual((string) $xpath[0]->td[2]->a, 'Link');

    // Test if edit worked and if new field values have been saved as
    // expected.
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id']);
    $this->assertFieldByName('label', $bundle['label']);
    $this->assertFieldByName('type', $bundle['type']);
    $this->assertFieldByName('type_configuration[twitter][source_field]', 'field_link_url');
    $this->drupalPostForm(NULL, NULL, t('Save media bundle'));
    $this->assertText('The media bundle ' . $bundle['label'] . ' has been updated.');
    $this->assertText('Twitter');

    $this->drupalGet('admin/structure/media/manage/' . $bundle['id'] . '/display');

    // Set and save the settings of the new field.
    $edit = [
      'fields[field_link_url][label]' => 'above',
      'fields[field_link_url][type]' => 'link',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->assertText('Your settings have been saved.');

    // Create and save the media with an twitter media code.
    $this->drupalGet('media/add/' . $bundle['id']);

    // Random image from twitter.
    $tweet_url = 'https://twitter.com/RamzyStinson/status/670650348319576064';

    $edit = [
      'name[0][value]' => 'Title',
      'field_link_url[0][uri]' => $tweet_url,
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Assert that the media has been successfully saved.
    $this->assertText('Title');
    $this->assertText('Link URL');

    // Assert that the link url formatter exists on this page.
    $this->assertFieldByXPath('/html/body/div/main/div/div/article/div[5]/div[2]/a');
  }

  /**
   * Tests adding and editing a twitter embed formatter.
   */
  public function testManageFieldFormatter() {
    // Test and create one media bundle.
    $bundle = $this->createMediaBundle();

    // Assert that the media bundle has the expected values before proceeding.
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id']);
    $this->assertFieldByName('label', $bundle['label']);
    $this->assertFieldByName('type', $bundle['type']);

    // Add and save field settings (Embed code).
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id'] . '/fields/add-field');
    $edit_conf = [
      'new_storage_type' => 'link',
      'label' => 'Tweet URL',
      'field_name' => 'tweet_url',
    ];
    $this->drupalPostForm(NULL, $edit_conf, t('Save and continue'));
    $this->assertText('These settings apply to the ' . $edit_conf['label'] . ' field everywhere it is used.');
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => '1',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save field settings'));
    $this->assertText('Updated field ' . $edit_conf['label'] . ' field settings.');

    // Set the new field as required.
    $edit = [
      'required' => TRUE,
      'settings[link_type]' => '16',
      'settings[title]' => '0',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save settings'));
    $this->assertText('Saved ' . $edit_conf['label'] . ' configuration.');

    // Assert that the new field configuration has been successfully saved.
    $xpath = $this->xpath('//*[@id="field-tweet-url"]');
    $this->assertEqual((string) $xpath[0]->td[0], 'Tweet URL');
    $this->assertEqual((string) $xpath[0]->td[1], 'field_tweet_url');
    $this->assertEqual((string) $xpath[0]->td[2]->a, 'Link');

    // Test if edit worked and if new field values have been saved as
    // expected.
    $this->drupalGet('admin/structure/media/manage/' . $bundle['id']);
    $this->assertFieldByName('label', $bundle['label']);
    $this->assertFieldByName('type', $bundle['type']);
    $this->assertFieldByName('type_configuration[twitter][source_field]', 'field_tweet_url');
    $this->drupalPostForm(NULL, NULL, t('Save media bundle'));
    $this->assertText('The media bundle ' . $bundle['label'] . ' has been updated.');
    $this->assertText('Twitter');

    $this->drupalGet('admin/structure/media/manage/' . $bundle['id'] . '/display');

    // Set and save the settings of the new field.
    $edit = [
      'fields[field_tweet_url][label]' => 'above',
      'fields[field_tweet_url][type]' => 'twitter_embed',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->assertText('Your settings have been saved.');

    // Create and save the media with an twitter media code.
    $this->drupalGet('media/add/' . $bundle['id']);

    // Random image from twitter.
    $tweet_url = 'https://twitter.com/RamzyStinson/status/670650348319576064';

    $edit = [
      'name[0][value]' => 'Title',
      'field_tweet_url[0][uri]' => $tweet_url,
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Assert that the media has been successfully saved.
    $this->assertText('Title');
    $this->assertText('Tweet URL');

    // Assert that the formatter exists on this page.
    $this->assertFieldByXPath('/html/body/div/main/div/div/article/div/div');
  }

  /**
   * Creates and tests a new media bundle.
   *
   * @return array
   *   Returns the media bundle fields.
   */
  public function createMediaBundle() {
    // Generates and holds all media bundle fields.
    $edit = [
      'id' => strtolower($this->media_id),
      'label' => $this->media_id,
      'type' => 'twitter',
    ];

    // Create new media bundle.
    $this->drupalPostForm('admin/structure/media/add', $edit, t('Save media bundle'));
    $this->assertText('The media bundle ' . $this->media_id . ' has been added.');

    // Check if media bundle is successfully created.
    $this->drupalGet('admin/structure/media');
    $this->assertResponse(200);
    $this->assertRaw($edit['label']);

    return $edit;
  }

}
