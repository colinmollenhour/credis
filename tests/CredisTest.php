<?php

require_once dirname(__FILE__).'/../Client.php';

class CredisTest extends PHPUnit_Framework_TestCase
{

  /** @var Credis_Client */
  protected $credis;

  protected $config;

  protected $useStandalone = FALSE;

  protected function setUp()
  {
    if($this->config === NULL) {
      $configFile = dirname(__FILE__).'/test_config.json';
      if( ! file_exists($configFile) || ! ($config = file_get_contents($configFile))) {
        $this->markTestSkipped('Could not load '.$configFile);
        return;
      }
      $this->config = json_decode($config);
    }
    $this->credis = new Credis_Client($this->config->host, $this->config->port, $this->config->timeout);
    if($this->useStandalone) {
      $this->credis->forceStandalone();
    } else if ( ! extension_loaded('redis')) {
      $this->fail('The Redis extension is not loaded.');
    }
  }

  protected function tearDown()
  {
    if($this->credis) {
      $this->credis->flushDb();
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
  }

  public function testPHPRedisReadTimeout() {
    try {
      $this->credis->forceStandalone = false;
      $this->credis->setReadTimeout(-1);
    } catch(CredisException $e) {
      $this->fail('setReadTimeout should accept -1 as timeout value');
    }
    try {
      $this->credis->setReadTimeout(-2);
      $this->fail('setReadTimeout should not accept values less than -1');
    } catch(CredisException $e) {
    }

    $this->credis->setReadTimeout(10);
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

  public function testHashes()
  {
    $this->assertEquals(1, $this->credis->hSet('hash','field1','foo'));
    $this->assertEquals(0, $this->credis->hSet('hash','field1','foo'));
    $this->assertEquals('foo', $this->credis->hGet('hash','field1'));
    $this->assertEquals(NULL, $this->credis->hGet('hash','x'));
    $this->assertTrue($this->credis->hMSet('hash', array('field2' => 'Hello', 'field3' => 'World')));
    $this->assertEquals(array('foo','Hello',FALSE), $this->credis->hMGet('hash', array('field1','field2','nilfield')));
    $this->assertEquals(array(), $this->credis->hGetAll('nohash'));
    $this->assertEquals(array('field1' => 'foo', 'field2' => 'Hello', 'field3' => 'World'), $this->credis->hGetAll('hash'));

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

}
