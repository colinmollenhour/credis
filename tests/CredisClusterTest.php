<?php

require_once dirname(__FILE__).'/CredisTest.php';
require_once dirname(__FILE__).'/../Cluster.php';

class CredisClusterTest extends CredisTest
{
    const portBase = 28123;
    const password = "password-for-testing";

    /**
     * @inheritDoc
     */
    protected function setUpInternal()
    {
        $this->credis = new Credis_Cluster(
            null,
            ["127.0.0.1:" . self::portBase],
            null,
            null,
            false,
            self::password,
            null,
            ['cafile' => './tls/ca.crt', 'verify_peer_name' => false]
        );
        $output = $this->credis->flushDb(false);
    }

    private static $serverProcesses = [];

    /**
     * @inheritDoc
     */
    public static function setUpBeforeClassInternal()
    {
        chdir(__DIR__.'/../');
        if (!file_exists('./tests/tls/ca.crt') || !file_exists('./tests/tls/server.crt')) {
            // generate SSL keys
            system('./tests/gen-test-certs.sh');
        }
        chdir(__DIR__);
        for ($i = 0; $i < 6; $i++) {
            $process = proc_open(
                sprintf(
                    'exec redis-server redis-cluster.conf --bind 127.0.0.1 --tls-port %d --cluster-config-file nodes.%d.conf',
                    self::portBase + $i,
                    self::portBase + $i,
                ),
                [],
                $pipes,
            );
            if (!$process) {
                throw new \Exception("redis-server start failed");
            }
            self::$serverProcesses[] = $process;
        }
        self::waitForServersUp();
        self::clusterAssemble();
    }

    private static function waitForServersUp()
    {
        for ($i = 0; $i < 6; $i++) {
            self::waitForServerUp(self::portBase + $i);
        }
    }

    private static function waitForServerUp($port)
    {
        system(sprintf(
            "timeout 30s bash -c %s",
            escapeshellarg(sprintf(
                "until (redis-cli -a %s --tls --cacert ./tls/ca.crt -h 127.0.0.1 -p %d cluster info ) ; do sleep 1; done",
                escapeshellarg(self::password),
                $port,
            ))
        ));
    }

    private static function clusterAssemble()
    {
        system(sprintf(
            "bash -c %s",
            escapeshellarg(sprintf(
                "(redis-cli -a %s --tls --cacert ./tls/ca.crt -h 127.0.0.1 -p %d cluster info |grep cluster_state:ok) || (yes yes | redis-cli -a %s --tls --cacert ./tls/ca.crt --cluster create 127.0.0.1:%d 127.0.0.1:%d 127.0.0.1:%d 127.0.0.1:%d 127.0.0.1:%d 127.0.0.1:%d --cluster-replicas 1)",
                escapeshellarg(self::password),
                self::portBase + 0,
                escapeshellarg(self::password),
                self::portBase + 0,
                self::portBase + 1,
                self::portBase + 2,
                self::portBase + 3,
                self::portBase + 4,
                self::portBase + 5,
            ))
        ));
    }

    /**
     * @inheritDoc
     */
    public static function tearDownAfterClassInternal()
    {
        while ($process = array_pop(self::$serverProcesses)) {
            proc_terminate($process);
        }
    }


    /**
     * @inheritDoc
     *
     * TODO: This test depends on CredisClusterTest::save which currently requires parameter specific redis node.
     */
    public function testReadTimeout()
    {
        $this->markTestSkipped("RedisCluster::save method incompatible with Redis::save");
    }

    /**
     * @inheritDoc
     */
    public function testSortedSets()
    {
        $this->markTestSkipped("RedisCluster::zunionstore(): All keys don't hash to the same slot!");
    }

