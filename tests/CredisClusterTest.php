<?php

require_once dirname(__FILE__).'/CredisTest.php';
require_once dirname(__FILE__).'/../Cluster.php';

class CredisClusterTest extends CredisTest
{
    /**
     * @inheritDoc
     */
    protected function setUpInternal()
    {
        $this->credis = new Credis_Cluster(
            null,
            [getenv('REDIS_NODE_1_SEED')],
            null,
            null,
            false,
            getenv('REDIS_PASSWORD'),
            null,
            ['cafile' => '/certs/server.cert', 'verify_peer_name' => false]
        );
        $this->credis->flushDb('redis-node-1', 6379);
        $this->credis->flushDb('redis-node-2', 6379);
        $this->credis->flushDb('redis-node-3', 6379);
        $this->credis->flushDb('redis-node-4', 6379);
        $this->credis->flushDb('redis-node-5', 6379);
        $this->credis->flushDb('redis-node-6', 6379);
    }

    /**
     * @inheritDoc
     */
    public static function setUpBeforeClassInternal()
    {
    }

    /**
     * @inheritDoc
     */
    public static function tearDownAfterClassInternal()
    {
    }

    /**
     * @inheritDoc
     *
     * TODO: After CredisClusterTest::flushDb is implemented, update this test and make sure to check various keys
     * so that more than just 1 node is checked.
     */
    public function testFlush()
    {
        $this->markTestSkipped("RedisCluster::flushDb method incompatible with Redis::flushdb");
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

    /**
     * @inheritDoc
     */
    public function testPing()
    {
        $this->markTestSkipped("RedisCluster::ping requires argument for which node to scan");
    }
}
