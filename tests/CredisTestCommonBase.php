<?php

/**
 * Base class - compatible with PHP 7.2 / PHPUnit 8.0
 */
class CredisTestCommonBase extends \PHPUnit\Framework\TestCase
{
  // define own set of setUp/tearDown methods with unified signatures to fix PHP 7.2 / PHPUnit 8.0 compatibility issue
  protected function setUpOverride() { }
  protected function tearDownOverride() { }
  public static function setUpBeforeClassOverride() { }
  public static function tearDownAfterClassOverride() { }

  protected function setUp(): void
  {
    $this->setUpOverride(); // proxy call to method with unified signature
  }

  protected function tearDown(): void
  {
    $this->tearDownOverride(); // proxy call to method with unified signature
  }

  public static function setUpBeforeClass(): void
  {
    static::setUpBeforeClassOverride(); // proxy call to method with unified signature
  }

  public static function tearDownAfterClass(): void
  {
    static::tearDownAfterClassOverride(); // proxy call to method with unified signature
  }
}
