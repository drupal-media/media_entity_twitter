<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Plugin\MediaEntity\Type\Twitter.
 */

namespace Drupal\media_entity_twitter\Plugin\MediaEntity\Type;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media_entity\MediaBundleInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeException;
use Drupal\media_entity\MediaTypeInterface;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
class Twitter extends PluginBase implements MediaTypeInterface, ContainerFactoryPluginInterface {
  use StringTranslationTrait;

  /**
   * List of validation regular expressions.
   *
   * @var array
   */
  protected $validationRegexp = '@((http|https):){0,1}//(www\.){0,1}twitter\.com/(?<user>[a-z0-9_-]+)/(status(es){0,1})/(?<id>[a-z0-9_-]+)@i';

  /**
   * Plugin label.
   *
   * @var string
   */
  protected $label;

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->label;
  }

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity manager object.
   *
   * @var \Drupal\Core\Entity\EntityManager;
   */
  protected $entityManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('entity.manager')
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, EntityManager $entity_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function providedFields() {
    $fields = array(
      'id' => $this->t('Tweet ID'),
      'user' => $this->t('Twitter user information'),
    );

    if ($this->configuration['twitter']['use_twitter_api']) {
      $fields += array(
        'image' => $this->t('Link to the twitter image'),
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
    if ($this->configuration['twitter']['use_twitter_api'] && $tweet = $this->fetchTweet($matches['id'])) {
      switch ($name) {
        case 'image':
          if (isset($tweet['extended_entities']['media'][0]['media_url'])) {
            return $tweet['extended_entities']['media'][0]['media_url'];
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
      '#description' => t('Field on media entity that stores Twitter embed code or URL.'),
      '#default_value' => empty($this->configuration['twitter']['source_field']) ? NULL : $this->configuration['source_field'],
      '#options' => $options,
    );

    $form['use_twitter_api'] = array(
      '#type' => 'select',
      '#title' => t('Whether to use Twitter api to fetch tweets or not.'),
      '#description' => t("In order to use Twitter's api you have to create a developer account and an application. For more information consult the readme file."),
      '#default_value' => empty($this->configuration['twitter']['use_twitter_api']) ? 0 : $this->configuration['twitter']['use_twitter_api'],
      '#options' => array(
        0 => t('No'),
        1 => t('Yes'),
      ),
    );

    // @todo Evauate if this should be a site-wide configuration.
    $form['consumer_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer key'),
      '#default_value' => empty($this->configuration['twitter']['consumer_key']) ? NULL : $this->configuration['twitter']['consumer_key'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['consumer_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer secret'),
      '#default_value' => empty($this->configuration['twitter']['consumer_secret']) ? NULL : $this->configuration['twitter']['consumer_secret'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token'),
      '#default_value' => empty($this->configuration['twitter']['oauth_access_token']) ? NULL : $this->configuration['twitter']['oauth_access_token'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[twitter][use_twitter_api]"]' => array('value' => '1'),
        ),
      ),
    );

    $form['oauth_access_token_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token secret'),
      '#default_value' => empty($this->configuration['twitter']['oauth_access_token_secret']) ? NULL : $this->configuration['twitter']['oauth_access_token_secret'],
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
      throw new MediaTypeException($this->configuration['twitter']['source_field'], 'Not valid URL/embed code.');
    }

    // Check that the tweet is publicly visible.
    $response = $this->httpClient->get($matches[0]);
    $effective_url_parts = parse_url($response->getEffectiveUrl());

    if (!empty($effective_url_parts) && isset($effective_url_parts['query']) && $effective_url_parts['query'] == 'protected_redirect=true') {
      throw new MediaTypeException($this->configuration['twitter']['source_field'], 'The tweet is not reachable.');
    }
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
    $source_field = $this->configuration['twitter']['source_field'];

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
      'consumer_key' => $this->configuration['twitter']['consumer_key'],
      'consumer_secret' => $this->configuration['twitter']['consumer_secret'],
      'oauth_access_token' => $this->configuration['twitter']['oauth_access_token'],
      'oauth_access_token_secret' => $this->configuration['twitter']['oauth_access_token_secret'],
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
