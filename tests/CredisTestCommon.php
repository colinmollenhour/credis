<?php
// backward compatibility (https://stackoverflow.com/a/42828632/187780)
if (!class_exists('\PHPUnit\Framework\TestCase') && class_exists('\PHPUnit_Framework_TestCase')) {
    class_alias('\PHPUnit_Framework_TestCase', '\PHPUnit\Framework\TestCase');
}

class CredisTestCommon extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
        if(preg_match('/^WIN/',strtoupper(PHP_OS))){
            echo "Unit tests will not work automatically on Windows. Please setup all Redis instances manually:".PHP_EOL;
            echo "\tredis-server redis-master.conf".PHP_EOL;
            echo "\tredis-server redis-slave.conf".PHP_EOL;
            echo "\tredis-server redis-2.conf".PHP_EOL;
            echo "\tredis-server redis-3.conf".PHP_EOL;
            echo "\tredis-server redis-4.conf".PHP_EOL;
            echo "\tredis-server redis-auth.conf".PHP_EOL;
            echo "\tredis-server redis-socket.conf".PHP_EOL;
            echo "\tredis-sentinel redis-sentinel.conf".PHP_EOL.PHP_EOL;
        } else {
            chdir(__DIR__);
            $directoryIterator = new DirectoryIterator(__DIR__);
            foreach($directoryIterator as $item){
                if(!$item->isfile() || !preg_match('/^redis\-(.+)\.conf$/',$item->getFilename()) || $item->getFilename() == 'redis-sentinel.conf'){
                    continue;
                }
                exec('redis-server '.$item->getFilename());
            }
            copy('redis-master.conf','redis-master.conf.bak');
            copy('redis-slave.conf','redis-slave.conf.bak');
            copy('redis-sentinel.conf','redis-sentinel.conf.bak');
            exec('redis-sentinel redis-sentinel.conf');
        }
    }

    public static function tearDownAfterClass()
    {
        if(preg_match('/^WIN/',strtoupper(PHP_OS))){
            echo "Please kill all Redis instances manually:".PHP_EOL;
        } else {
            chdir(__DIR__);
            $directoryIterator = new DirectoryIterator(__DIR__);
            foreach($directoryIterator as $item){
                if(!$item->isfile() || !preg_match('/^redis\-(.+)\.pid$/',$item->getFilename())){
                    continue;
                }
                $pid = trim(file_get_contents($item->getFilename()));
                if(function_exists('posix_kill')){
                    posix_kill($pid,15);
                } else {
                    exec('kill '.$pid);
                }
            }
            sleep(1); // give teardown some time to finish
            @unlink('dump.rdb');
            @unlink('redis-master.conf');
            @unlink('redis-slave.conf');
            @unlink('redis-sentinel.conf');
            @copy('redis-master.conf.bak','redis-master.conf');
            @copy('redis-slave.conf.bak','redis-slave.conf');
            @copy('redis-sentinel.conf.bak','redis-sentinel.conf');
        }
    }
}
