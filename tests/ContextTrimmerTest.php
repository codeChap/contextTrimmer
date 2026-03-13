<?php

namespace codechap\ContextTrimmer\Tests;

use PHPUnit\Framework\TestCase;
use codechap\ContextTrimmer\ContextTrimmer;

class ContextTrimmerTest extends TestCase
{
    private ContextTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new ContextTrimmer();
    }

    public function testBasicTrimming(): void
    {
        $input = "This is a long text that needs to be trimmed to fit within token limits.";
        $maxTokens = 5;

        $result = $this->trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // Expect an array of segments
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Ensure each segment does not exceed the token limit
        foreach ($result as $segment) {
            $this->assertLessThanOrEqual($maxTokens, $this->trimmer->countTokens($segment));
        }
    }

    public function testEmptyInput(): void
    {
        $result = $this->trimmer
            ->set('maxTokens', 10)
            ->trim('');
        // When input is empty, expect an empty array
        $this->assertEmpty($result);
    }

    public function testNegativeTokenLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->trimmer
            ->set('maxTokens', -1)
            ->trim('Some text');
    }

    public function testZeroTokenLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->trimmer
            ->set('maxTokens', 0)
            ->trim('Some text');
    }

    public function testPreservesImportantContent(): void
    {
        $input = "Important: This is a critical message that should be preserved.";
        $maxTokens = 5;

        $result = $this->trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // Join the segments for easier content checking
        $joined = implode(' ', $result);
        $this->assertStringContainsString('Important', $joined);
    }

    public function testHandlesMultilineInput(): void
    {
        $input = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $maxTokens = 3;

        $result = $this->trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // Each segment should not exceed the token limit
        foreach ($result as $segment) {
            $this->assertLessThanOrEqual($maxTokens, $this->trimmer->countTokens($segment));
        }
        $joined = implode(' ', $result);
        $this->assertStringContainsString('Line', $joined);
    }

    public function testCustomTokenizer(): void
    {
        $customTokenizer = function(string $text): array {
            return explode(' ', $text);
        };

        $trimmer = new ContextTrimmer($customTokenizer);
        $input = "This is a test sentence";
        $maxTokens = 3;

        $result = $trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // For each segment, count the tokens using the custom tokenizer (splitting by space)
        foreach ($result as $segment) {
            $tokens = array_filter(array_map('trim', explode(' ', $segment)), fn($t) => $t !== '');
            $this->assertLessThanOrEqual($maxTokens, count($tokens));
        }
    }

    public function testMaxTokensOne(): void
    {
        $input = "This is a test";
        $maxTokens = 1;

        $result = $this->trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // When maxTokens is 1, the method returns each individual token.
        // The expected tokens are generated using the default tokenizer:
        $expectedTokens = preg_split('/\b|\s+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        $expectedTokens = array_values(array_filter(array_map('trim', $expectedTokens), function($token) {
            return $token !== '';
        }));

        $this->assertIsArray($result);
        $this->assertEquals($expectedTokens, $result);
    }

    public function testUnderTokenLimitReturnsSingleSegment(): void
    {
        $input = "Short text with few tokens";
        $maxTokens = 50; // Token limit exceeds total tokens of input

        $result = $this->trimmer
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        // Expect a single segment (after preprocessing)
        $this->assertCount(1, $result);
        // The returned segment should equal the processed input (whitespace compressed)
        $processedInput = $this->trimmer->compressWhitespace($input);
        $this->assertEquals($processedInput, $result[0]);
    }

    public function testRemoveDuplicateLines(): void
    {
        $input = "Line one\nLine two\nLine one\nLine three\nLine two";

        $result = $this->trimmer->removeDuplicateLines($input);

        $this->assertEquals("Line one\nLine two\nLine three", $result);
    }

    public function testRemoveDuplicateLinesIntegration(): void
    {
        $input = "First sentence here.\nSecond sentence here.\nFirst sentence here.\nThird sentence here.";
        $maxTokens = 50;

        $result = $this->trimmer
            ->set('removeDuplicateLines', true)
            ->set('maxTokens', $maxTokens)
            ->trim($input);

        $joined = implode(' ', $result);
        $this->assertStringContainsString('First', $joined);
        $this->assertStringContainsString('Third', $joined);

        // The duplicate "First sentence here." should only appear once
        $this->assertEquals(1, substr_count($joined, 'First sentence here'));
    }
}