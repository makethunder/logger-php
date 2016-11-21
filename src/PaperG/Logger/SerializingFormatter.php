<?php

namespace PaperG\Logger;

use Monolog\Formatter\FormatterInterface;

/**
 * Serializes a log record into a single UTF-8 line.
 *
 * This formatter only uses two keys from the record's context array:
 *  - 'tags':       an optional array of key-value metadata
 *  - 'variable':   an optional context variable
 * All other keys are ignored.
 *
 * 'tags' is an associative array that maps string keys to scalar values.
 * These key-values appear in the log message like so:
 *      [timestamp] [chan:sev] [key1 value1] [key2 value2]: Some log message
 * This makes it easy to associate log entries with
 * other identifiers, such as campaign IDs or user IDs.
 *
 * 'variable' can be any PHP type.
 * It is serialized to JSON and appended to the end of the log message:
 *      [timestamp] [chan:sev] Some log message [{"some":"object for context"}]
 * This makes it easy to log exceptions, data structures,
 * or anything else that aids println debugging.
 */
class SerializingFormatter implements FormatterInterface
{
    const MAX_RECURSION_DEPTH = 32;
    const MAX_BYTES_PER_LINE = 7900; // rsyslog config (8000) minus safety margin (observed limit is 7996)

    private $visitedMarker = null; // used by normalizeVariable() and friends to detect circular references
    private $jsonOptions = null; // common settings passed to json_encode()

    public function __construct()
    {
        $this->visitedMarker = uniqid("__visited_");

        // TODO: Change this to a const once we move to PHP 5.6 (const expressions are not allowed in 5.5).
        $this->jsonOptions =
            JSON_UNESCAPED_UNICODE |    // Use UTF-8 characters instead of \u263A
            JSON_UNESCAPED_SLASHES;     // Don't escape the forward (/) slash
    }

    /**
     * Prepares $scalar to be serialized via json_encode().
     *
     * This is a helper function for normalizeVariable().
     * You should probably call that instead.
     *
     * @param mixed $scalar
     * @return string
     */
    private function normalizeScalar($scalar)
    {
        switch (gettype($scalar))
        {
            // Non-composite types that json_encode() handles well
            case 'boolean':
            case 'integer':
            case 'NULL':
                return $scalar;

            // Non-composite types that need modification
            case 'double':
                // The JSON format lacks support for NaN and the two infinities
                if (is_nan($scalar))
                {
                    return 'NaN';
                }
                else if (is_infinite($scalar))
                {
                    return $scalar > 0 ? 'Infinity' : '-Infinity';
                }
                else
                {
                    return $scalar;
                }
            case 'string':
            case 'resource':
            default: // PHP documentation says "unknown type" is a possibility?!?
                // Make sure we're dealing with a string
                $normalized = (string) $scalar;

                // Make sure that $normalized is a valid UTF-8 string.
                // This should always succeed, because any string of bytes
                // can be interpreted as a Latin-1 string.
                $encoding = mb_detect_encoding($normalized, ['UTF-8', 'ISO-8859-1']);
                if ($encoding !== 'UTF-8')
                {
                    $normalized = mb_convert_encoding($scalar, 'UTF-8', $encoding);
                }
                return $normalized;
        }
    }

    /**
     * Prepares $arr to be serialized via json_encode().
     *
     * This is a helper function for normalizeVariable().
     * You should probably call that instead.
     *
     * Note that $arr has to be pass-by-reference. If it was pass-by-value, it
     * would not be able to detect circular references, because it would be
     * looking at a new copy of the array on each iteration.
     *
     * @param array $arr
     * @param int $maxRecursionDepth
     * @return string
     */
    private function normalizeArray(&$arr, $maxRecursionDepth)
    {
        if ($maxRecursionDepth <= 0)
        {
            return "(array ...)";
        }
        elseif (isset($arr[$this->visitedMarker]))
        {
            return "(array self-reference)";
        }

        // Recursively normalize all keys, values
        $normalized = [];
        $arr[$this->visitedMarker] = true; // Beginning of circular reference danger zone
        foreach ($arr as $key => &$value)
        {
            $newKey = $this->normalizeScalar($key); // Necessary because keys may be non-UTF-8 strings
            $newValue = $this->normalizeVariable($value, $maxRecursionDepth - 1);
            $normalized[$newKey] = $newValue;
        }
        unset($normalized[$this->visitedMarker]);
        unset($arr[$this->visitedMarker]); // End of circular reference danger zone

        return $normalized;
    }

