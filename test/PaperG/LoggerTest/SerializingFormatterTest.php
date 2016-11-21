<?php
namespace PaperG\LoggerTest;

use DateTime;
use PaperG\Logger\SerializingFormatter;
use stdClass;

class SerializingFormatterTest extends \PHPUnit_Framework_TestCase
{
    /** @var SerializingFormatter $formatter */
    protected static $formatter;

    public static function setUpBeforeClass()
    {
        self::$formatter = new SerializingFormatter();
    }

    /**
     * Returns the provided variable after being run through the SerializingFormatter.
     *
     * @param mixed $contextVariable Some entity to format: string, object, any type
     * @return string
     */
    private function getFormattedContext($contextVariable)
    {
        // Send $contextVariable through the SerializingFormatter.
        // Not all of these fields matter, but I include them for completeness's sake.
        $record = [
            'message'       => uniqid('unique-log-message'),
            'context'       => ['variable' => $contextVariable],
            'level'         => 100,
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
            'extra'         => []
        ];
        $fullLogEntry = self::$formatter->format($record);

        // Extract only the context at the end
        preg_match("/{$record['message']} \\[(?<context>.*)\\]$/", $fullLogEntry, $matches);
        return $matches['context'];
    }


    // Tests follow

    public function testPlainString()
    {
        $this->assertEquals(
            '"plain string"',
            $this->getFormattedContext('plain string')
        );
    }

    public function testMultilineString()
    {
        $this->assertEquals(
            '"Hello,\\nworld!"', // newline replaced with \n
            $this->getFormattedContext("Hello,\nworld!")
        );
    }

    function testBinaryString()
    {
        $this->assertEquals(
            '"þíúÎ"',   // FEEDFACE interpreted under Latin-1. It's okay that this doesn't look pretty:
                        // the important thing is that json_encode() doesn't throw a UTF-8 fit.
            $this->getFormattedContext("\xFE\xED\xFA\xCE")
        );
    }

    function testUtf8String()
    {
        $this->assertEquals(
            '"A légpárnás hajóm tele van angolnákkal."',
            $this->getFormattedContext('A légpárnás hajóm tele van angolnákkal.')
        );
    }

    public function testEmptyArray()
    {
        $this->assertEquals(
            '[]',
            $this->getFormattedContext([])
        );
    }

    public function testStrangeFloatingPointValues()
    {
        $this->assertEquals(
            '["NaN","-Infinity","Infinity"]',
            $this->getFormattedContext([NAN, -INF, INF])
        );
    }

    public function testMiscellaneousScalars()
    {
        $this->assertEquals(
            '[true,false,null,1,2.718]',
            $this->getFormattedContext([true, false, null, 1, 2.718])
        );
    }

    public function testAssocArray()
    {
        $this->assertJsonStringEqualsJsonString(
            '{"key":"value"}',
            $this->getFormattedContext(['key' => 'value'])
        );
    }

    public function testObject()
    {
        $obj = new stdClass();
        $obj->memberName = 'someValue';
        $this->assertJsonStringEqualsJsonString(
            '{"class":"stdClass","memberName":"someValue"}',
            $this->getFormattedContext($obj)
        );
    }

    public function testClosure()
    {
        $closure = function () {};
        $this->assertEquals(
            '"(closure)"',
            $this->getFormattedContext($closure)
        );
    }

    public function testRecursionDepthLimit()
    {
        $ridiculousRecursionDepth = 1000;
        $nestedArrays1 = ["The following two serializations should match"];
        $nestedArrays2 = ["because these strings will never be reached"];
        for ($i = 0; $i < $ridiculousRecursionDepth; $i++)
        {
            $nestedArrays1 = [$nestedArrays1];
            $nestedArrays2 = [$nestedArrays2];
        }

        $this->assertEquals(
            $this->getFormattedContext($nestedArrays1),
            $this->getFormattedContext($nestedArrays2)
        );
    }

