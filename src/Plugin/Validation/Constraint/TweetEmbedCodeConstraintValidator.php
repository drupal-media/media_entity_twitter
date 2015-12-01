<?php

/**
 * @file
 * Contains \Drupal\media_entity_twitter\Plugin\Validation\Constraint\TweetEmbedCodeConstraintValidator.
 */

namespace Drupal\media_entity_twitter\Plugin\Validation\Constraint;

use Drupal\media_entity_twitter\Plugin\MediaEntity\Type\Twitter;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the TweetEmbedCode constraint.
 */
class TweetEmbedCodeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
    if (!isset($entity)) {
      return;
    }

    foreach (Twitter::$validationRegexp as $pattern => $key) {
      if (preg_match($pattern, $entity->value)) {
        return;
      }
    }

    $this->context->addViolation($constraint->message);
  }

}