    /**
     * Prepares $obj to be serialized via json_encode().
     *
     * This is a helper function for normalizeVariable().
     * You should probably call that instead.
     *
     * @param object $obj
     * @param int $maxRecursionDepth
     * @return string
     */
    private function normalizeObject($obj, $maxRecursionDepth)
    {
        // Handle bad recursion cases
        $className = get_class($obj);
        if (is_a($obj, 'Closure'))
        {
            // visitedMarker (and thus recursion) doesn't work with closure objects,
            // because closures are not allowed to have properties in PHP.
            return "(closure)";
        }
        elseif ($maxRecursionDepth <= 0)
        {
            return $this->normalizeScalar("($className ...)");
        }
        elseif (isset($obj->{$this->visitedMarker}))
        {
            return $this->normalizeScalar("($className self-reference)");
        }

        // Generate a variable that represents $obj (may be any type except object)
        if (is_a($obj, 'Exception'))
        {
            // Exceptions get special treatment
            /** @var \Exception $obj */
            $representation = [
                'cause'     => $obj->getPrevious(),
                'code'      => $obj->getCode(),
                'message'   => $obj->getMessage(),
                'trace'     => $obj->getTrace(),
                'class'     => $className
            ];
        }
        elseif (is_a($obj, 'JsonSerializable'))
        {
            // Objects implementing JsonSerializable get to choose their own serialization
            /** @var \JsonSerializable $obj */
            $representation = $obj->jsonSerialize();

            /*
             * jsonSerialize() is allowed to return objects. If these objects
             * are themselves JsonSerializable, an infinite loop could result.
             *
             * To prevent this, we convert any returned objects to arrays.
             * This ensures that $representation will never be an object.
             */
            if (is_object($representation))
            {
                $representation = get_object_vars($representation); // array of public fields
            }
        }
        else
        {
            // For all other objects, record their public fields + class name
            $representation = get_object_vars($obj);
            $representation['class'] = $className;
        }

        /*
         * Recursively normalize the resulting $representation.
         *
         * Basically, we are acting as if we have removed $obj from the data
         * structure, swapping it with $representation. The normalization
         * process continues as usual.
         *
         * Note that this pretend-swap operation does not change our current
         * depth in the data structure. That is why we do not reduce the value
         * of $maxRecursionDepth when calling normalizeVariable().
         */
        $obj->{$this->visitedMarker} = true; // Beginning of circular reference danger zone
        $normalized = $this->normalizeVariable($representation, $maxRecursionDepth);
        unset($obj->{$this->visitedMarker}); // End of circular reference danger zone
        return $normalized;
    }