    /**
     * @inheritDoc
     *
     * Note: copied from CredisTest::testHashes, but removed pipeline stuff since it ain't supported
     */
    public function testHashes()
    {
        $this->assertEquals(1, $this->credis->hSet('hash', 'field1', 'foo'));
        $this->assertEquals(0, $this->credis->hSet('hash', 'field1', 'foo'));
        $this->assertEquals('foo', $this->credis->hGet('hash', 'field1'));
        $this->assertEquals(null, $this->credis->hGet('hash', 'x'));
        $this->assertTrue($this->credis->hMSet('hash', array('field2' => 'Hello', 'field3' => 'World')));
        $this->assertEquals(array('field1' => 'foo', 'field2' => 'Hello', 'nilfield' => false), $this->credis->hMGet('hash', array('field1', 'field2', 'nilfield')));
        $this->assertEquals(array(), $this->credis->hGetAll('nohash'));
        $this->assertEquals(array('field1' => 'foo', 'field2' => 'Hello', 'field3' => 'World'), $this->credis->hGetAll('hash'));
        // test integer keys
        $this->assertTrue($this->credis->hMSet('hashInt', array(0 => 'Hello', 1 => 'World')));
        $this->assertEquals(array(0 => 'Hello', 1 => 'World'), $this->credis->hGetAll('hashInt'));
        // Test long hash values
        $longString = str_repeat(md5('asd'), 4096); // 128k (redis.h REDIS_INLINE_MAX_SIZE = 64k)
        $this->assertEquals(1, $this->credis->hMSet('long_hash', array('count' => 1, 'data' => $longString)), 'Set long hash value');
        $this->assertEquals($longString, $this->credis->hGet('long_hash', 'data'), 'Get long hash value');
        $this->assertTrue($this->credis->hMSet('hash', array('field1' => 'foo', 'field2' => 'Hello')));

    }

    public function testPipeline()
    {
        $this->markTestSkipped("Pipeline isn't currently supported in CredisCluster");
    }

    public function testPipelineMulti()
    {
        $this->markTestSkipped("Pipeline isn't currently supported in CredisCluster");
    }

    public function testWatchMultiUnwatch()
    {
        $this->markTestSkipped("Pipeline isn't currently supported in CredisCluster");
    }

    public function testTransaction()
    {
        $this->markTestSkipped("Pipeline isn't currently supported in CredisCluster");
    }

    public function testServer()
    {
        $this->markTestSkipped("RedisCluster::info() expects at least 1");
    }

    /**
     * @inheritDoc
     *
     * TODO: Remove this after RedisCluster::script is fixed.
     */
    public function testScripts()
    {
        $this->markTestSkipped('Bug in RedisCluster::script("load", string); returns null.');
    }

    /**
     * @inheritDoc
     *
     * TODO: Remove this after RedisCluster::pSubscribe is fixed.
     */
    public function testPubsub()
    {
        $this->markTestSkipped("Bug in RedisCluster::pSubscribe(); It shouldn't return.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own.
     */
    public function testDb()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own.
     */
    public function testPassword()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own.
     */
    public function testUsernameAndPassword()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own.
     */
    public function testGettersAndSetters()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own use different cluster configs.
     */
    public function testConnectionStrings()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own use different cluster configs.
     */
    public function testConnectionStringsTls()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     *
     * TODO: SuperClass's test is specific to Credis_Client.  We could make our own use different cluster configs.
     */
    public function testTLSConnection()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     */
    public function testConnectionStringsSocket()
    {
        $this->markTestSkipped("Not testing this for now. Does RedisCluster even support this?");
    }

    /**
     * @inheritDoc
     */
    public function testInvalidTcpConnectionString()
    {
        $this->markTestSkipped("TODO: Need to add this to verify which URIs are compatible with RedisCluster");
    }

    /**
     * @inheritDoc
     */
    public function testInvalidTlsConnectionString()
    {
        $this->markTestSkipped("TODO: Need to add this to verify which URIs are compatible with RedisCluster");
    }

    /**
     * @inheritDoc
     */
    public function testInvalidUnixSocketConnectionString()
    {
        $this->markTestSkipped("Not testing this for now.");
    }

    /**
     * @inheritDoc
     */
    public function testForceStandAloneAfterEstablishedConnection()
    {
        $this->markTestSkipped("Not supported.");
    }

    /**
     * @inheritDoc
     */
    public function testscan()
    {
        $this->markTestSkipped("RedisCluster::scan requires argument for which node to scan");
    }

    /**
     * @inheritDoc
     */
    public function testscanEmptyIterator()
    {
        $this->markTestSkipped("RedisCluster::scan requires argument for which node to scan");
    }


    public function testGetClusterNodes()
    {
        $masters = $this->credis->getClusterMasters();
        $this->assertIsArray($masters);
        $this->assertNotEmpty($masters);
    }
}
