<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Plugin\MediaEntity\Type\Twitter.
 */

namespace Drupal\media_entity_twitter\Plugin\MediaEntity\Type;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityManager;
use Drupal\media_entity\MediaBundleInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeBase;
use Drupal\media_entity\MediaTypeException;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\Client;
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
   * List of validation regular expressions.
   *
   * @var array
   */
  protected $validationRegexp = '@((http|https):){0,1}//(www\.){0,1}twitter\.com/(?<user>[a-z0-9_-]+)/(status(es){0,1})/(?<id>[a-z0-9_-]+)@i';

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('entity.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * Constructs a new class instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\Client $http_client
   *   HTTP client.
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   Entity manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $http_client, EntityManager $entity_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_manager, $config_factory->get('media_entity.settings'));
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
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
            $local_uri = $this->configFactory->get('media_entity_twitter.settings')->get('local_images') . '/' . $matches['id'] . '.' . pathinfo($tweet['extended_entities']['media'][0]['media_url'], PATHINFO_EXTENSION);

            if (!file_exists($local_uri)) {
              file_prepare_directory($local_uri, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

              $image = file_get_contents($tweet['extended_entities']['media'][0]['media_url']);
              file_unmanaged_save_data($image, $local_uri, FILE_EXISTS_REPLACE);

              return $local_uri;
            }
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
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(MediaBundleInterface $bundle) {
    $form = array();

    $options = array();
    $allowed_field_types = array('string', 'string_long', 'link');
    foreach ($this->entityManager->getFieldDefinitions('media', $bundle->id()) as $field_name => $field) {
      if (in_array($field->getType(), $allowed_field_types) && !$field->getFieldStorageDefinition()->isBaseField()) {
        $options[$field_name] = $field->getLabel();
      }
    }

    $form['source_field'] = array(
      '#type' => 'select',
      '#title' => t('Field with source information'),
      '#description' => t('Field on media entity that stores Twitter embed code or URL. You can create a bundle without selecting a value for this dropdown initially. This dropdown can be populated after adding fields to the bundle.'),
      '#default_value' => empty($this->configuration['source_field']) ? NULL : $this->configuration['source_field'],
      '#options' => $options,
    );

    $form['use_twitter_api'] = array(
      '#type' => 'select',
      '#title' => t('Whether to use Twitter api to fetch tweets or not.'),
      '#description' => t("In order to use Twitter's api you have to create a developer account and an application. For more information consult the readme file."),
      '#default_value' => empty($this->configuration['use_twitter_api']) ? 0 : $this->configuration['use_twitter_api'],
      '#options' => array(
        0 => t('No'),
        1 => t('Yes'),
      ),
    );

    // @todo Evauate if this should be a site-wide configuration.
    $form['consumer_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer key'),
      '#default_value' => empty($this->configuration['consumer_key']) ? NULL : $this->configuration['consumer_key'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['consumer_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer secret'),
      '#default_value' => empty($this->configuration['consumer_secret']) ? NULL : $this->configuration['consumer_secret'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token'),
      '#default_value' => empty($this->configuration['oauth_access_token']) ? NULL : $this->configuration['oauth_access_token'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token secret'),
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
  public function validate(MediaInterface $media) {
    $matches = $this->matchRegexp($media);

    // Validate regex.
    if (!$matches) {
      throw new MediaTypeException($this->configuration['source_field'], 'Not valid URL/embed code.');
    }

    // Check that the tweet is publicly visible.
    $response = $this->httpClient->request('get', $matches[0], ['allow_redirects' => FALSE]);

    if ($response->getStatusCode() == 302 && ($location = $response->getHeader('location'))) {
      $effective_url_parts = parse_url($location[0]);
      if (!empty($effective_url_parts) && isset($effective_url_parts['query']) && $effective_url_parts['query'] == 'protected_redirect=true') {
        throw new MediaTypeException($this->configuration['source_field'], 'The tweet is not reachable.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function thumbnail(MediaInterface $media) {
    if ($local_image = $this->getField($media, 'local_image')) {
      return $local_image;
    }

    return $this->config->get('icon_base') . '/twitter.png';
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
    $source_field = $this->configuration['source_field'];

    $property_name = $media->{$source_field}->first()->mainPropertyName();
    if (preg_match($this->validationRegexp, $media->{$source_field}->{$property_name}, $matches)) {
      return $matches;
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
        drupal_set_message(t('Twitter library is not available. Consult the README.md for installation instructions.'), 'error');
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

}
