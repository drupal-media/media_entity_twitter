<?php

namespace Drupal\media_entity_twitter;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ThumbnailGenerator extends ControllerBase {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * ThumbnailGenerator constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(RendererInterface $renderer) {
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('renderer'));
  }

  public function thumbnail(Request $request) {
    $build = [
      '#theme' => 'media_entity_twitter_tweet_thumbnail',
      '#tweet' => $request->get('tweet'),
      '#author' => $request->get('author'),
      '#avatar' => $request->get('avatar'),
    ];

    return new Response($this->renderer->render($build), 200, [
      'Content-Type' => 'image/svg+xml',
    ]);
  }

}
