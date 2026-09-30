<?php

namespace Drupal\dynamic_image_style\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\dynamic_image_style\DynamicImageStyleHelper;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Dynamic image style controller.
 *
 * @author Kodamera AB <info@kodamera.se>
 */
class DynamicImageStyleController extends ControllerBase {

  /**
   * How long browsers and proxies may cache public images, in seconds.
   *
   * The URL only contains the file ID and the settings, so it stays the same
   * when a focal point changes. Keep this short enough for such changes to
   * show up within reasonable time.
   */
  const MAX_AGE = 604800;

  /**
   * The dynamic image style helper.
   *
   * @var \Drupal\dynamic_image_style\DynamicImageStyleHelper
   */
  protected DynamicImageStyleHelper $dynamicImageStyleHelper;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected ImageFactory $imageFactory;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lock;

  /**
   * DynamicImageStyleController constructor.
   */
  public function __construct(DynamicImageStyleHelper $dynamic_image_style_helper, ImageFactory $image_factory, LockBackendInterface $lock) {
    $this->dynamicImageStyleHelper = $dynamic_image_style_helper;
    $this->imageFactory = $image_factory;
    $this->lock = $lock;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DynamicImageStyleController {
    return new static(
      $container->get('dynamic_image_style.helper'),
      $container->get('image.factory'),
      $container->get('lock'),
    );
  }

  /**
   * Generate and deliver an image based on the settings provided.
   *
   * @param \Drupal\file\FileInterface $file
   *   A file entity.
   * @param string $settings
   *   The settings for the image style.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The response with the image.
   */
  public function deliver(FileInterface $file, string $settings): BinaryFileResponse {
    // To avoid DoS attacks, we only allow image style settings generated from
    // our Twig filters. The filters store all used settings in the cache. So if
    // it's not in the cache, it's not valid.
    if (!$this->dynamicImageStyleHelper->isValidSettings($settings)) {
      throw new BadRequestHttpException('Invalid image style settings.');
    }

    $image_style = $this->dynamicImageStyleHelper->createImageStyle($settings);

    if (!$image_style->supportsUri($file->getFileUri())) {
      throw new BadRequestHttpException(sprintf('Could not apply image style %s.', $settings));
    }

    $image_style_uri = $image_style->buildUri($file->getFileUri());

    if (!file_exists($file->getFileUri())) {
      if (\Drupal::moduleHandler()->moduleExists('stage_file_proxy')) {
        // Our image style implementation does not support stage_file_proxy,
        // since this URL is not a standard image style URL. Instead, we have to
        // handle fetching via stage file proxy manually.
        // The code below is inspired by its ProxySubscriber class.

        // Stage file proxy service name changed in 3.x. We need to support
        // both.
        if (\Drupal::hasService('stage_file_proxy.download_manager')) {
          /** @var \Drupal\stage_file_proxy\FetchManagerInterface $stage_file_proxy_fetch_manager */
          $stage_file_proxy_fetch_manager = \Drupal::service('stage_file_proxy.download_manager');
        }
        // Fall back to old service name (2.x).
        elseif (\Drupal::hasService('stage_file_proxy.fetch_manager')) {
          /** @var \Drupal\stage_file_proxy\FetchManagerInterface $stage_file_proxy_fetch_manager */
          $stage_file_proxy_fetch_manager = \Drupal::service('stage_file_proxy.fetch_manager');
        }

        $stage_file_proxy_config = \Drupal::config('stage_file_proxy.settings');

        $original_path = $stage_file_proxy_fetch_manager->styleOriginalPath($file->getFileUri(), FALSE);

        $file_dir = $stage_file_proxy_fetch_manager->filePublicPath();

        $remote_file_dir = trim($stage_file_proxy_config->get('origin_dir'));
        if (!$remote_file_dir) {
          $remote_file_dir = $file_dir;
        }

        $options = [
          'verify' => $stage_file_proxy_config->get('verify'),
        ];

        $fetch_path = StreamWrapperManager::getTarget($original_path);
        $stage_file_proxy_fetch_manager->fetch($stage_file_proxy_config->get('origin'), $remote_file_dir, $fetch_path, $options);
      }
      else {
        throw new NotFoundHttpException(sprintf('Could not find source file %s.', $file->getFileUri()));
      }
    }

    if (!file_exists($image_style_uri)) {
      // Don't let concurrent requests generate the same derivative. The lock
      // name follows core's ImageStyleDownloadController.
      $lock_name = 'dynamic_image_style_deliver:' . $image_style->id() . ':' . Crypt::hashBase64($file->getFileUri());
      if (!$this->lock->acquire($lock_name)) {
        throw new ServiceUnavailableHttpException(3, 'Image generation in progress. Try again shortly.');
      }
      $success = file_exists($image_style_uri) || $image_style->createDerivative($file->getFileUri(), $image_style_uri);
      $this->lock->release($lock_name);

      if (!$success) {
        throw new HttpException(500, sprintf('Could not generate image style %s.', $settings));
      }
    }

    $image = $this->imageFactory->get($image_style_uri);

    $headers = [
      'Content-Type' => $image->getMimeType(),
      'Content-Length' => $image->getFileSize(),
    ];

    $response = new BinaryFileResponse($image->getSource(), 200, $headers, FALSE);

    if (StreamWrapperManager::getScheme($file->getFileUri()) === 'private') {
      $response->setPrivate();
    }
    else {
      $response->setPublic();
      $response->setMaxAge(self::MAX_AGE);
      // Drupal adds an Expires header in the past to responses without one,
      // which makes browsers revalidate on every page view.
      $response->setExpires(new \DateTime('@' . (time() + self::MAX_AGE)));
    }

    return $response;
  }

}
