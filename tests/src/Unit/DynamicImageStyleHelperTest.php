<?php

namespace Drupal\Tests\dynamic_image_style\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\dynamic_image_style\DynamicImageStyleHelper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests DynamicImageStyleHelper.
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
   * Tests storing and checking valid settings.
   *
   * @covers ::addValidSettings
   * @covers ::isValidSettings
   * @covers ::getValidSettings
   */
  public function testValidSettings(): void {
    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    $helper = new DynamicImageStyleHelper($module_handler, $cache);
    $this->assertFalse($helper->isValidSettings('320w'));

    $helper->addValidSettings('320w');
    $helper->addValidSettings('640w');
    $this->assertTrue($helper->isValidSettings('320w'));
    $this->assertSame(['320w' => '320w', '640w' => '640w'], $helper->getValidSettings());

    // A new request must not rely on what this request has already checked.
    $helper = new DynamicImageStyleHelper($module_handler, $cache);
    $this->assertTrue($helper->isValidSettings('640w'));
    $this->assertFalse($helper->isValidSettings('960w'));
  }

  /**
   * Tests that a lost write to the combined list keeps settings valid.
   *
   * Two concurrent requests can both read the list, add their own settings
   * and write it back, so one of them is lost from the list.
   *
   * @covers ::addValidSettings
   * @covers ::isValidSettings
   */
  public function testValidSettingsSurviveOverwrittenList(): void {
    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    (new DynamicImageStyleHelper($module_handler, $cache))->addValidSettings('320w');
    (new DynamicImageStyleHelper($module_handler, $cache))->addValidSettings('640w');
    $cache->set(DynamicImageStyleHelper::VALID_SETTINGS_CID, ['640w' => '640w']);

    $helper = new DynamicImageStyleHelper($module_handler, $cache);
    $this->assertTrue($helper->isValidSettings('320w'));
    $this->assertTrue($helper->isValidSettings('640w'));
  }

}
