<?php

namespace Drupal\dynamic_image_style\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\dynamic_image_style\DynamicImageStyleHelper;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Dynamic image style controller.
 *
 * @author Kodamera AB <info@kodamera.se>
 */
class DynamicImageStyleController extends ControllerBase {

  /**
   * How long browsers and proxies may cache public derivatives, in seconds.
   *
   * Matches the two weeks that Drupal's .htaccess sets for static files, which
   * is how regular image style derivatives are served. Derivatives are flushed
   * when a focal point changes, so this is kept short of a year.
   */
  const MAX_AGE = 1209600;

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
   * The stream wrapper manager.
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * DynamicImageStyleController constructor.
   */
  public function __construct(DynamicImageStyleHelper $dynamic_image_style_helper, ImageFactory $image_factory, LockBackendInterface $lock, StreamWrapperManagerInterface $stream_wrapper_manager) {
    $this->dynamicImageStyleHelper = $dynamic_image_style_helper;
    $this->imageFactory = $image_factory;
    $this->lock = $lock;
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DynamicImageStyleController {
    return new static(
      $container->get('dynamic_image_style.helper'),
      $container->get('image.factory'),
      $container->get('lock'),
      $container->get('stream_wrapper_manager'),
    );
  }

  /**
   * Generate and deliver an image based on the settings provided.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\file\FileInterface $file
   *   A file entity.
   * @param string $settings
   *   The settings for the image style.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response with the image.
   */
  public function deliver(Request $request, FileInterface $file, string $settings): Response {
    // To avoid DoS attacks, we only allow image style settings generated from
    // our Twig filters, which store all used settings.
    if (!$this->dynamicImageStyleHelper->isAllowedSettings($settings)) {
      throw new BadRequestHttpException('Invalid image style settings.');
    }

    $image_style = $this->dynamicImageStyleHelper->createImageStyle($settings);

    $file_uri = $file->getFileUri();
    if (!$image_style->supportsUri($file_uri)) {
      throw new BadRequestHttpException(sprintf('Could not apply image style %s.', $settings));
    }

    // Files on non-public schemes, like private://, may only be delivered to
    // users that are allowed to download the original file. This is the same
    // check core does for image style derivatives.
    $headers = [];
    $is_public = $this->isPublicScheme($file_uri);
    if (!$is_public) {
      $headers = $this->moduleHandler()->invokeAll('file_download', [$file_uri]);
      if (in_array(-1, $headers) || empty($headers)) {
        throw new AccessDeniedHttpException();
      }
    }

    $image_style_uri = $image_style->buildUri($file_uri);

    if (!file_exists($file_uri)) {
      $this->fetchWithStageFileProxy($file_uri);
    }

    // Don't generate the derivative if it already exists, or if another
    // request is already generating it.
    $lock_name = NULL;
    if (!file_exists($image_style_uri)) {
      $lock_name = 'dynamic_image_style_deliver:' . Crypt::hashBase64($image_style_uri);
      if (!$this->lock->acquire($lock_name)) {
        throw new ServiceUnavailableHttpException(3, 'Image generation in progress. Try again shortly.');
      }
    }

    // Try to generate the derivative, unless another request just did it while
    // we were acquiring the lock.
    $success = file_exists($image_style_uri) || $image_style->createDerivative($file_uri, $image_style_uri);

    if ($lock_name) {
      $this->lock->release($lock_name);
    }

    if (!$success) {
      $this->getLogger('dynamic_image_style')->notice('Could not generate derivative %derivative from %source.', [
        '%derivative' => $image_style_uri,
        '%source' => $file_uri,
      ]);
      return new Response($this->t('Error generating image.'), 500);
    }

    $image = $this->imageFactory->get($image_style_uri);

    $headers += [
      'Content-Type' => $image->getMimeType(),
      'Content-Length' => $image->getFileSize(),
    ];

    $response = new BinaryFileResponse($image->getSource(), 200, $headers, $is_public);

    if ($is_public) {
      // Let browsers and proxies cache the derivative, so repeat views don't
      // hit Drupal at all. Setting Expires keeps Drupal from adding one in the
      // past.
      $response->setMaxAge(self::MAX_AGE);
      $response->setExpires(new \DateTime('@' . (time() + self::MAX_AGE)));
    }
    else {
      $response->setPrivate();
    }

    // Answer conditional requests with 304 Not Modified.
    $response->isNotModified($request);

    return $response;
  }

  /**
   * Checks whether a URI uses a public scheme.
   *
   * @param string $uri
   *   The file URI.
   *
   * @return bool
   *   TRUE if files on the scheme are publicly accessible.
   */
  protected function isPublicScheme(string $uri): bool {
    $scheme = $this->streamWrapperManager->getScheme($uri);
    $core_schemes = ['public', 'private', 'temporary'];
    $additional_public_schemes = array_diff(Settings::get('file_additional_public_schemes', []), $core_schemes);
    return in_array($scheme, array_merge(['public'], $additional_public_schemes), TRUE);
  }

  /**
   * Fetches a missing source file with stage_file_proxy, if available.
   *
   * Our image style implementation does not support stage_file_proxy, since
   * this URL is not a standard image style URL. Instead, we have to handle
   * fetching via stage file proxy manually. The code below is inspired by its
   * ProxySubscriber class.
   *
   * @param string $file_uri
   *   The URI of the missing source file.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the file is missing and stage_file_proxy is not available.
   */
  protected function fetchWithStageFileProxy(string $file_uri): void {
    if (!$this->moduleHandler()->moduleExists('stage_file_proxy')) {
      throw new NotFoundHttpException(sprintf('Could not find source file %s.', $file_uri));
    }

    // Stage file proxy service name changed in 3.x. We need to support both.
    if (\Drupal::hasService('stage_file_proxy.download_manager')) {
      /** @var \Drupal\stage_file_proxy\FetchManagerInterface $stage_file_proxy_fetch_manager */
      $stage_file_proxy_fetch_manager = \Drupal::service('stage_file_proxy.download_manager');
    }
    // Fall back to old service name (2.x).
    elseif (\Drupal::hasService('stage_file_proxy.fetch_manager')) {
      /** @var \Drupal\stage_file_proxy\FetchManagerInterface $stage_file_proxy_fetch_manager */
      $stage_file_proxy_fetch_manager = \Drupal::service('stage_file_proxy.fetch_manager');
    }
    else {
      throw new NotFoundHttpException(sprintf('Could not find source file %s.', $file_uri));
    }

    $stage_file_proxy_config = $this->config('stage_file_proxy.settings');

    $original_path = $stage_file_proxy_fetch_manager->styleOriginalPath($file_uri, FALSE);

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

}
