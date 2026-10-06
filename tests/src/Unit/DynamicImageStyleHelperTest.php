<?php

namespace Drupal\Tests\dynamic_image_style\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
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
      new KeyValueMemoryFactory(),
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
      'decimal ratio and width' => [
        '1.91x1r_382w',
        ['r' => '1.91x1', 'w' => 382, 'h' => 200],
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
      'decimal ratio' => ['1.91x1r_382w', TRUE],
      'decimal ratio below one' => ['0.5x1r_320w', TRUE],
      'empty' => ['', FALSE],
      'ratio only' => ['16x9r', FALSE],
      'zero width' => ['0w', FALSE],
      'zero ratio height' => ['16x0r_320w', FALSE],
      'zero decimal ratio width' => ['0.0x1r_320w', FALSE],
      'malformed decimal ratio' => ['1.x1r_320w', FALSE],
      'zero multiplier' => ['320w_0x', FALSE],
      'duplicate width' => ['320w_640w', FALSE],
      'unknown setting' => ['320q', FALSE],
      'empty part' => ['320w__2x', FALSE],
      'path traversal' => ['320w_../../etc', FALSE],
      'slash' => ['320w/2x', FALSE],
      'negative width' => ['-320w', FALSE],
    ];
  }

  /**
   * Tests storing and checking valid settings.
   *
   * @covers ::addValidSettings
   * @covers ::isAllowedSettings
   * @covers ::getValidSettings
   */
  public function testValidSettings(): void {
    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $key_value_factory = new KeyValueMemoryFactory();
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    $helper = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $this->assertFalse($helper->isAllowedSettings('320w'));

    $helper->addValidSettings('320w');
    $helper->addValidSettings('640w');
    $helper->addValidSettings('320w');
    $this->assertTrue($helper->isAllowedSettings('320w'));
    $this->assertSame(['320w', '640w'], $helper->getValidSettings());

    // A new request must not rely on what this request has already loaded,
    // and settings must survive a cache clear.
    $cache->deleteAll();
    $helper = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $this->assertTrue($helper->isAllowedSettings('640w'));
    $this->assertFalse($helper->isAllowedSettings('960w'));
  }

  /**
   * Tests that concurrent requests don't drop each other's settings.
   *
   * Both requests load the stored settings before either of them writes.
   *
   * @covers ::addValidSettings
   */
  public function testConcurrentValidSettings(): void {
    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $key_value_factory = new KeyValueMemoryFactory();
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    $first = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $second = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $first->addValidSettings('100w');
    $second->addValidSettings('100h');

    $first->addValidSettings('320w');
    $second->addValidSettings('640w');

    $helper = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $this->assertTrue($helper->isAllowedSettings('320w'));
    $this->assertTrue($helper->isAllowedSettings('640w'));
  }

  /**
   * Tests that settings stored by earlier versions are still accepted.
   *
   * @covers ::isAllowedSettings
   */
  public function testLegacyValidSettings(): void {
    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $cache->set(DynamicImageStyleHelper::LEGACY_VALID_SETTINGS_CID, ['320w' => '320w']);
    $key_value_factory = new KeyValueMemoryFactory();
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    $helper = new DynamicImageStyleHelper($module_handler, $cache, $key_value_factory);
    $this->assertTrue($helper->isAllowedSettings('320w'));
    $this->assertFalse($helper->isAllowedSettings('640w'));

    // Accepted legacy settings are moved to key value storage.
    $this->assertSame(['320w'], $helper->getValidSettings());
  }

}
