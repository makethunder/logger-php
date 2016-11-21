<?php

namespace PaperG\Logger;

/**
 * Repository for automatically-applied log tags.
 *
 * This class is essentially just a singleton hashmap for storing metadata tags
 * and their values. To add or update a tag, call:
 *
 *      LogTags::add("SomethingId", 123456);
 *
 * Subsequent log entries will automatically include all such tag/value pairs,
 * provided that the log entries originated from a Monolog-based logger.
 *
 * To manually remove a tag, call:
 *
 *      LogTags::remove("SomethingId");
 *
 * Removing tags is optional. If not explicitly removed, tags will remain
 * in place for the duration of the PHP request (or, for long-running scripts,
 * for the duration of the process).
 */
class LogTags
{
    /**
     * Map of tag names (strings) to tag values.
     *
     * @var mixed[] $tags
     */
    private static $tags = [];

    /**
     * Add a tag to the logging system.
     *
     * If the tag already exists, the previous value is overwritten.
     *
     * @param string $tagName
     * @param mixed $tagValue
     */
    public static function add($tagName, $tagValue)
    {
        self::$tags[$tagName] = $tagValue;
    }

    /**
     * Remove a tag from the logging system.
     *
     * @param string $tagName
     */
    public static function remove($tagName)
    {
        unset(self::$tags[$tagName]);
    }

    /**
     * Returns the current values of all tags.
     *
     * In most cases, you do not need to call this function:
     * the logging system will automatically call it on your behalf.
     *
     * @return mixed[] An associative array of tag names and their values
     */
    public static function getAll()
    {
        return self::$tags;
    }
}
