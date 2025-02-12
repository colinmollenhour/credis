<?php

require_once dirname(__FILE__).'/../Client.php';
require_once dirname(__FILE__).'/../Cluster.php';
require_once dirname(__FILE__).'/../Sentinel.php';
require_once dirname(__FILE__).'/CredisTestCommon.php';

class CredisSentinelTest extends CredisTestCommon
{
    /** @var Credis_Sentinel */
    protected $sentinel;

    protected $sentinelConfig;

    protected function setUpInternal()
    {
        parent::setUpInternal();
        if ($this->sentinelConfig === null) {
            $configFile = dirname(__FILE__).'/sentinel_config.json';
            if (! file_exists($configFile) || ! ($config = file_get_contents($configFile))) {
                $this->markTestSkipped('Could not load '.$configFile);
                return;
            }
            $this->sentinelConfig = json_decode($config);
        }

        $sentinelClient = new Credis_Client($this->sentinelConfig->host, $this->sentinelConfig->port);
        $this->sentinel = new Credis_Sentinel($sentinelClient);
        if ($this->useStandalone) {
            $this->sentinel->forceStandalone();
        }
        $this->waitForSlaveReplication();
    }

    public static function setUpBeforeClassInternal()
    {
        parent::setUpBeforeClassInternal();
        if (preg_match('/^WIN/', strtoupper(PHP_OS))) {
            echo "\tredis-server redis-sentinel.conf --sentinel".PHP_EOL.PHP_EOL;
        } else {
            sleep(2);
            chdir(__DIR__);
            copy('redis-sentinel.conf', 'redis-sentinel.conf.bak');
            exec('redis-server redis-sentinel.conf --sentinel');
            // wait for redis to initialize
            sleep(1);
        }
    }

    public static function tearDownAfterClassInternal()
    {
        parent::tearDownAfterClassInternal();
        if (preg_match('/^WIN/', strtoupper(PHP_OS))) {
            echo "Please kill all Redis instances manually:".PHP_EOL;
        } else {
            chdir(__DIR__);
            @unlink('redis-sentinel.conf');
            @copy('redis-sentinel.conf.bak', 'redis-sentinel.conf');
        }
    }

    protected function tearDownInternal()
    {
        if ($this->sentinel) {
            $this->sentinel = null;
        }
    }
    public function testMasterClient()
    {
        $master = $this->sentinel->getMasterClient($this->sentinelConfig->clustername);
        $this->assertInstanceOf('Credis_Client', $master);
        $this->assertEquals($this->redisConfig[0]['port'], $master->getPort());
        $this->setExpectedExceptionShim('CredisException', 'Master not found');
        $this->sentinel->getMasterClient('non-existing-cluster');
    }
    public function testMasters()
    {
        $masters = $this->sentinel->masters();
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($masters);
        } else {
            $this->assertInternalType('array', $masters);
        }
        $this->assertCount(2, $masters);
        $this->assertArrayHasKey(0, $masters);
        $this->assertArrayHasKey(1, $masters);
        $this->assertArrayHasKey(1, $masters[0]);
        $this->assertArrayHasKey(1, $masters[1]);
        $this->assertArrayHasKey(5, $masters[1]);
        if ($masters[0][1] == 'masterdown') {
            $this->assertEquals($this->sentinelConfig->clustername, $masters[1][1]);
            $this->assertEquals($this->redisConfig[0]['port'], $masters[1][5]);
        } else {
            $this->assertEquals('masterdown', $masters[1][1]);
            $this->assertEquals($this->sentinelConfig->clustername, $masters[0][1]);
            $this->assertEquals($this->redisConfig[0]['port'], $masters[0][5]);
        }
    }
    public function testMaster()
    {
        $master = $this->sentinel->master($this->sentinelConfig->clustername);
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($master);
        } else {
            $this->assertInternalType('array', $master);
        }
        $this->assertArrayHasKey(1, $master);
        $this->assertArrayHasKey(5, $master);
        $this->assertEquals($this->sentinelConfig->clustername, $master[1]);
        $this->assertEquals($this->redisConfig[0]['port'], $master[5]);

        $this->setExpectedExceptionShim('CredisException', 'No such master with that name');
        $this->sentinel->master('non-existing-cluster');
    }
    public function testSlaveClient()
    {
        $slaves = $this->sentinel->getSlaveClients($this->sentinelConfig->clustername);
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($slaves);
        } else {
            $this->assertInternalType('array', $slaves);
        }
        $this->assertCount(1, $slaves);
        foreach ($slaves as $slave) {
            $this->assertInstanceOf('Credis_Client', $slave);
        }
        $this->setExpectedExceptionShim('CredisException', 'No such master with that name');
        $this->sentinel->getSlaveClients('non-existing-cluster');
    }
    public function testSlaves()
    {
        $slaves = $this->sentinel->slaves($this->sentinelConfig->clustername);
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($slaves);
        } else {
            $this->assertInternalType('array', $slaves);
        }
        $this->assertCount(1, $slaves);
        $this->assertArrayHasKey(0, $slaves);
        $this->assertArrayHasKey(5, $slaves[0]);
        $this->assertEquals(6385, $slaves[0][5]);

        $slaves = $this->sentinel->slaves('masterdown');
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($slaves);
        } else {
            $this->assertInternalType('array', $slaves);
        }
        $this->assertCount(0, $slaves);

        $this->setExpectedExceptionShim('CredisException', 'No such master with that name');
        $this->sentinel->slaves('non-existing-cluster');
    }
    public function testNonExistingClusterNameWhenCreatingSlaves()
    {
        $this->setExpectedExceptionShim('CredisException', 'No such master with that name');
        $this->sentinel->createSlaveClients('non-existing-cluster');
    }

    public function testGetMasterAddressByName()
    {
        $address = $this->sentinel->getMasterAddressByName($this->sentinelConfig->clustername);
        if (method_exists($this, 'assertIsArray')) {
            $this->assertIsArray($address);
        } else {
            $this->assertInternalType('array', $address);
        }
        $this->assertCount(2, $address);
        $this->assertArrayHasKey(0, $address);
        $this->assertArrayHasKey(1, $address);
        $this->assertEquals($this->redisConfig[0]['host'], $address[0]);
        $this->assertEquals($this->redisConfig[0]['port'], $address[1]);
    }

    public function testPing()
    {
        $pong = $this->sentinel->ping();
        $this->assertEquals("PONG", $pong);
    }

    public function testGetHostAndPort()
    {
        $host = 'localhost';
        $port = '123456';

        $client = $this->createMock('\Credis_Client');
        $sentinel = new Credis_Sentinel($client);

        $client->expects($this->once())->method('getHost')->willReturn($host);
        $client->expects($this->once())->method('getPort')->willReturn($port);

        $this->assertEquals($host, $sentinel->getHost());
        $this->assertEquals($port, $sentinel->getPort());
    }
    public function testNonExistingMethod()
    {
        try {
            $this->sentinel->bla();
        } catch (CredisException $e) {
            if (strpos($e->getMessage(), 'bla') !== false) {
                if (strpos($e->getMessage(), 'unknown subcommand') !== false) {
                    $this->assertStringStartsWith('ERR unknown subcommand \'bla\'', $e->getMessage());
                } else {
                    $this->assertStringStartsWith('ERR Unknown sentinel subcommand \'bla\'', $e->getMessage());
                }
            } else {
                $this->assertStringStartsWith('ERR Unknown subcommand or wrong number of arguments', $e->getMessage());
            }
        }
    }
}
