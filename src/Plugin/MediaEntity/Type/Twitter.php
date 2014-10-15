<?php

/**
 * Contains \Drupal\media_entity_twitter\Plugin\MediaEntity\Type\Twitter.
 */

namespace Drupal\media_entity_twitter\Plugin\MediaEntity\Type;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media_entity\MediaBundleInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeException;
use Drupal\media_entity\MediaTypeInterface;
use Drupal\Component\Serialization\Json;

/**
 * Provides media type plugin for Twitter.
 *
 * @MediaType(
 *   id = "twitter",
 *   label = @Translation("Twitter"),
 *   description = @Translation("Provides business logic and metadata for Twitter.")
 * )
 */
class Twitter extends PluginBase implements MediaTypeInterface {
  use StringTranslationTrait;

  /**
   * List of validation regular expressions.
   *
   * @var array
   */
  protected $validationRegexp = array(
    '@((http|https):){0,1}//(www\.){0,1}twitter\.com/(?<user>[a-z0-9_-]+)/(status(es){0,1})/(?<id>[a-z0-9_-]+)@i'
  );

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
   * {@inheritdoc}
   */
  public function providedFields() {
    return array(
      'id' => $this->t('Tweet ID'),
      'thumbnail' => $this->t('Locally stored twitter image thumbnail'),
      'user' => $this->t('Twitter user information'),
      'content' => $this->t('This tweet content'),
      'retweet_count' => $this->t('Retweet count for this tweet'),
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getField(MediaInterface $media, $name) {
    $matches = $this->matchRegexp($media);

    if (!$matches) {
      return FALSE;
    }

    // First we return the fields that are available from regex.
    switch ($name) {
      case 'id':
        if ($matches['id']) {
          return $matches['id'];
        }
        return FALSE;

      case 'user':
        if ($matches['user']) {
          return $matches['user'];
        }
        return FALSE;
    }

    // If we have auth settings return the other fields.
    if ($this->configuration['twitter']['use_twitter_api'] && !empty($matches['id']) && $tweet = $this->fetchTweet($matches['id'])) {
      switch ($name) {
        case 'thumbnail':
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
    foreach (\Drupal::entityManager()->getFieldDefinitions('media', $bundle->id()) as $field_name => $field) {
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

    // @todo Probably this should be moved elsewhere.
    $form['consumer_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer key'),
      '#default_value' => empty($this->configuration['twitter']['consumer_key']) ? NULL : $this->configuration['twitter']['consumer_key'],
    );

    $form['consumer_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Consumer secret'),
      '#default_value' => empty($this->configuration['twitter']['consumer_secret']) ? NULL : $this->configuration['twitter']['consumer_secret'],
    );

    $form['oauth_access_token'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token'),
      '#default_value' => empty($this->configuration['twitter']['oauth_access_token']) ? NULL : $this->configuration['twitter']['oauth_access_token'],
    );

    $form['oauth_access_token_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Oauth access token secret'),
      '#default_value' => empty($this->configuration['twitter']['oauth_access_token_secret']) ? NULL : $this->configuration['twitter']['oauth_access_token_secret'],
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
    if ($matches[0]) {
      $response = \Drupal::httpClient()->get($matches[0]);
      $effective_url_parts = parse_url($response->getEffectiveUrl());
      if (!empty($effective_url_parts) && isset($effective_url_parts['query']) && $effective_url_parts['query'] == 'protected_redirect=true') {
        throw new MediaTypeException($this->configuration['twitter']['source_field'], 'The tweet is not reachable.');
      }
    }
    else {
      throw new MediaTypeException($this->configuration['twitter']['source_field'], 'Tweet url not found.');
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

    foreach ($this->validationRegexp as $regexp) {
      $property_name = $media->{$source_field}->first()->mainPropertyName();
      if (preg_match($regexp, $media->{$source_field}->{$property_name}, $matches)) {
        return $matches;
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
    $tweets = &drupal_static(__FUNCTION__);

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
