<?php

namespace Drupal\dynamic_image_style;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig extension with filters for dynamic image styles.
 *
 * @author Kodamera AB <info@kodamera.se>
 */
class DynamicImageStyleTwigExtension extends AbstractExtension {

  /**
   * The dynamic image style helper.
   */
  protected DynamicImageStyleHelper $dynamicImageStyleHelper;

  /**
   * The file url generator.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The default cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs the DynamicImageStyleTwigExtension object.
   */
  public function __construct(DynamicImageStyleHelper $dynamic_image_style_helper, FileUrlGeneratorInterface $file_url_generator, CacheBackendInterface $cache) {
    $this->dynamicImageStyleHelper = $dynamic_image_style_helper;
    $this->fileUrlGenerator = $file_url_generator;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter('dis', [$this, 'dynamicImageStyleFilter']),
      new TwigFilter('dynamic_image_style', [$this, 'dynamicImageStyleFilter']),
      new TwigFilter('dynamic_image_style_url', [$this, 'dynamicImageStyleUrlFilter']),
      new TwigFilter('dynamic_image_style_source', [$this, 'dynamicImageStyleSourceFilter']),
    ];
  }

  /**
   * Returns the URL of this image derivative for an original image path or URI.
   *
   * This will also create the derivative if it does not exist.
   *
   * @param string|null $path
   *   The path or URI to the original image.
   * @param string $settings_string
   *   The settings for the image style.
   *
   * @return string|null
   *   The absolute URL where a style image can be downloaded, suitable for use
   *   in an <img> tag.
   */
  public function dynamicImageStyleFilter(?string $path, string $settings_string): ?string {

    if (!$path) {
      trigger_error('Image path is empty.');
      return NULL;
    }

    // Get image style.
    $image_style = $this->dynamicImageStyleHelper->createImageStyle($settings_string);

    if (!$image_style->supportsUri($path)) {
      trigger_error(sprintf('Could not apply image style %s.', $settings_string));
      return NULL;
    }

    // Get image style uri.
    $image_style_uri = $image_style->buildUri($path);

    // Create derivative if it does not exist.
    if (!file_exists($image_style_uri)) {
      $image_style->createDerivative($path, $image_style_uri);
    }

    return $this->fileUrlGenerator->transformRelative($image_style->buildUrl($path));
  }

  /**
   * Returns the URL of this image derivative for an original image file.
   *
   * @param string|null $file_id
   *   The file ID.
   * @param string $settings
   *   The settings for the image style.
   *
   * @return string|null
   *   The absolute URL where a style image can be downloaded, suitable for use
   *   in an <img> tag. Requesting the URL will cause the image to be created.
   */
  public function dynamicImageStyleUrlFilter(?string $file_id, string $settings): ?string {
    if (!$file_id) {
      trigger_error('Image file is empty.');
      return NULL;
    }

    $url = Url::fromRoute('dynamic_image_style.deliver', [
      'file' => $file_id,
      'settings' => $settings,
    ]);

    $valid_settings = $this->getValidSettings();
    $valid_settings[$settings] = $settings;
    $this->setValidSettings($valid_settings);

    return $url->toString();
  }

  /**
   * Returns a string ready for picture srcset attribute.
   *
   * @param string|null $file_id
   *   The file ID.
   * @param string $settings
   *   The settings for the image style.
   * @param array $multipliers
   *   The multipliers to generate for.
   *
   * @return string|null
   *   A string ready for picture srcset attribute, containing both 1x and 2x
   *   versions.
   */
  public function dynamicImageStyleSourceFilter(?string $file_id, string $settings, array $multipliers = [2]): ?string {
    if (!$file_id) {
      trigger_error('Image file is empty.');
      return NULL;
    }

    $valid_settings = $this->getValidSettings();

    // Always include 1x version.
    $settings_1x = $settings . '_1x';
    $valid_settings[$settings_1x] = $settings_1x;
    $urls = [
      Url::fromRoute('dynamic_image_style.deliver', [
        'file' => $file_id,
        'settings' => $settings_1x,
      ])->toString() . ' 1x',
    ];

    foreach ($multipliers as $multiplier) {
      $settings_multiplier = $settings . '_' . $multiplier . 'x';
      $url = Url::fromRoute('dynamic_image_style.deliver', [
        'file' => $file_id,
        'settings' => $settings_multiplier,
      ])->toString();
      $urls[] = "$url {$multiplier}x";
      $valid_settings[$settings_multiplier] = $settings_multiplier;
    }

    $this->setValidSettings($valid_settings);

    return implode(', ', $urls);
  }

  /**
   * Get valid settings cache.
   *
   * To avoid DoS we store all valid settings (originating from Twig) in cache.
   */
  protected function getValidSettings(): array {
    $cache = $this->cache->get('dynamic_image_style:valid_settings');
    return $cache ? $cache->data : [];
  }

  /**
   * Set valid settings cache.
   *
   * To avoid DoS we store all valid settings (originating from Twig) in cache.
   */
  protected function setValidSettings(array $valid_settings): void {
    $this->cache->set('dynamic_image_style:valid_settings', $valid_settings);
  }

}
