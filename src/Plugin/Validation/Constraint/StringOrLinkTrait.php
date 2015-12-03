<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Plugin\Validation\Constraint\StringOrLinkTrait.
 */

namespace Drupal\media_entity_twitter\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemInterface;

trait StringOrLinkTrait {

  /**
   * Extracts the raw value from the validator input.
   *
   * @param mixed $value
   *   The input value. Can be a normal string, or a value wrapped by the
   *   Typed Data API.
   *
   * @return string|null
   */
  protected function getValue($value) {
    if (is_string($value)) {
      return $value;
    }
    elseif ($value instanceof FieldItemInterface) {
      switch ($value->getFieldDefinition()->getType()) {
        case 'link':
          return $value->uri;
        case 'string':
        case 'string_long':
          return $value->value;
        default:
          break;
      }
    }
  }

}
