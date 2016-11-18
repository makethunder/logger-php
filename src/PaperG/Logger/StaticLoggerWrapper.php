<?php

namespace PaperG\Logger;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;


/**
 * Base class for wrapping a PSR-3 compliant logger with static methods.
 *
 * Subclasses must define a method createLogger() that returns a logger
 * object. In return, the subclass receives static methods (Subclass::debug(),
 * Subclass::info(), etc) that log to that logger object.
 *
 * These static methods accept any input that their LoggerInterface
 * counterparts accept, with a couple extensions:
 *
 *  - $context may be of any type, not just an array.
 *  - Callers may specify metadata in the form of $tags, an optional array
 *      that maps string keys to scalar values.
 *
 * $context and $tags are passed to the corresponding LoggerInterface function
 * as an array of the form ['variable' => $context, 'keys' => $keys].
 */
abstract class StaticLoggerWrapper {

    // Define string versions of the log levels
    // These can be used when calling log()
    const DEBUG     = 'debug';
    const INFO      = 'info';
    const NOTICE    = 'notice';
    const WARNING   = 'warning';
    const ERROR     = 'error';
    const CRITICAL  = 'critical';
    const ALERT     = 'alert';
    const EMERGENCY = 'emergency';


    /** @var $loggerSingletons LoggerInterface[string] */
    private static $loggerSingletons = [];

    /**
     * Create and return a new logger object.
     *
     * This method will be called no more than once per subclass: the
     * resulting logger object will be stored as a singleton.
     *
     * @return LoggerInterface
     */
    abstract protected function createLogger();

    /**
     * Internal helper, returns singleton instance of logger.
     *
     * Each subclass of StaticLoggerWrapper (ApiLog, SecurityLog, etc) gets its
     * own singleton logger instance. In other words, calls to ApiLog go to a
     * different logger than calls to SecurityLog do.
     *
     * Note that this function must be called using the static keyword:
     *
     *      static::getLogger();
     *
     * Calling by other means (e.g., self::getLogger()) will fail, because
     * this function uses late static binding in order to figure out which
     * logger instance to return. For more details, see the documentation:
     * http://php.net/manual/en/language.oop5.late-static-bindings.php
     *
     * @return LoggerInterface
     */
    private static function getLogger()
    {
        $className = get_called_class(); // full name, e.g., Foo\Bar\SpecificLogger
        if (!isset(StaticLoggerWrapper::$loggerSingletons[$className]))
        {
            /** @var $classInstance StaticLoggerWrapper */
            $classInstance = new $className();
            StaticLoggerWrapper::$loggerSingletons[$className] = $classInstance->createLogger();
        }
        return StaticLoggerWrapper::$loggerSingletons[$className];
    }

    /**
     * Internal helper, builds context arrays acceptable to SerializingFormatter.
     *
     * @param mixed $context
     * @param bool $contextExists Whether a context variable is being logged.
     *      This flag is needed to tell the difference between no $context
     *      variable (i.e., the user did not provide one) and a null $context.
     * @param array $tags
     * @return array
     */
    private static function makeContextArray($context = null, $contextExists = false, array $tags = [])
    {
        $contextArray = [];
        if ($contextExists)
        {
            $contextArray['variable'] = $context;
        }
        $autoTags = LogTags::getAll();
        if (!empty($tags) || !empty($autoTags))
        {
            $contextArray['tags'] = array_merge($autoTags, $tags);
        }
        return $contextArray;
    }

    /**
     * Validates that the message is a string.
     *
     * $message needs to be a string because Monolog casts it to a string.
     * If $message is another type (e.g., an array), it can result in a PHP
     * notice in the logs: "Array to string conversion".
     *
     * This function should go away if PHP ever supports scalar type hints.
     *
     * @param string $message
     * @throws InvalidArgumentException
     */
    private static function validateMessage($message)
    {
        if (!is_string($message))
        {
            throw new InvalidArgumentException('Log message must be of type string');
        }
    }

    /**
     * Log a message of arbitrary level
     *
     * @param string $level
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function log($level, $message, $context = null, array $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 3; // Distinguish log($l, $m, null) vs. log($l, $m)
        static::getLogger()->log($level, $message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log an emergency message (code 0)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function emergency($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish emergency($m, null) vs. emergency($m)
        static::getLogger()->emergency($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log an alert message (code 1)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function alert($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish alert($m, null) vs. alert($m)
        static::getLogger()->alert($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log a critical message (code 2)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function critical($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish critical($m, null) vs. critical($m)
        static::getLogger()->critical($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log an error message (code 3)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function error($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish error($m, null) vs. error($m)
        static::getLogger()->error($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log a warning message (code 4)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function warning($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish warning($m, null) vs. warning($m)
        static::getLogger()->warning($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log a notice message (code 5)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function notice($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish notice($m, null) vs. notice($m)
        static::getLogger()->notice($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log an info message (code 6)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function info($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish info($m, null) vs. info($m)
        static::getLogger()->info($message, self::makeContextArray($context, $contextExists, $tags));
    }

    /**
     * Log a debug message (code 7)
     *
     * @param string $message
     * @param mixed $context
     * @param array $tags
     */
    public static function debug($message, $context = null, $tags = [])
    {
        self::validateMessage($message);
        $contextExists = func_num_args() >= 2; // Distinguish debug($m, null) vs. debug($m)
        static::getLogger()->debug($message, self::makeContextArray($context, $contextExists, $tags));
    }
}