    /**
     * Prepares $var to be serialized via json_encode().
     *
     * Changes that make more information available:
     * - Non-JsonSerializable objects are converted to arrays
     * - Exceptions are converted to a standard array format
     *
     * Changes that keep json_encode from blowing up:
     * - Circular references are removed
     * - Non-UTF-8 strings are converted to UTF-8
     * - Resources (e.g., file handles) are converted to strings
     *
     * $var is passed-by-reference because it made it easier to write the
     * circular reference detection code (see normalizeArray()'s doc comment).
     * Callers of normalizeVariable() should pretend $var is passed-by-value.
     *
     * @param mixed $var
     * @param int $maxRecursionDepth
     * @return mixed A normalized version of the input
     */
    private function normalizeVariable(&$var, $maxRecursionDepth)
    {
        $typeName = gettype($var);
        switch ($typeName)
        {
            case 'array':
                return $this->normalizeArray($var, $maxRecursionDepth);
            case 'object':
                return $this->normalizeObject($var, $maxRecursionDepth);
            default:
                $normalizedScalar = $this->normalizeScalar($var);
                if ($maxRecursionDepth <= 0)
                {
                    // It's okay to summarize scalars once we hit the max recursion depth.
                    // However, it only makes sense to do so if the summary is shorter.
                    $scalarSummary = "($typeName)";
                    $originalLength = strlen(json_encode($normalizedScalar, $this->jsonOptions));
                    $summaryLength = strlen(json_encode($scalarSummary, $this->jsonOptions));
                    return $summaryLength < $originalLength ? $scalarSummary : $normalizedScalar;
                }
                else
                {
                    return $normalizedScalar;
                }
        }
    }

    /**
     * Formats the provided variable as a UTF-8 string without line breaks.
     *
     * Composite data types (arrays, objects) are serialized as in JSON.
     *
     * @param mixed $var The variable to format
     * @param bool $quoteStrings Whether to double-quote standalone strings
     * @param int $maxRecursionDepth
     * @return string
     */
    private function formatVariable($var, $quoteStrings = true, $maxRecursionDepth = self::MAX_RECURSION_DEPTH)
    {
        // Pre-process the log message, so that it will be safe to pass to json_encode()
        $result = $this->normalizeVariable($var, $maxRecursionDepth);

        /*
         * The official JSON grammar (see http://json.org) guarantees that
         * JSON-encoded documents will not contain literal newline characters.
         * Assuming the PHP library is compliant (which appears so), this allows
         * us to guarantee that the log message will fit on a single line.
         */
        $result = json_encode($result, $this->jsonOptions);
        if ($result === false)
        {
            // If normalizeVariable() did its job right, this should never happen
            return "Error serializing log message: JSON error " . json_last_error();
        }

        // Style/readability: Only double-quote standalone strings if requested to do so
        if (is_string($var) && !$quoteStrings)
        {
            /*
             * Remove the quotes that json_encode() added to the string.
             *
             * Why json_encode() the string in the first place?
             * Because json_encode() also escapes control characters, such as
             * \n, \t, and the rest of the range \u0000 through \u001F.
             *
             * Why not escape that stuff ourselves?
             * Because I want to be consistent with how json_encode() would
             * have escaped the string, and PHP's library doesn't have a
             * convenient function for doing \uHHHH-style escapes.
             */
            $result = substr($result, 1, -1); // strip double quotes from ends
            $result = str_replace('\"','"', $result); // un-escape double quotes

            // We have to escape square brackets, because the log format uses
            // them to separate data. This should be a no-op for most strings.
            $result = addcslashes($result, '[]');
        }

        return $result;
    }