    public function testScalarSummariesAtDepthLimit()
    {
        // Correct summaries of selected scalars (all bytes, including quotes)
        $intSummary = '"(integer)"';
        $doubleSummary = '"(double)"';
        $stringSummary = '"(string)"';

        // Generate some large scalars.
        // The big scalars, when serialized, should be exactly the same size as their summaries.
        // The bigger variants should be one character larger.
        $bigInt = pow(10, strlen($intSummary) - 1);
        $biggerInt = $bigInt*10;
        $bigDouble = pow(10, strlen($doubleSummary) - 1 - strlen('.5')) + 0.5;
        $biggerDouble = $bigDouble + 0.25; // convert 1000.5 to 1000.75
        $bigString = str_repeat('a', strlen($stringSummary) - strlen('""'));
        $biggerString = $bigString . 'a';

        // Assemble an array of scalars, and do a meta-test on our work
        $scalarArray = [$bigInt, $biggerInt, $bigDouble, $biggerDouble, $bigString, $biggerString];
        foreach (array_chunk($scalarArray, 2) as $pair)
        {
            $big = json_encode($pair[0]);
            $bigger = json_encode($pair[1]);
            $this->assertTrue(
                    strlen($big) + 1 == strlen($bigger),
                    __FUNCTION__ . " is broken: $bigger should be one character longer than $big"
            );
        }

        // Prod SerializingFormatter into wanting to summarize scalars by inserting a huge one.
        // This shows up in the result as an extra $stringSummary.
        $scalarArray[] = str_repeat('a', SerializingFormatter::MAX_BYTES_PER_LINE + 1);

        // Actually test the serialization.
        // Big types should serialize to themselves; bigger types should serialize to summaries.
        $this->assertEquals(
                "[$bigInt,$intSummary,$bigDouble,$doubleSummary,\"$bigString\",$stringSummary,$stringSummary]",
                $this->getFormattedContext($scalarArray)
        );
    }

