<?php
// backward compatibility (https://stackoverflow.com/a/42828632/187780)
if (!class_exists('\PHPUnit\Framework\TestCase') && class_exists('\PHPUnit_Framework_TestCase')) {
  class_alias('\PHPUnit_Framework_TestCase', '\PHPUnit\Framework\TestCase');
}

/**
 * Base class - compatible with everything before PHP 7.2 / PHPUnit 8.0
 */
abstract class CredisTestCommonCompat extends \PHPUnit\Framework\TestCase
{
  // define own set of setUp/tearDown methods with unified signatures to fix PHP 7.2 / PHPUnit 8.0 compatibility issue
  protected function setUpOverride() { }
  protected function tearDownOverride() { }
  public static function setUpBeforeClassOverride() { }
  public static function tearDownAfterClassOverride() { }

  protected function setUp()
  {
    $this->setUpOverride(); // proxy call to method with unified signature
  }

  protected function tearDown()
  {
    $this->tearDownOverride(); // proxy call to method with unified signature
  }

  public static function setUpBeforeClass()
  {
    static::setUpBeforeClassOverride(); // proxy call to method with unified signature
  }

  public static function tearDownAfterClass()
  {
    static::tearDownAfterClassOverride(); // proxy call to method with unified signature
  }
}
