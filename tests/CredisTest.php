<?php

require_once dirname(__FILE__).'/../Client.php';
require_once dirname(__FILE__).'/CredisTestCommon.php';

class CredisTest extends CredisTestCommon
{

    /** @var Credis_Client */
    protected $credis;

    protected $config;

    protected $useStandalone = FALSE;

    protected function setUp()
    {
        if($this->config === NULL) {
            $configFile = dirname(__FILE__).'/redis_config.json';
            if( ! file_exists($configFile) || ! ($config = file_get_contents($configFile))) {
                $this->markTestSkipped('Could not load '.$configFile);
                return;
            }
            $this->config = json_decode($config);
            if(count($this->config) < 6) {
              $this->markTestSkipped('Config file '.$configFile.' should contain at least 6 entries');
              return;
            }
        }
        $this->credis = new Credis_Client($this->config[0]->host, $this->config[0]->port, $this->config[0]->timeout);
        if($this->useStandalone) {
            $this->credis->forceStandalone();
        } else if ( ! extension_loaded('redis')) {
            $this->fail('The Redis extension is not loaded.');
        }
        $this->credis->flushDb();
    }
    protected function tearDown()
    {
        if($this->credis) {
            $this->credis->close();
            $this->credis = NULL;
        }
    }
    public function testFlush()
    {
        $this->credis->set('foo','FOO');
        $this->assertTrue($this->credis->flushDb());
        $this->assertFalse($this->credis->get('foo'));
    }

    public function testReadTimeout()
    {
        $this->credis->setReadTimeout(0.0001);
        try {
            $this->credis->save();
            $this->fail('Expected exception (read should timeout since disk sync should take longer than 0.0001 seconds).');
        } catch(CredisException $e) {
        }
        $this->credis->setReadTimeout(10);
        $this->assertTrue(true);
    }

    public function testPHPRedisReadTimeout()
    {
        try {
            $this->credis->setReadTimeout(-1);
        } catch(CredisException $e) {
            $this->fail('setReadTimeout should accept -1 as timeout value');
        }
        try {
            $this->credis->setReadTimeout(-2);
            $this->fail('setReadTimeout should not accept values less than -1');
        } catch(CredisException $e) {
        }
        $this->assertTrue(true);
    }

    public function testScalars()
    {
        // Basic get/set
        $this->credis->set('foo','FOO');
        $this->assertEquals('FOO', $this->credis->get('foo'));
        $this->assertFalse($this->credis->get('nil'));

        // Empty string
        $this->credis->set('empty','');
        $this->assertEquals('', $this->credis->get('empty'));

        // UTF-8 characters
        $utf8str = str_repeat("quarter: ¼, micro: µ, thorn: Þ, ", 500);
        $this->credis->set('utf8',$utf8str);
        $this->assertEquals($utf8str, $this->credis->get('utf8'));

        // Array
        $this->assertTrue($this->credis->mSet(array('bar' => 'BAR', 'apple' => 'red')));
        $mGet = $this->credis->mGet(array('foo','bar','empty'));
        $this->assertTrue(in_array('FOO', $mGet));
        $this->assertTrue(in_array('BAR', $mGet));
        $this->assertTrue(in_array('', $mGet));

        // Non-array
        $mGet = $this->credis->mGet('foo','bar');
        $this->assertTrue(in_array('FOO', $mGet));
        $this->assertTrue(in_array('BAR', $mGet));

        // Delete strings, null response
        $this->assertEquals(2, $this->credis->del('foo','bar'));
        $this->assertFalse($this->credis->get('foo'));
        $this->assertFalse($this->credis->get('bar'));

        // Long string
        $longString = str_repeat(md5('asd'), 4096); // 128k (redis.h REDIS_INLINE_MAX_SIZE = 64k)
        $this->assertTrue($this->credis->set('long', $longString));
        $this->assertEquals($longString, $this->credis->get('long'));
    }

