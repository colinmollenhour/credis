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
            $tempDirectory = self::makeTemporaryDirectory();
            $process = proc_open(
                sprintf(
                    'exec redis-server redis-cluster.conf --dbfilename dump.%d.rdb --bind 127.0.0.1 --tls-port %d --cluster-config-file nodes.%d.conf --dir %s --tls-cert-file %s --tls-key-file %s --tls-ca-cert-file %s --tls-dh-params-file %s',
                    self::portBase + $i,
                    self::portBase + $i,
                    self::portBase + $i,
                    escapeshellarg($tempDirectory),
                    escapeshellarg(__DIR__ . '/tls/redis.crt'),
                    escapeshellarg(__DIR__ . '/tls/redis.key'),
                    escapeshellarg(__DIR__ . '/tls/ca.crt'),
                    escapeshellarg(__DIR__ . '/tls/redis.dh'),
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
        self::waitForClusterStateOk();
    }

    private static function makeTemporaryDirectory()
    {
        for ($i = 0; $i < 100; $i++) {
            $tempnam = tempnam(sys_get_temp_dir(), "");
            file_exists($tempnam) && unlink($tempnam);
            if (mkdir($tempnam)) {
                return $tempnam;
            }
        }
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

    private static function waitForClusterStateOk()
    {
        for ($i = 0; $i < 6; $i++) {
            self::waitForClusterStateOkPerNode(self::portBase + $i);
        }
    }

    private static function waitForClusterStateOkPerNode($port)
    {
        system(sprintf(
            "timeout 30s bash -c %s",
            escapeshellarg(sprintf(
                "until (redis-cli -a %s --tls --cacert ./tls/ca.crt -h 127.0.0.1 -p %d cluster info |grep cluster_state:ok) ; do sleep 1; done",
                escapeshellarg(self::password),
                $port,
            ))
        ));
    }

    /**
     * @inheritDoc
     */
    public static function tearDownAfterClassInternal()
    {
        foreach (self::$serverProcesses as $process) {
            proc_terminate($process);
        }
        $startTime = time();
        // Wait for each redis-server to terminate.
        foreach (self::$serverProcesses as $process) {
            do {
                $running =  !proc_get_status($process)['running'];
                if (!$running) {
                    break;
                }
            } while (time() - $startTime < 10);
            if ($running) {
                proc_terminate($process, 9); //SIGKILL if still running at this point
            }
        }
    }

    /**
     * @inheritDoc
     *
     * TODO: Find out why save isn't triggering the read timeout when in Cluster
     */
    public function testReadTimeout()
    {
        $this->markTestSkipped("RedisCluster::save is behaving differently than RedisClient in this test.");
    }

    /**
     * @inheritDoc
     *
     * Note: Copied from ClientTest.  Using Hash Tag in order to use multi-key operations in Redis Cluster.
     */
    public function testSortedSets()
    {
        $this->assertEquals(1, $this->credis->zAdd('{hashtag}myset', 1, 'Hello'));
        $this->assertEquals(1, $this->credis->zAdd('{hashtag}myset', 2.123, 'World'));
        $this->assertEquals(1, $this->credis->zAdd('{hashtag}myset', 10, 'And'));
        $this->assertEquals(1, $this->credis->zAdd('{hashtag}myset', 11, 'Goodbye'));

        $this->assertEquals(4, count($this->credis->zRange('{hashtag}myset', 0, 4)));
        $this->assertEquals(2, count($this->credis->zRange('{hashtag}myset', 0, 1)));

        $range = $this->credis->zRange('{hashtag}myset', 1, 2);
        $this->assertEquals(2, count($range));
        $this->assertEquals('World', $range[0]);
        $this->assertEquals('And', $range[1]);

        $range = $this->credis->zRange('{hashtag}myset', 1, 2, array('withscores' => true));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('World', $range));
        $this->assertEquals(2.123, $range['World']);
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);

        // withscores-option is off
        // $range = $this->credis->zRange('{hashtag}myset', 0, 4, array('withscores'));
        // $this->assertEquals(4, count($range));
        // $this->assertEquals(range(0, 3), array_keys($range)); // expecting numeric array without scores

        $range = $this->credis->zRange('{hashtag}myset', 0, 4, array('withscores' => false));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range));

        $this->assertEquals(4, count($this->credis->zRevRange('{hashtag}myset', 0, 4)));
        $this->assertEquals(2, count($this->credis->zRevRange('{hashtag}myset', 0, 1)));

        $range = $this->credis->zRevRange('{hashtag}myset', 0, 1, array('withscores' => true));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);
        $this->assertTrue(array_key_exists('Goodbye', $range));
        $this->assertEquals(11, $range['Goodbye']);

        // withscores-option is off
        $range = $this->credis->zRevRange('{hashtag}myset', 0, 4, array('withscores'));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range)); // expecting numeric array without scores

        $range = $this->credis->zRevRange('{hashtag}myset', 0, 4, array('withscores' => false));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range));

        $this->assertEquals(4, count($this->credis->zRangeByScore('{hashtag}myset', '-inf', '+inf')));
        $this->assertEquals(2, count($this->credis->zRangeByScore('{hashtag}myset', '1', '9')));

        $range = $this->credis->zRangeByScore('{hashtag}myset', '-inf', '+inf', array('limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertEquals('World', $range[0]);
        $this->assertEquals('And', $range[1]);

        $range = $this->credis->zRangeByScore('{hashtag}myset', '-inf', '+inf', array('withscores' => true, 'limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('World', $range));
        $this->assertEquals(2.123, $range['World']);
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);

        $range = $this->credis->zRangeByScore('{hashtag}myset', 10, '+inf', array('withscores' => true));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);
        $this->assertTrue(array_key_exists('Goodbye', $range));
        $this->assertEquals(11, $range['Goodbye']);

        // withscores-option is off
        // $range = $this->credis->zRangeByScore('{hashtag}myset', '-inf', '+inf', array('withscores'));
        // $this->assertEquals(4, count($range));
        // $this->assertEquals(range(0, 3), array_keys($range)); // expecting numeric array without scores

        $range = $this->credis->zRangeByScore('{hashtag}myset', '-inf', '+inf', array('withscores' => false));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range));

        $this->assertEquals(4, count($this->credis->zRevRangeByScore('{hashtag}myset', '+inf', '-inf')));
        $this->assertEquals(2, count($this->credis->zRevRangeByScore('{hashtag}myset', '9', '1')));

        $range = $this->credis->zRevRangeByScore('{hashtag}myset', '+inf', '-inf', array('limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertEquals('World', $range[1]);
        $this->assertEquals('And', $range[0]);

        $range = $this->credis->zRevRangeByScore('{hashtag}myset', '+inf', '-inf', array('withscores' => true, 'limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('World', $range));
        $this->assertEquals(2.123, $range['World']);
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);

        $range = $this->credis->zRevRangeByScore('{hashtag}myset', '+inf', 10, array('withscores' => true));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);
        $this->assertTrue(array_key_exists('Goodbye', $range));
        $this->assertEquals(11, $range['Goodbye']);

        // withscores-option is off
        // $range = $this->credis->zRevRangeByScore('{hashtag}myset', '+inf', '-inf', array('withscores'));
        // $this->assertEquals(4, count($range));
        // $this->assertEquals(range(0, 3), array_keys($range)); // expecting numeric array without scores

        $range = $this->credis->zRevRangeByScore('{hashtag}myset', '+inf', '-inf', array('withscores' => false));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range));


        // testing zunionstore (intersection of sorted sets)
        $this->credis->zAdd('{hashtag}myset1', 10, 'key1');
        $this->credis->zAdd('{hashtag}myset1', 10, 'key2');
        $this->credis->zAdd('{hashtag}myset1', 10, 'key_not_in_myset2');

        $this->credis->zAdd('{hashtag}myset2', 15, 'key1');
        $this->credis->zAdd('{hashtag}myset2', 15, 'key2');
        $this->credis->zAdd('{hashtag}myset2', 15, 'key_not_in_myset1');

        $this->credis->zUnionStore('{hashtag}myset3', array('{hashtag}myset1', '{hashtag}myset2'));
        $range = $this->credis->zRangeByScore('{hashtag}myset3', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(25, $range['key1']);
        $this->assertTrue(array_key_exists('key_not_in_myset1', $range));
        $this->assertEquals(15, $range['key_not_in_myset1']);

        // testing zunionstore AGGREGATE option
        $this->credis->zUnionStore('{hashtag}myset4', array('{hashtag}myset1', '{hashtag}myset2'), array('aggregate' => 'max'));
        $range = $this->credis->zRangeByScore('{hashtag}myset4', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(15, $range['key1']);
        $this->assertTrue(array_key_exists('key2', $range));
        $this->assertEquals(15, $range['key2']);

        // testing zunionstore WEIGHTS option
        $this->credis->zUnionStore('{hashtag}myset5', array('{hashtag}myset1', '{hashtag}myset2'), array('weights' => array(2, 4)));
        $range = $this->credis->zRangeByScore('{hashtag}myset5', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(80, $range['key1']);
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

    public function testPingForNode()
    {
        $master = $this->credis->getClusterMasters()[0];
        $pong = $this->credis->pingForNode($master);
        $this->assertEquals("PONG", $pong);
        $pong = $this->credis->pingForNode($master, "test");
        $this->assertEquals("test", $pong);
    }

    public function testFlushDbForNode()
    {
        $master = $this->credis->getClusterMasters()[0];
        $this->assertTrue($this->credis->flushDbForNode($master));
        $this->credis->set('foo', 'FOO');
        $this->assertEquals('FOO', $this->credis->get('foo'));
        $this->assertTrue($this->credis->flushDbForNode('foo'));
        $this->assertFalse($this->credis->get('foo'));
    }

    public function testFlushAllForNode()
    {
        $master = $this->credis->getClusterMasters()[0];
        $this->assertTrue($this->credis->flushAllForNode($master));
        $this->credis->set('foo', 'FOO');
        $this->assertEquals('FOO', $this->credis->get('foo'));
        $this->assertTrue($this->credis->flushAllForNode('foo'));
        $this->assertFalse($this->credis->get('foo'));
    }
}
