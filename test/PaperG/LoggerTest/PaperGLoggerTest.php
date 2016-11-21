<?php
namespace PaperG\LoggerTest;

use PaperG\Logger\PaperGLogger;

class PaperGLoggerTest extends \PHPUnit_Framework_TestCase
{
    /** @var PaperGLogger */
    private static $logger;
    private static $oldEnv = [];

    public static function setUpBeforeClass()
    {
        self::$logger = new PaperGLogger("logName");
        self::$oldEnv['LOG_DIR'] = getenv('LOG_DIR');
        self::$oldEnv['LOG_FULLPATH'] = getenv('LOG_FULLPATH');
        self::$oldEnv['LOG_PREFIX'] = getenv('LOG_PREFIX');
    }

    public function setUp()
    {
        putenv("LOG_DIR");
        putenv("LOG_FULLPATH");
        putenv("LOG_PREFIX");
    }

    public static function tearDownAfterClass()
    {
        putenv("LOG_DIR=" . self::$oldEnv['LOG_DIR']);
        putenv("LOG_FULLPATH=" . self::$oldEnv['LOG_FULLPATH']);
        putenv("LOG_PREFIX=" . self::$oldEnv['LOG_PREFIX']);
    }

    public function testDevboxLogPath()
    {
        $path = self::$logger->calculateLogPath("logName");
        $this->assertEquals("/var/log/logName.log", $path);
    }

    public function testLogDirOverride()
    {
        putenv("LOG_DIR=" . __DIR__);
        $path = self::$logger->calculateLogPath("logName");
        $this->assertEquals(__DIR__ . "/logName.log", $path);
    }

    public function testLogDirAndPrefix()
    {
        putenv("LOG_DIR=" . __DIR__);
        putenv("LOG_PREFIX=whatever-");
        $path = self::$logger->calculateLogPath("logName");
        $this->assertEquals(__DIR__ . "/whatever-logName.log", $path);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testBadLogDirOverride()
    {
        putenv("LOG_DIR=/this/folder/should/never/exist");
        self::$logger->calculateLogPath("logName");
    }

    public function testLogFullpathOverride()
    {
        putenv("LOG_FULLPATH=whatever");
        $path = self::$logger->calculateLogPath("logName");
        $this->assertEquals("whatever", $path);
    }

    public function testPrefix()
    {
        putenv("LOG_PREFIX=whatever");
        $path = self::$logger->calculateLogPath("logName");
        $this->assertEquals("/var/log/whateverlogName.log", $path);
    }
}
