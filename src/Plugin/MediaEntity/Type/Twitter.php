<?php

namespace Drupal\media_entity_twitter\Plugin\MediaEntity\Type;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeBase;
use Drupal\media_entity\MediaTypeException;
use Drupal\Component\Serialization\Json;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides media type plugin for Twitter.
 *
 * @MediaType(
 *   id = "twitter",
 *   label = @Translation("Twitter"),
 *   description = @Translation("Provides business logic and metadata for Twitter.")
 * )
 */
class Twitter extends MediaTypeBase {

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('renderer')
    );
  }

  /**
   * List of validation regular expressions.
   *
   * @var array
   */
  public static $validationRegexp = array(
    '@((http|https):){0,1}//(www\.){0,1}twitter\.com/(?<user>[a-z0-9_-]+)/(status(es){0,1})/(?<id>[\d]+)@i' => 'id',
  );

  /**
   * Constructs a new class instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config_factory, RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $config_factory->get('media_entity.settings'));
    $this->configFactory = $config_factory;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'use_twitter_api' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function providedFields() {
    $fields = array(
      'id' => $this->t('Tweet ID'),
      'user' => $this->t('Twitter user information'),
    );

    if ($this->configuration['use_twitter_api']) {
      $fields += array(
        'image' => $this->t('Link to the twitter image'),
        'image_local' => $this->t('Copies tweet image to the local filesystem and returns the URI.'),
        'image_local_uri' => $this->t('Gets URI of the locally saved image.'),
        'content' => $this->t('This tweet content'),
        'retweet_count' => $this->t('Retweet count for this tweet'),
      );
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getField(MediaInterface $media, $name) {
    $matches = $this->matchRegexp($media);

    if (!$matches['id']) {
      return FALSE;
    }

    // First we return the fields that are available from regex.
    switch ($name) {
      case 'id':
        return $matches['id'];

      case 'user':
        if ($matches['user']) {
          return $matches['user'];
        }
        return FALSE;
    }

    // If we have auth settings return the other fields.
    if ($this->configuration['use_twitter_api'] && $tweet = $this->fetchTweet($matches['id'])) {
      switch ($name) {
        case 'image':
          if (isset($tweet['extended_entities']['media'][0]['media_url'])) {
            return $tweet['extended_entities']['media'][0]['media_url'];
          }
          return FALSE;

        case 'image_local':
          if (isset($tweet['extended_entities']['media'][0]['media_url'])) {
            try {
              $local_uri = $this->prepareLocalImageDirectory();
            }
            catch (\Exception $e) {
              return FALSE;
            }

            $local_uri .= '/' . $matches['id'] . '.' . pathinfo($tweet['extended_entities']['media'][0]['media_url'], PATHINFO_EXTENSION);
            if (!file_exists($local_uri)) {
              file_unmanaged_save_data($tweet['extended_entities']['media'][0]['media_url'], $local_uri, FILE_EXISTS_REPLACE);
            }

            return $local_uri;
          }
          return FALSE;

        case 'image_local_uri':
          if (isset($tweet['extended_entities']['media'][0]['media_url'])) {
            return $this->configFactory->get('media_entity_twitter.settings')->get('local_images') . '/' . $matches['id'] . '.' . pathinfo($tweet['extended_entities']['media'][0]['media_url'], PATHINFO_EXTENSION);
          }
          return FALSE;

        case 'content':
          if (isset($tweet['text'])) {
            return $tweet['text'];
          }
          return FALSE;

        case 'retweet_count':
          if (isset($tweet['retweet_count'])) {
            return $tweet['retweet_count'];
          }
          return FALSE;

        case 'thumbnail':
          try {
            $uri = $this->prepareLocalImageDirectory();
          }
          catch (\Exception $e) {
            return FALSE;
          }

          $uri .= '/' . $matches['id'] . '.svg';
          if (!file_exists($uri)) {
            $avatar_uri = $this->copyTwitterProfileImage($tweet['user']['profile_image_url']);

            $build = [
              '#theme' => 'media_entity_twitter_tweet_thumbnail',
              '#tweet' => $tweet['text'],
              '#author' => $tweet['user']['name'],
              '#avatar' => file_create_url($avatar_uri),
            ];
            $contents = $this->renderer->render($build);
            file_unmanaged_save_data($contents, $uri);
          }
          return $uri;
      }
    }

    return FALSE;
  }

  /**
   * Copies a Twitter profile image to a local directory.
   *
   * @param string $source_url
   *   The URL to the profile image hosted by Twitter.
   *
   * @return string
   *   The local URI of the copied image, or $source_url if an error occurred.
   */
  protected function copyTwitterProfileImage($source_url) {
    try {
      $destination_uri = $this->prepareLocalImageDirectory();
    }
    catch (\Exception $e) {
      return $source_url;
    }

    $destination_uri .= '/' . basename(parse_url($source_url, PHP_URL_PATH));
    // We need to use copy(), not file_unmanaged_copy(), because
    // file_unmanaged_copy() will try to verify the existence of the file
    // (not possible with HTTP URIs).
    $success = copy($source_url, $destination_uri);

    return $success ? $destination_uri : $source_url;
  }

  /**
   * Prepares the configured local images directory for writing.
   *
   * @return string
   *   The URI of the local image directory.
   *
   * @throws \Exception if the file system operation fails.
   */
  protected function prepareLocalImageDirectory() {
    $dir = $this->configFactory->get('media_entity_twitter.settings')->get('local_images');
    $success = file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
    if ($success) {
      return $dir;
    }
    else {
      throw new \Exception("Cannot prepare $dir for writing");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $allowed_field_types = ['string', 'string_long', 'link'];
    /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
    $bundle = $form_state->getFormObject()->getEntity();
    foreach ($this->entityFieldManager->getFieldDefinitions('media', $bundle->id()) as $field_name => $field) {
      if (in_array($field->getType(), $allowed_field_types) && !$field->getFieldStorageDefinition()->isBaseField()) {
        $options[$field_name] = $field->getLabel();
      }
    }

    $form['source_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('Field with source information'),
      '#description' => $this->t('Field on media entity that stores Twitter embed code or URL. You can create a bundle without selecting a value for this dropdown initially. This dropdown can be populated after adding fields to the bundle.'),
      '#default_value' => empty($this->configuration['source_field']) ? NULL : $this->configuration['source_field'],
      '#options' => $options,
    );

    $form['use_twitter_api'] = array(
      '#type' => 'select',
      '#title' => $this->t('Whether to use Twitter api to fetch tweets or not.'),
      '#description' => $this->t("In order to use Twitter's api you have to create a developer account and an application. For more information consult the readme file."),
      '#default_value' => empty($this->configuration['use_twitter_api']) ? 0 : $this->configuration['use_twitter_api'],
      '#options' => array(
        0 => $this->t('No'),
        1 => $this->t('Yes'),
      ),
    );

    // @todo Evauate if this should be a site-wide configuration.
    $form['consumer_key'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Consumer key'),
      '#default_value' => empty($this->configuration['consumer_key']) ? NULL : $this->configuration['consumer_key'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['consumer_secret'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Consumer secret'),
      '#default_value' => empty($this->configuration['consumer_secret']) ? NULL : $this->configuration['consumer_secret'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Oauth access token'),
      '#default_value' => empty($this->configuration['oauth_access_token']) ? NULL : $this->configuration['oauth_access_token'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token_secret'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Oauth access token secret'),
      '#default_value' => empty($this->configuration['oauth_access_token_secret']) ? NULL : $this->configuration['oauth_access_token_secret'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function attachConstraints(MediaInterface $media) {
    parent::attachConstraints($media);

    if (isset($this->configuration['source_field'])) {
      $source_field_name = $this->configuration['source_field'];
      if ($media->hasField($source_field_name)) {
        foreach ($media->get($source_field_name) as &$embed_code) {
          /** @var \Drupal\Core\TypedData\DataDefinitionInterface $typed_data */
          $typed_data = $embed_code->getDataDefinition();
          $typed_data->addConstraint('TweetEmbedCode');
          $typed_data->addConstraint('TweetVisible');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultThumbnail() {
    return $this->config->get('icon_base') . '/twitter.png';
  }

  /**
   * {@inheritdoc}
   */
  public function thumbnail(MediaInterface $media) {
    if ($local_image = $this->getField($media, 'image_local')) {
      return $local_image;
    }
    elseif ($thumbnail = $this->getField($media, 'thumbnail')) {
      return $thumbnail;
    }

    return $this->getDefaultThumbnail();
  }

  /**
   * Runs preg_match on embed code/URL.
   *
   * @param MediaInterface $media
   *   Media object.
   *
   * @return array|bool
   *   Array of preg matches or FALSE if no match.
   *
   * @see preg_match()
   */
  protected function matchRegexp(MediaInterface $media) {
    $matches = array();

    if (isset($this->configuration['source_field'])) {
      $source_field = $this->configuration['source_field'];
      if ($media->hasField($source_field)) {
        $property_name = $media->{$source_field}->first()->mainPropertyName();
        foreach (static::$validationRegexp as $pattern => $key) {
          if (preg_match($pattern, $media->{$source_field}->{$property_name}, $matches)) {
            return $matches;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Get auth settings.
   *
   * @return array
   *   Array of auth settings.
   */
  protected function getAuthSettings() {
    return array(
      'consumer_key' => $this->configuration['consumer_key'],
      'consumer_secret' => $this->configuration['consumer_secret'],
      'oauth_access_token' => $this->configuration['oauth_access_token'],
      'oauth_access_token_secret' => $this->configuration['oauth_access_token_secret'],
    );
  }

  /**
   * Get a single tweet.
   *
   * @param int $id
   *   The tweet id.
   */
  protected function fetchTweet($id) {
    $tweet = &drupal_static(__FUNCTION__);

    if (!isset($tweet)) {
      // Check for dependencies.
      // @todo There is perhaps a better way to do that.
      if (!class_exists('\TwitterAPIExchange')) {
        drupal_set_message($this->t('Twitter library is not available. Consult the README.md for installation instructions.'), 'error');
        return;
      }

      // Settings.
      $auth_settings = $this->getAuthSettings();
      $request_settings = array(
        'url' => 'https://api.twitter.com/1.1/statuses/show.json',
        'method' => 'GET',
      );
      $query = "?id=$id";

      // Get the tweet.
      $twitter = new \TwitterAPIExchange($auth_settings);
      $result = $twitter->setGetfield($query)
        ->buildOauth($request_settings['url'], $request_settings['method'])
        ->performRequest();

      if ($result) {
        return Json::decode($result);
      }
      else {
        throw new MediaTypeException(NULL, 'The tweet could not be retrived.');
      }
    }
    else {
      return $tweet;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultName(MediaInterface $media) {
    // The default name will be the twitter username of the author + the
    // tweet ID.
    $user = $this->getField($media, 'user');
    $id = $this->getField($media, 'id');
    if (!empty($user) && !empty($id)) {
      return $user . ' - ' . $id;
    }

    return parent::getDefaultName($media);
  }

}