    public function testSets()
    {
        // Multiple arguments
        $this->assertEquals(2, $this->credis->sAdd('myset', 'Hello', 'World'));

        // Array Arguments
        $this->assertEquals(1, $this->credis->sAdd('myset', array('Hello','Cruel','World')));

        // Non-empty set
        $members = $this->credis->sMembers('myset');
        $this->assertEquals(3, count($members));
        $this->assertTrue(in_array('Hello', $members));

        // Empty set
        $this->assertEquals(array(), $this->credis->sMembers('noexist'));
    }

    public function testSortedSets()
    {
        $this->assertEquals(1, $this->credis->zAdd('myset', 1, 'Hello'));
        $this->assertEquals(1, $this->credis->zAdd('myset', 2.123, 'World'));
        $this->assertEquals(1, $this->credis->zAdd('myset', 10, 'And'));
        $this->assertEquals(1, $this->credis->zAdd('myset', 11, 'Goodbye'));

        $this->assertEquals(4, count($this->credis->zRangeByScore('myset', '-inf', '+inf')));
        $this->assertEquals(2, count($this->credis->zRangeByScore('myset', '1', '9')));

        $range = $this->credis->zRangeByScore('myset', '-inf', '+inf', array('limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertEquals('World', $range[0]);
        $this->assertEquals('And', $range[1]);

        $range = $this->credis->zRangeByScore('myset', '-inf', '+inf', array('withscores' => true, 'limit' => array(1, 2)));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('World', $range));
        $this->assertEquals(2.123, $range['World']);
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);

        $range = $this->credis->zRangeByScore('myset', 10, '+inf', array('withscores' => true));
        $this->assertEquals(2, count($range));
        $this->assertTrue(array_key_exists('And', $range));
        $this->assertEquals(10, $range['And']);
        $this->assertTrue(array_key_exists('Goodbye', $range));
        $this->assertEquals(11, $range['Goodbye']);

        // withscores-option is off
        $range = $this->credis->zRangeByScore('myset', '-inf', '+inf', array('withscores'));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range)); // expecting numeric array without scores

        $range = $this->credis->zRangeByScore('myset', '-inf', '+inf', array('withscores' => false));
        $this->assertEquals(4, count($range));
        $this->assertEquals(range(0, 3), array_keys($range));

        // testing zunionstore (intersection of sorted sets)
        $this->credis->zAdd('myset1', 10, 'key1');
        $this->credis->zAdd('myset1', 10, 'key2');
        $this->credis->zAdd('myset1', 10, 'key_not_in_myset2');

        $this->credis->zAdd('myset2', 15, 'key1');
        $this->credis->zAdd('myset2', 15, 'key2');
        $this->credis->zAdd('myset2', 15, 'key_not_in_myset1');

        $this->credis->zUnionStore('myset3', array('myset1', 'myset2'));
        $range = $this->credis->zRangeByScore('myset3', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(25, $range['key1']);
        $this->assertTrue(array_key_exists('key_not_in_myset1', $range));
        $this->assertEquals(15, $range['key_not_in_myset1']);

        // testing zunionstore AGGREGATE option
        $this->credis->zUnionStore('myset4', array('myset1', 'myset2'), array('aggregate' => 'max'));
        $range = $this->credis->zRangeByScore('myset4', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(15, $range['key1']);
        $this->assertTrue(array_key_exists('key2', $range));
        $this->assertEquals(15, $range['key2']);

        // testing zunionstore WEIGHTS option
        $this->credis->zUnionStore('myset5', array('myset1', 'myset2'), array('weights' => array(2, 4)));
        $range = $this->credis->zRangeByScore('myset5', '-inf', '+inf', array('withscores' => true));
        $this->assertEquals(4, count($range));
        $this->assertTrue(array_key_exists('key1', $range));
        $this->assertEquals(80, $range['key1']);
    }

    public function testHashes()
    {
        $this->assertEquals(1, $this->credis->hSet('hash','field1','foo'));
        $this->assertEquals(0, $this->credis->hSet('hash','field1','foo'));
        $this->assertEquals('foo', $this->credis->hGet('hash','field1'));
        $this->assertEquals(NULL, $this->credis->hGet('hash','x'));
        $this->assertTrue($this->credis->hMSet('hash', array('field2' => 'Hello', 'field3' => 'World')));
        $this->assertEquals(array('field1' => 'foo', 'field2' => 'Hello', 'nilfield' => FALSE), $this->credis->hMGet('hash', array('field1','field2','nilfield')));
        $this->assertEquals(array(), $this->credis->hGetAll('nohash'));
        $this->assertEquals(array('field1' => 'foo', 'field2' => 'Hello', 'field3' => 'World'), $this->credis->hGetAll('hash'));

        // test integer keys
        $this->assertTrue($this->credis->hMSet('hashInt', array(0 => 'Hello', 1 => 'World')));
        $this->assertEquals(array(0 => 'Hello', 1 => 'World'), $this->credis->hGetAll('hashInt'));

        // Test long hash values
        $longString = str_repeat(md5('asd'), 4096); // 128k (redis.h REDIS_INLINE_MAX_SIZE = 64k)
        $this->assertEquals(1, $this->credis->hMSet('long_hash', array('count' => 1, 'data' => $longString)), 'Set long hash value');
        $this->assertEquals($longString, $this->credis->hGet('long_hash', 'data'), 'Get long hash value');
    }

    public function testFalsey()
    {
        $this->assertEquals(Credis_Client::TYPE_NONE, $this->credis->type('foo'));
    }

    public function testPipeline()
    {
        $longString = str_repeat(md5('asd')."\r\n", 500);
        $reply = $this->credis->pipeline()
                ->set('a', 123)
                ->get('a')
                ->sAdd('b', 123)
                ->sMembers('b')
                ->set('empty','')
                ->get('empty')
                ->set('big', $longString)
                ->get('big')
                ->exec();
        $this->assertEquals(array(
            TRUE, 123, 1, array(123), TRUE, '', TRUE, $longString
        ), $reply);

        $this->assertEquals(array(), $this->credis->pipeline()->exec());
    }

    public function testTransaction()
    {
        $reply = $this->credis->multi()
                ->incr('foo')
                ->incr('bar')
                ->exec();
        $this->assertEquals(array(1,1), $reply);

        $reply = $this->credis->pipeline()->multi()
                ->incr('foo')
                ->incr('bar')
                ->exec();
        $this->assertEquals(array(2,2), $reply);

        $reply = $this->credis->multi()->pipeline()
                ->incr('foo')
                ->incr('bar')
                ->exec();
        $this->assertEquals(array(3,3), $reply);

        $reply = $this->credis->multi()
                ->set('a', 3)
                ->lpop('a')
                ->exec();
        $this->assertEquals(2, count($reply));
        $this->assertEquals(TRUE, $reply[0]);
        $this->assertFalse($reply[1]);
    }

    public function testServer()
    {
        $this->assertArrayHasKey('used_memory', $this->credis->info());
        $this->assertArrayHasKey('maxmemory', $this->credis->config('GET', 'maxmemory'));
    }

    public function testScripts()
    {
        $this->assertNull($this->credis->evalSha('1111111111111111111111111111111111111111'));
        $this->assertEquals(3, $this->credis->eval('return 3'));
        $this->assertEquals('09d3822de862f46d784e6a36848b4f0736dda47a', $this->credis->script('load', 'return 3'));
        $this->assertEquals(3, $this->credis->evalSha('09d3822de862f46d784e6a36848b4f0736dda47a'));

        $this->credis->set('foo','FOO');
        $this->assertEquals('FOOBAR', $this->credis->eval("return redis.call('get', KEYS[1])..ARGV[1]", 'foo', 'BAR'));

        $this->assertEquals(array(1,2,'three'), $this->credis->eval("return {1,2,'three'}"));
        try {
            $this->credis->eval('this-is-not-lua');
            $this->fail('Expected exception on invalid script.');
        } catch(CredisException $e) {
        }
    }

    public function testPubsub()
    {
        if (!$this->useStandalone && version_compare(PHP_VERSION, '7.0.0') >= 0) {
            $ext = new ReflectionExtension('redis');
            var_dump($ext->getVersion());
            if (version_compare($ext->getVersion(), '3.1.4RC1') < 0) {
                $this->fail('phpredis 3.1.4 is required for subscribe/pSubscribe not to segfault with php 7.x');
                return;
            }
        }
        $timeout = 2;
        $time = time();
        $this->credis->setReadTimeout($timeout);
        try {
            $testCase = $this;
            $this->credis->pSubscribe(array('foobar','test*'), function ($credis, $pattern, $channel, $message) use ($testCase, &$time) {
                $time = time(); // Reset timeout
                // Test using: redis-cli publish foobar blah
                $testCase->assertEquals('blah', $message);
            });
            $this->fail('pSubscribe should not return.');
        } catch (CredisException $e) {
            $this->assertEquals($timeout, time() - $time);
            if ($this->useStandalone) { // phpredis does not distinguish between timed out and disconnected
                $this->assertEquals($e->getCode(), CredisException::CODE_TIMED_OUT);
            } else {
                $this->assertEquals($e->getCode(), CredisException::CODE_DISCONNECTED);
            }
        }

        // Perform a new subscription. Client should have either unsubscribed or disconnected
        $timeout = 2;
        $time = time();
        $this->credis->setReadTimeout($timeout);
        try {
            $testCase = $this;
            $this->credis->subscribe('foobar', function ($credis, $channel, $message) use ($testCase, &$time) {
                $time = time(); // Reset timeout
                // Test using: redis-cli publish foobar blah
                $testCase->assertEquals('blah', $message);
            });
            $this->fail('subscribe should not return.');
        } catch (CredisException $e) {
            $this->assertEquals($timeout, time() - $time);
            if ($this->useStandalone) { // phpredis does not distinguish between timed out and disconnected
                $this->assertEquals($e->getCode(), CredisException::CODE_TIMED_OUT);
            } else {
                $this->assertEquals($e->getCode(), CredisException::CODE_DISCONNECTED);
            }
        }
    }
  public function testDb()
  {
      $this->tearDown();
      $this->credis = new Credis_Client($this->config[0]->host, $this->config[0]->port, $this->config[0]->timeout,false,1);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertTrue($this->credis->set('database',1));
      $this->credis->close();
      $this->credis = new Credis_Client($this->config[0]->host, $this->config[0]->port, $this->config[0]->timeout,false,0);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertFalse($this->credis->get('database'));
      $this->credis = new Credis_Client($this->config[0]->host, $this->config[0]->port, $this->config[0]->timeout,false,1);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertEquals(1,$this->credis->get('database'));
  }

  /**
   * @group Auth
   */
  public function testPassword()
  {
      $this->tearDown();
      $this->assertObjectHasAttribute('password',$this->config[4]);
      $this->credis = new Credis_Client($this->config[4]->host, $this->config[4]->port, $this->config[4]->timeout,false,0,$this->config[4]->password);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertInstanceOf('Credis_Client',$this->credis->connect());
      $this->assertTrue($this->credis->set('key','value'));
      $this->credis->close();
      $this->credis = new Credis_Client($this->config[4]->host, $this->config[4]->port, $this->config[4]->timeout,false,0,'wrongpassword');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      try
      {
          $this->credis->connect();
          $this->fail('connect should fail with wrong password');
      }
      catch(CredisException $e)
      {
          $this->assertStringStartsWith('ERR invalid password', $e->getMessage());
          $this->credis->close();
      }
      $this->credis = new Credis_Client($this->config[4]->host, $this->config[4]->port, $this->config[4]->timeout,false,0);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      try
      {
          $this->credis->set('key', 'value');
      }
      catch(CredisException $e)
      {
          $this->assertStringStartsWith('NOAUTH Authentication required', $e->getMessage());

      }
      try
      {
          $this->credis->auth('anotherwrongpassword');
      }
      catch(CredisException $e)
      {
          $this->assertStringStartsWith('ERR invalid password', $e->getMessage());
      }
      $this->assertTrue($this->credis->auth('thepassword'));
      $this->assertTrue($this->credis->set('key','value'));
  }

  public function testGettersAndSetters()
  {
      $this->assertEquals($this->credis->getHost(),$this->config[0]->host);
      $this->assertEquals($this->credis->getPort(),$this->config[0]->port);
      $this->assertEquals($this->credis->getSelectedDb(),0);
      $this->assertTrue($this->credis->select(2));
      $this->assertEquals($this->credis->getSelectedDb(),2);
      $this->assertTrue($this->credis->isConnected());
      $this->credis->close();
      $this->assertFalse($this->credis->isConnected());
      $this->credis = new Credis_Client($this->config[0]->host,$this->config[0]->port,null,'persistenceId');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertEquals('persistenceId',$this->credis->getPersistence());
      $this->credis = new Credis_Client('localhost', 12345);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->credis->setMaxConnectRetries(1);
      $this->expectException('CredisException','Connection to Redis localhost:12345 failed after 2 failures.');
      $this->credis->connect();
  }

  public function testConnectionStrings()
  {
      $this->credis->close();
      $this->credis = new Credis_Client('tcp://'.$this->config[0]->host.':'.$this->config[0]->port);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertEquals($this->credis->getHost(),$this->config[0]->host);
      $this->assertEquals($this->credis->getPort(),$this->config[0]->port);
      $this->credis = new Credis_Client('tcp://'.$this->config[0]->host);
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertEquals($this->credis->getPort(),$this->config[0]->port);
      $this->credis = new Credis_Client('tcp://'.$this->config[0]->host.':'.$this->config[0]->port.'/abc123');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->assertEquals('abc123',$this->credis->getPersistence());
  }

  /**
   * @group UnixSocket
   */
  public function testConnectionStringsSocket()
  {
      $this->credis = new Credis_Client(realpath(__DIR__).'/redis.sock',0,null,'persistent');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
      $this->credis->connect();
      $this->credis->set('key','value');
      $this->assertEquals('value',$this->credis->get('key'));
  }

  public function testInvalidTcpConnectionstring()
  {
      $this->credis->close();
      $this->expectException('CredisException','Invalid host format; expected tcp://host[:port][/persistence_identifier]');
      $this->credis = new Credis_Client('tcp://'.$this->config[0]->host.':abc');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
  }

  public function testInvalidUnixSocketConnectionstring()
  {
      $this->credis->close();
      $this->expectException('CredisException','Invalid unix socket format; expected unix:///path/to/redis.sock');
      $this->credis = new Credis_Client('unix://path/to/redis.sock');
      if ($this->useStandalone) {
          $this->credis->forceStandalone();
      }
  }

  public function testForceStandAloneAfterEstablishedConnection()
  {
      $this->credis->connect();
      if ( ! $this->useStandalone) {
          $this->expectException('CredisException','Cannot force Credis_Client to use standalone PHP driver after a connection has already been established.');
      }
      $this->credis->forceStandalone();
      $this->assertTrue(true);
  }
  public function testHscan()
  {
      $this->credis->hmset('hash',array('name' => 'Jack','age' =>33));
      $iterator = null;
      $result = $this->credis->hscan($iterator,'hash','n*',10);
      $this->assertEquals($iterator,0);
      $this->assertEquals($result,['name'=>'Jack']);
	}
    public function testSscan()
    {
        $this->credis->sadd('set','name','Jack');
        $this->credis->sadd('set','age','33');
        $iterator = null;
        $result = $this->credis->sscan($iterator,'set','n*',10);
        $this->assertEquals($iterator,0);
        $this->assertEquals($result,[0=>'name']);
    }
    public function testZscan()
    {
        $this->credis->zadd('sortedset',0,'name');
        $this->credis->zadd('sortedset',1,'age');
        $iterator = null;
        $result = $this->credis->zscan($iterator,'sortedset','n*',10);
        $this->assertEquals($iterator,0);
        $this->assertEquals($result,['name'=>'0']);
    }
    public function testscan()
    {
        $this->credis->set('name','Jack');
        $this->credis->set('age','33');
        $iterator = null;
        $result = $this->credis->scan($iterator,'n*',10);
        $this->assertEquals($iterator,0);
        $this->assertEquals($result,['name']);
    }
}
