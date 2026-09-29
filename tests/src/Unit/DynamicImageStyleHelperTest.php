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

}
