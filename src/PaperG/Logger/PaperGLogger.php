<?php

namespace PaperG\Logger;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;


class PaperGLogger extends Logger
{
    const DEFAULT_LOG_DIR = "/var/log";

    public function __construct($logName)
    {
        $handler = new StreamHandler(
            $this->calculateLogPath($logName, get_defined_constants()),

            // Skip optional parameters that we don't care about:
            Logger::DEBUG,  // $level
            true,           // $bubble
            null,           // $filePermission

            // Turn on file locking:
            true            // $useLocking
        );
        $handler->setFormatter(new SerializingFormatter());
        parent::__construct($logName, [$handler]);
    }

    public function calculateLogPath($logName)
    {
        // Allow user to completely override the log destination via environment variable.
        // A single filename ("/foo/bar/baz.log") or stream ("php://stdout") may be specified.
        if (!empty(getenv('LOG_FULLPATH')))
        {
            return getenv('LOG_FULLPATH');
        }

        // Use a different log folder if requested.
        if (empty(getenv('LOG_DIR')))
        {
            $logRoot = self::DEFAULT_LOG_DIR;
        }
        else
        {
            $logRoot = realpath(getenv('LOG_DIR'));
            if ($logRoot === false)
            {
                throw new \InvalidArgumentException("Invalid value for LOG_DIR: " . getenv('LOG_DIR'));
            }
        }

        $prefix = getenv('LOG_PREFIX');
        if (isset($prefix)) {
            $logName = $prefix . $logName;
        }

        return "$logRoot/$logName.log";
    }
}
