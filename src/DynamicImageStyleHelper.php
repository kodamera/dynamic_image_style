<?php

namespace Drupal\dynamic_image_style;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;

/**
 * Helper for dynamic generation of image styles.
 *
 * @author Kodamera AB <info@kodamera.se>
 */
class DynamicImageStyleHelper {

  /**
   * The module handler service.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The default cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs the DynamicImageStyleHelper object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache) {
    $this->moduleHandler = $module_handler;
    $this->cache = $cache;
  }

  /**
   * Creates an image style based on settings string.
   *
   * @param string $settings_string
   *   The string with image style settings.
   * @param bool $save
   *   True if the image style should be saved, else false.
   *
   * @return \Drupal\image\ImageStyleInterface
   *   The generated image style.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createImageStyle(string $settings_string, bool $save = FALSE): ImageStyleInterface {
    // Generate the machine name based on settings string.
    $machine_name = 'dynamic_' . $settings_string;

    // Create image style.
    $image_style = ImageStyle::create([
      'name' => $machine_name,
      'label' => $machine_name,
    ]);

    $settings = $this->parseSettings($settings_string);

    $use_focal_point = $this->moduleHandler->moduleExists('focal_point');

    // Determine effect.
    if (array_key_exists('w', $settings) && array_key_exists('h', $settings)) {
      // Scale and crop.
      $image_style->addImageEffect([
        'id' => $use_focal_point ? 'focal_point_scale_and_crop' : 'image_scale_and_crop',
        'data' => [
          'width' => $settings['w'],
          'height' => $settings['h'],
          'anchor' => 'center-center',
          'weight' => 1,
        ],
      ]);
    }
    elseif (array_key_exists('w', $settings)) {
      // Scale with width.
      $image_style->addImageEffect([
        'id' => 'image_scale',
        'data' => [
          'width' => $settings['w'],
          'upscale' => TRUE,
          'weight' => 1,
        ],
      ]);
    }
    elseif (array_key_exists('h', $settings)) {
      // Scale with height.
      $image_style->addImageEffect([
        'id' => 'image_scale',
        'data' => [
          'height' => $settings['h'],
          'upscale' => TRUE,
          'weight' => 1,
        ],
      ]);
    }

    // Convert to WebP.
    $image_style->addImageEffect([
      'id' => 'image_convert',
      'data' => [
        'extension' => 'webp',
      ],
      'weight' => 10,
    ]);

    if ($save) {
      // Save the image style.
      $image_style->save();
    }

    return $image_style;
  }

  /**
   * Converts a settings string to an array.
   *
   * @param string $settings_string
   *   The settings string.
   *
   * @return array
   *   The settings array.
   */
  protected function parseSettings(string $settings_string): array {
    $parts = explode('_', $settings_string);

    $settings = [];

    foreach ($parts as $part) {
      $key = substr($part, -1);
      $value = substr($part, 0, -1);
      $settings[$key] = $value;
    }

    // Set width or height if aspect ratio is available.
    if (array_key_exists('r', $settings)) {
      $ratio = $settings['r'];
      $ratio_parts = explode('x', $ratio);
      // Width divided by height.
      $ratio_multiplier = $ratio_parts[0] / $ratio_parts[1];

      // Width is set but not height, calculate height.
      if (array_key_exists('w', $settings) && !array_key_exists('h', $settings)) {
        $settings['h'] = $settings['w'] / $ratio_multiplier;
      }

      // Height is set but not width, calculate width.
      if (array_key_exists('h', $settings) && !array_key_exists('w', $settings)) {
        $settings['w'] = $settings['h'] * $ratio_multiplier;
      }
    }

    // Multiply width and height if multipler is set.
    if (array_key_exists('x', $settings)) {
      $multiplier = $settings['x'];
      $settings['w'] *= $multiplier;
      if (isset($settings['h'])) {
        $settings['h'] *= $multiplier;
      }
    }

    // Raise number to the closest int.
    if (isset($settings['w'])) {
      $settings['w'] = ceil($settings['w']);
    }

    // Raise number to the closest int.
    if (isset($settings['h'])) {
      $settings['h'] = ceil($settings['h']);
    }

    return $settings;
  }

  /**
   * Manually store a settings string in the "valid settings" cache.
   *
   * @param string $settings
   *   The settings string.
   */
  public function addValidSettings(string $settings): void {
    $cache = $this->cache->get('dynamic_image_style:valid_settings');

    $valid_settings = $cache ? $cache->data : [];
    $valid_settings[$settings] = $settings;

    $this->cache->set('dynamic_image_style:valid_settings', $valid_settings);
  }

}
