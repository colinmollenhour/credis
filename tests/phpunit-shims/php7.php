<?php

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

    protected function setUp()
    {
        $this->setUpInternal();
    }
    protected function tearDown()
    {
        $this->tearDownInternal();
    }

    public static function setUpBeforeClass()
    {
        static::setUpBeforeClassInternal();
    }

    public static function tearDownAfterClass()
    {
        static::tearDownAfterClassInternal();
    }
}
