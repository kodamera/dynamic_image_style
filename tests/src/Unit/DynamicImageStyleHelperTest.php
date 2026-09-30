<?php

namespace Drupal\Tests\dynamic_image_style\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\dynamic_image_style\DynamicImageStyleHelper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the settings string parsing in DynamicImageStyleHelper.
 *
 * @coversDefaultClass \Drupal\dynamic_image_style\DynamicImageStyleHelper
 * @group dynamic_image_style
 */
class DynamicImageStyleHelperTest extends UnitTestCase {

  /**
   * Tests parsing of settings strings.
   *
   * @covers ::parseSettings
   * @dataProvider providerParseSettings
   */
  public function testParseSettings(string $settings_string, array $expected): void {
    $helper = new DynamicImageStyleHelper(
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(CacheBackendInterface::class),
    );

    $method = new \ReflectionMethod($helper, 'parseSettings');
    $method->setAccessible(TRUE);
    $settings = $method->invoke($helper, $settings_string);

    $this->assertEquals($expected, $settings);
  }

  /**
   * Data provider for testParseSettings().
   */
  public static function providerParseSettings(): array {
    return [
      'height only' => ['128h', ['h' => 128]],
      'height only, 1x' => ['128h_1x', ['h' => 128, 'x' => 1]],
      'height only, 2x' => ['128h_2x', ['h' => 256, 'x' => 2]],
      'ratio and width, 2x' => [
        '4x3r_320w_2x',
        ['r' => '4x3', 'w' => 640, 'h' => 480, 'x' => 2],
      ],
    ];
  }

  /**
   * Tests validation of settings strings.
   *
   * @covers ::isValidSettingsString
   * @dataProvider providerIsValidSettingsString
   */
  public function testIsValidSettingsString(string $settings_string, bool $expected): void {
    $this->assertSame($expected, DynamicImageStyleHelper::isValidSettingsString($settings_string));
  }

  /**
   * Data provider for testIsValidSettingsString().
   */
  public static function providerIsValidSettingsString(): array {
    return [
      'width' => ['320w', TRUE],
      'height' => ['128h', TRUE],
      'width and height' => ['320w_240h', TRUE],
      'ratio and width' => ['16x9r_320w', TRUE],
      'ratio, width and multiplier' => ['4x3r_320w_2x', TRUE],
      'decimal multiplier' => ['320w_1.5x', TRUE],
      'empty' => ['', FALSE],
      'ratio only' => ['16x9r', FALSE],
      'zero width' => ['0w', FALSE],
      'zero ratio height' => ['16x0r_320w', FALSE],
      'zero multiplier' => ['320w_0x', FALSE],
      'duplicate width' => ['320w_640w', FALSE],
      'unknown setting' => ['320q', FALSE],
      'empty part' => ['320w__2x', FALSE],
      'path traversal' => ['320w_../../etc', FALSE],
      'slash' => ['320w/2x', FALSE],
      'negative width' => ['-320w', FALSE],
    ];
  }

}
