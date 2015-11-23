<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Plugin\Field\FieldFormatter\TwitterEmbedFormatter.
 */

namespace Drupal\media_entity_twitter\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\media_entity_twitter\Plugin\MediaEntity\Type\Twitter;

/**
 * Plugin implementation of the 'twitter_embed' formatter.
 *
 * @FieldFormatter(
 *   id = "twitter_embed",
 *   label = @Translation("Twitter embed"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class TwitterEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = array();
    foreach ($items as $delta => $item) {
      $matches = [];
      preg_match(Twitter::VALIDATION_REGEXP, $item->uri, $matches);
      if (!empty($matches['user']) && !empty($matches['id'])) {
        $element[$delta] = [
          '#theme' => 'media_entity_twitter_tweet',
          '#path' => 'https://twitter.com/' . $matches['user'] . '/statuses/' . $matches['id'],
          '#attributes' => [
            'class' => ['twitter-tweet', 'element-hidden'],
            'data-conversation' => 'none',
            'lang' => 'en',
          ],
        ];
      }
    }

    if (!empty($element)) {
      $element['#attached'] = [
        'library' => [
          'media_entity_twitter/twitter.widget',
        ],
      ];
    }

    return $element;
  }
}