    public function testLengthLimitWithDeepArray()
    {
        // Generate a deep data structure that will be both:
        // - shallower than MAX_RECURSION_DEPTH, and
        // - larger than MAX_BYTES_PER_LINE when serialized.
        // The result will look similar to: ["aaa",["aaa",["aaa",[ ... ]]]]
        $numElements = floor(SerializingFormatter::MAX_RECURSION_DEPTH/2);
        $numBytes = SerializingFormatter::MAX_BYTES_PER_LINE*2;
        $elementSize = ceil($numBytes/$numElements);
        $element = str_repeat('a', $elementSize);
        $dataStructure = null;
        for ($i = 0; $i < $numElements; $i++)
        {
            $dataStructure = [&$element, $dataStructure];
        }

        // Make sure the log line doesn't exceed the length limit
        $record = [
            'message'       => "Some message",
            'context'       => ['variable' => $dataStructure],
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $this->assertLessThanOrEqual(
            SerializingFormatter::MAX_BYTES_PER_LINE, strlen($formatted),
            "Serialization over size limit due to context variable"
        );

        // Make sure that the length limiting didn't remove too much
        $lengthWithAdditionalElement = strlen($formatted) + $elementSize + strlen('["",]');
        $this->assertGreaterThan(
            SerializingFormatter::MAX_BYTES_PER_LINE, $lengthWithAdditionalElement,
            "Serialization under size limit by a large margin"
        );
    }

    public function testLengthLimitWithLongArray()
    {
        $numElements = SerializingFormatter::MAX_BYTES_PER_LINE + 1;
        $dataStructure = array_fill(0, $numElements, 0); // array of zeroes, length $numElements

        $this->assertEquals(
            '"(array ...)"',    // Length limiting doesn't do anything fancy:
                                // we should only see a type string.
            $this->getFormattedContext($dataStructure)
        );
    }

    public function testLengthLimitWithString()
    {
        $numElements = SerializingFormatter::MAX_BYTES_PER_LINE + 1;
        $dataStructure = str_repeat('a', $numElements);

        $this->assertEquals(
            '"(string)"',   // Length limiting doesn't do anything fancy:
                            // we should only see a type summary.
            $this->getFormattedContext($dataStructure)
        );
    }

    public function testLengthLimitWithLongMessage()
    {
        // Human-readable messages should not be this long.
        // All we care about here is that SerializingFormatter doesn't break.
        $record = [
            'message'       => str_repeat('a', SerializingFormatter::MAX_BYTES_PER_LINE + 1),
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $this->assertLessThanOrEqual(
            SerializingFormatter::MAX_BYTES_PER_LINE, strlen($formatted),
            "Serialization over size limit due to long message"
        );
    }

    public function testCircularReferenceDetectionWithArrays()
    {
        $selfReferencingArray = [1, 2, 3];
        $selfReferencingArray[1] = &$selfReferencingArray;
        $wrapperArray = ['a', &$selfReferencingArray, 'b', &$selfReferencingArray, 'c'];

        $this->assertEquals(
            '["a",[1,"(array self-reference)",3],"b",[1,"(array self-reference)",3],"c"]',
            $this->getFormattedContext($wrapperArray)
        );
    }

    public function testCircularReferenceDetectionWithObjects()
    {
        $selfReferencingObject = new stdClass();
        $selfReferencingObject->referenceToSelf = $selfReferencingObject;

        $this->assertJsonStringEqualsJsonString(
            '{"class":"stdClass","referenceToSelf":"(stdClass self-reference)"}',
            $this->getFormattedContext($selfReferencingObject)
        );
    }

    public function testCustomTags()
    {
        $record = [
            'message'       => "Some message",
            'context'       => ['tags' => ['foo' => 3.14, 'bar' => "pi"]],
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $this->assertContains('[foo 3.14]', $formatted);
        $this->assertContains('[bar pi]', $formatted);
    }

    public function testCustomTagEscaping()
    {
        $record = [
            'message'       => "Some message",
            'context'       => ['tags' => ['brackets' => "a [b] c"]],
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $this->assertContains('[brackets a \[b\] c]', $formatted);
    }

    public function testLogMessageQuotes()
    {
        $record = [
            'message'       => 'Some message with "quotes"',
            'context'       => [],
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $message = trim(explode(': ', $formatted)[1]);

        $this->assertEquals(
            'Some message with "quotes"', // No surrounding quotes, no escaped quotes
            $message
        );
    }

    public function testLogMessageEscaping()
    {
        $record = [
            'message'       => 'Some message with [brackets]',
            'context'       => [],
            'level_name'    => 'DEBUG',
            'channel'       => 'test',
            'datetime'      => new DateTime("@0"),
        ];
        $formatted = self::$formatter->format($record);
        $message = trim(explode(': ', $formatted)[1]);

        $this->assertEquals(
            'Some message with \\[brackets\\]',
            $message
        );
    }

    public function testCustomSerialization()
    {
        $obj = $this->getMock('JsonSerializable');
        $obj->expects($this->once())->method('jsonSerialize')->willReturn([1,2,3]);

        $this->assertEquals(
            '[1,2,3]',
            self::getFormattedContext($obj)
        );
    }

    public function testCustomSerializationReturnsObject()
    {
        $returnedObject = new stdClass();
        $returnedObject->foo = "bar";
        $obj = $this->getMock('JsonSerializable');
        $obj->expects($this->once())->method('jsonSerialize')->willReturn($returnedObject);

        $this->assertEquals(
            '{"foo":"bar"}',
            self::getFormattedContext($obj)
        );
    }

    public function testCustomSerializationReturnsSelfReference()
    {
        $obj = $this->getMock('JsonSerializable');
        $obj->expects($this->once())->method('jsonSerialize')->willReturn($obj);

        $this->assertJsonStringEqualsJsonString(
            json_encode(get_object_vars($obj)),
            self::getFormattedContext($obj)
        );
    }
}
