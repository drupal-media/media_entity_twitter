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
  protected $media_id = 'twitter';

  /**
   * The test media bundle.
   *
   * @var \Drupal\media_entity\MediaBundleInterface
   */
  protected $testBundle;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $bundle['bundle'] = $this->media_id;
    $this->testBundle = $this->drupalCreateMediaBundle($bundle, 'twitter');
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
  public function testManageLinkFormatter() {
    // Test and create one media bundle.
    $bundle = $this->testBundle;

    // Assert that the media bundle has the expected values before proceeding.
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id());
    $this->assertFieldByName('label', $bundle->label());
    $this->assertFieldByName('type', 'twitter');

    // Add and save link settings (Embed code).
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id() . '/fields/add-field');
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
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id());
    $this->assertFieldByName('label', $bundle->label());
    $this->assertFieldByName('type', 'twitter');
    $this->assertFieldByName('type_configuration[twitter][source_field]', 'field_link_url');
    $this->drupalPostForm(NULL, NULL, t('Save media bundle'));
    $this->assertText('The media bundle ' . $bundle->label() . ' has been updated.');
    $this->assertText($bundle->label());

    $this->drupalGet('admin/structure/media/manage/' . $bundle->id() . '/display');

    // Set and save the settings of the new field.
    $edit = [
      'fields[field_link_url][label]' => 'above',
      'fields[field_link_url][type]' => 'link',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->assertText('Your settings have been saved.');

    // Create and save the media with an twitter media code.
    $this->drupalGet('media/add/' . $bundle->id());

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
   * Tests adding and editing an embed code type twitter embed formatter.
   */
  public function testManageFieldFormatter() {
    // Test and create one media bundle.
    $bundle = $this->testBundle;

    // Assert that the media bundle has the expected values before proceeding.
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id());
    $this->assertFieldByName('label', $bundle->label());
    $this->assertFieldByName('type', 'twitter');

    // Add and save field settings (Embed code).
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id() . '/fields/add-field');
    $edit_conf = [
      'new_storage_type' => 'string_long',
      'label' => 'Embed code',
      'field_name' => 'embed_code',
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
    ];
    $this->drupalPostForm(NULL, $edit, t('Save settings'));
    $this->assertText('Saved ' . $edit_conf['label'] . ' configuration.');

    // Assert that the new field configuration has been successfully saved.
    $xpath = $this->xpath('//*[@id="field-embed-code"]');
    $this->assertEqual((string) $xpath[0]->td[0], 'Embed code');
    $this->assertEqual((string) $xpath[0]->td[1], 'field_embed_code');
    $this->assertEqual((string) $xpath[0]->td[2]->a, 'Text (plain, long)');

    // Test if edit worked and if new field values have been saved as
    // expected.
    $this->drupalGet('admin/structure/media/manage/' . $bundle->id());
    $this->assertFieldByName('label', $bundle->label());
    $this->assertFieldByName('type', 'twitter');
    $this->assertFieldByName('type_configuration[twitter][source_field]', 'field_embed_code');
    $this->drupalPostForm(NULL, NULL, t('Save media bundle'));
    $this->assertText('The media bundle ' . $bundle->label() . ' has been updated.');
    $this->assertText($bundle->label());

    $this->drupalGet('admin/structure/media/manage/' . $bundle->id() . '/display');

    // Set and save the settings of the new field.
    $edit = [
      'fields[field_embed_code][label]' => 'above',
      'fields[field_embed_code][type]' => 'twitter_embed',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->assertText('Your settings have been saved.');

    // Create and save the media with an twitter media code.
    $this->drupalGet('media/add/' . $bundle->id());

    // Random image from twitter.
    $tweet_url = '<blockquote class="twitter-tweet" lang="it"><p lang="en" dir="ltr">' .
                 'Midnight project. I ain&#39;t got no oven. So I improvise making this milo crunchy kek batik. hahahaha ' .
                 '<a href="https://twitter.com/hashtag/itssomething?src=hash">#itssomething</a> ' .
                 '<a href="https://t.co/Nvn4Q1v2ae">pic.twitter.com/Nvn4Q1v2ae</a></p>&mdash; Zi (@RamzyStinson) ' .
                 '<a href="https://twitter.com/RamzyStinson/status/670650348319576064">' .
                 '28 Novembre 2015</a></blockquote><script async src="//platform.twitter.com/widgets.js" charset="utf-8"></script>';

    $edit = [
      'name[0][value]' => 'Title',
      'field_embed_code[0][value]' => $tweet_url,
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Assert that the media has been successfully saved.
    $this->assertText('Title');
    $this->assertText('Embed code');

    // Assert that the formatter exists on this page.
    $this->assertFieldByXPath('/html/body/div/main/div/div/article/div/div');
  }

}
