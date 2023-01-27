<?php

/** @noinspection PhpLanguageLevelInspection */

abstract class CredisTestCommonShim extends \PHPUnit\Framework\TestCase
{
    abstract protected function setUpInternal();
    protected function tearDownInternal()
    {
    }
    public static function setUpBeforeClassInternal()
    {
    }
    public static function tearDownAfterClassInternal()
    {
    }

    protected function setUp(): void
    {
        $this->setUpInternal();
    }
    protected function tearDown(): void
    {
        $this->tearDownInternal();
    }

    public static function setUpBeforeClass(): void
    {
        static::setUpBeforeClassInternal();
    }

    public static function tearDownAfterClass(): void
    {
        static::tearDownAfterClassInternal();
    }
}