    /**
     * Formats a Monolog record with a specific recursion depth.
     *
     * @param array $record A Monolog record array, representing a single log entry
     * @param int $maxRecursionDepth The recursion depth to use when serializing the context variable
     * @return string A log line of arbitrary length
     */
    private function formatToDepth(array $record, $maxRecursionDepth)
    {
        // Extract timestamp
        $dateTime = $record['datetime']; /** @var \DateTime $dateTime */
        $utcString = $dateTime->setTimeZone(new \DateTimeZone('UTC'))->format("Y-m-d H:i:s"); // 2014-09-17 01:23:45
        $metadata[] = $utcString;

        // Extract channel name and severity level
        $severity = strtolower($record['level_name']);
        $metadata[] = "{$record['channel']}:$severity";

        // Extract client IP address, if present
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        {
            $metadata[] = "client {$_SERVER['HTTP_X_FORWARDED_FOR']}";
        }
        elseif (isset($_SERVER['REMOTE_ADDR']))
        {
            $metadata[] = "client {$_SERVER['REMOTE_ADDR']}";
        }

        // Extract custom tags, if present
        if (isset($record['context']['tags']))
        {
            foreach ($record['context']['tags'] as $tagName => $tagValue)
            {
                $validName = is_string($tagName) && preg_match('/^[-\w]+$/', $tagName);
                $validValue = is_scalar($tagValue) || is_null($tagValue);
                if ($validName && $validValue)
                {
                    $tagValue = $this->formatVariable($tagValue, false);
                    $metadata[] = "$tagName $tagValue";
                }
            }
        }

        // Detect context variable
        // We must use array_key_exists(), because isset($foo) returns false if $foo === null
        $contextExists = isset($record['context']) && array_key_exists('variable', $record['context']);

        /*
         * Format and combine the different elements of the log message.
         *
         * The final result is intended to look something like the following:
         *      [timestamp] [chan:sev] [tag 123]: Some message [{"some":"variable"}]
         * If there is no context variable, it is omitted:
         *      [timestamp] [chan:sev] [tag 123]: Some message w/o context
         */
        $metadataString = implode(' ',
            array_map(function ($metadataItem) { return "[$metadataItem]"; }, $metadata)
        );
        $formattedMessage = $this->formatVariable($record['message'], false);
        $result = "$metadataString: $formattedMessage";
        if ($contextExists)
        {
            $formattedContext = $this->formatVariable($record['context']['variable'], true, $maxRecursionDepth);
            $result = "$result [$formattedContext]";
        }
        return "$result\n";
    }

    /**
     * Formats a Monolog record to a string.
     *
     * @param array $record A Monolog record array, representing a single log entry
     * @return string A single log line, not exceeding MAX_BYTES_PER_LINE in length.
     */
    public function format(array $record)
    {
        // Serialize $record using the maximum recursion depth.
        // We try this first, because it works for almost all (>99%) records.
        $formattedRecord = $this->formatToDepth($record, self::MAX_RECURSION_DEPTH);
        if (strlen($formattedRecord) <= self::MAX_BYTES_PER_LINE)
        {
            return $formattedRecord;
        }

        // The optimistic serialization was too large.
        // Try smaller recursion depths via binary search.
        $minDepth = 0;
        $maxDepth = self::MAX_RECURSION_DEPTH - 1;
        $formattedRecord = null;
        while ($maxDepth >= $minDepth)
        {
            $currentDepth = round(($minDepth + $maxDepth)/2);
            $currentFormattedRecord = $this->formatToDepth($record, $currentDepth);
            if (strlen($currentFormattedRecord) > self::MAX_BYTES_PER_LINE)
            {
                // Serialization violates size limit: decrease $maxDepth
                $maxDepth = $currentDepth - 1;
            }
            else
            {
                // Serialization satisfies size limit.
                // We still want to increase $minDepth, because there might be
                // larger depth values that still satisfy the size limit.
                $minDepth = $currentDepth + 1;

                // Known good serialization: save it.
                // Note that $formattedRecord always increases in depth,
                // because $minDepth always increases in size.
                $formattedRecord = $currentFormattedRecord;
            }
        }
        if ($formattedRecord !== null)
        {
            return $formattedRecord;
        }

        /*
         * Serialization is still too large: return a fallback value.
         *
         * If we actually get to this point, it indicates that we are likely
         * misusing the logging system: for example, we might be logging a
         * data blob where a human-readable message is expected.
         *
         * There are cleaner ways to calculate this fallback value. But for
         * the above reason, implementing them is not a priority right now.
         */
        $truncationSuffix = " (...)\n";
        $truncationLength = self::MAX_BYTES_PER_LINE - strlen($truncationSuffix);
        $formattedRecord = $this->formatToDepth($record, 0);
        $truncatedRecord = substr(rtrim($formattedRecord), 0, $truncationLength) . $truncationSuffix;
        return $truncatedRecord;
    }

    public function formatBatch(array $records)
    {
        $formattedRecords = array_map(
            function ($record) { return $this->format($record); },
            $records
        );
        return implode("", $formattedRecords);
    }
}
