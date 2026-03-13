<?php
declare(strict_types=1);

namespace codechap\ContextTrimmer;

/**
 * Class ContextTrimmer
 *
 * Provides tokenizer-agnostic preprocessing functions to trim text
 * for LLM context optimization. Methods include trimming by character count,
 * removing short words, and compressing whitespace.
 */
class ContextTrimmer {
    /**
     * @var callable|null
     */
    protected $tokenizer = null;

    /**
     * Whether to remove short words.
     *
     * @var bool
     */
    protected $removeShortWords = false;

    /**
     * Minimum word length allowed.
     *
     * @var int
     */
    protected $minWordLength = 2;

    /**
     * Whether to remove extraneous punctuation.
     *
     * @var bool
     */
    protected $removeExtraneous = false;

    /**
     * Whether to remove duplicate lines.
     *
     * @var bool
     */
    protected $removeDuplicateLines = false;
    
    /**
     * The maximum number of tokens per segment.
     *
     * @var int
     */
    protected $maxTokens = 0;

    /**
     * Constructor
     * 
     * @param callable|null $tokenizer
     * 
     * @return void
     */
    public function __construct(?callable $tokenizer = null)
    {
        $this->tokenizer = $tokenizer ?? function(string $text): array {
            // Default tokenizer splits on whitespace and preserves punctuation
            return preg_split('/\b|\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        };
    }

    /**
     * Trims and preprocesses the input text.
     * 
     * The preprocessing steps include:
     * - Removing words shorter than the configured minimum length (if enabled).
     * - Removing extraneous punctuation (if enabled).
     * - Compressing extra whitespace into a single space.
     * 
     * After preprocessing, the text is tokenized and segmented into groups of tokens
     * that do not split up sentences.
     *
     * The configuration for short word removal, min word length, and extraneous punctuation
     * is read from the instance's properties (which can be set via the set() method).
     *
     * @param string $input     The input text to process.
     * 
     * @return array The processed text segments.
     */
    public function trim(string $input): array {
        if ($this->maxTokens <= 0) {
            throw new \InvalidArgumentException('Token limit must be greater than zero');
        }
    
        if (empty($input)) {
            return [];
        }
    
        // Retrieve configuration using the public get() method.
        $removeDuplicateLines = $this->get('removeDuplicateLines');
        $removeShortWords = $this->get('removeShortWords');
        $minWordLength = $this->get('minWordLength');
        $removeExtraneous = $this->get('removeExtraneous');

        // Preprocess the text: optionally remove duplicate lines, short words, and compress whitespace.
        if ($removeDuplicateLines) {
            $input = $this->removeDuplicateLines($input);
        }
        if ($removeShortWords) {
            $input = $this->removeShortWords($input, $minWordLength);
        }
        if ($removeExtraneous) {
            $input = $this->removeExtraneousCharacters($input);
        }
        $input = $this->compressWhitespace($input);
    
        // Special handling: if maxTokens is 1, return each token as its own segment.
        if ($this->maxTokens === 1) {
            $tokens = ($this->tokenizer)($input);
            return array_values(array_filter(array_map('trim', $tokens), function($token) {
                return $token !== '';
            }));
        }
    
        $totalTokens = $this->countTokens($input);
        if ($totalTokens <= $this->maxTokens) {
            return [$input];
        }
    
        // Split the text into sentences using common punctuation as delimiters.
        $sentences = preg_split('/(?<=[.!?])\s+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        $segments = [];
        $currentSegment = '';
        $currentTokenCount = 0;
    
        foreach ($sentences as $sentence) {
            $sentenceTokenCount = $this->countTokens($sentence);
    
            // If a single sentence exceeds the token limit, flush the current segment and add this sentence on its own.
            if ($sentenceTokenCount > $this->maxTokens) {
                // Instead of returning the entire sentence as one segment,
                // we further break it into tokens if needed.
                if ($currentSegment !== '') {
                    $segments[] = trim($currentSegment);
                    $currentSegment = '';
                    $currentTokenCount = 0;
                }
                $tokens = ($this->tokenizer)($sentence);
                foreach ($tokens as $token) {
                    $trimmedToken = trim($token);
                    if ($trimmedToken !== '') {
                        $segments[] = $trimmedToken;
                    }
                }
                continue;
            }
    
            // If adding this sentence would exceed the token limit, start a new segment.
            if ($currentTokenCount + $sentenceTokenCount > $this->maxTokens) {
                $segments[] = trim($currentSegment);
                $currentSegment = $sentence;
                $currentTokenCount = $sentenceTokenCount;
            } else {
                // Append the sentence to the current segment.
                $currentSegment = $currentSegment === '' ? $sentence : $currentSegment . ' ' . $sentence;
                $currentTokenCount += $sentenceTokenCount;
            }
        }
    
        if ($currentSegment !== '') {
            $segments[] = trim($currentSegment);
        }
    
        return $segments;
    }

    /**
     * Counts the number of tokens in the input text.
     * 
     * @param string $text The input text to count tokens in.
     * 
     * @return int The number of tokens in the input text.
     */
    public function countTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        $tokens = ($this->tokenizer)($text);
        return count($tokens);
    }

    /**
     * Removes words that are shorter than the specified minimum word length.
     *
     * @param string $text          The original text.
     * @param int    $minWordLength The minimum length of words to preserve.
     *
     * @return string The filtered text.
     */
    public function removeShortWords(string $text, int $minWordLength = 2): string {
        // Split the text into tokens while preserving whitespace.
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Filter out tokens that are purely alphabetical and of length less than or equal to the given minimum.
        $filteredTokens = array_map(function($token) use ($minWordLength) {
            if (preg_match('/^\p{L}+$/u', $token)) {
                return mb_strlen($token) <= $minWordLength ? '' : $token;
            }
            return $token;
        }, $tokens);

        // Rejoin the tokens and compress whitespace.
        return trim(preg_replace('/\s+/', ' ', implode('', $filteredTokens)));
    }

    /**
     * Compresses multiple whitespace characters into a single space.
     *
     * @param string $text The original text.
     *
     * @return string The text with compressed whitespace.
     */
    public function compressWhitespace(string $text): string {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * Removes duplicate lines from the text, preserving order.
     *
     * @param string $text The original text.
     *
     * @return string The text with duplicate lines removed.
     */
    public function removeDuplicateLines(string $text): string {
        $lines = explode("\n", $text);
        return implode("\n", array_unique($lines));
    }

    /**
     * Removes extraneous punctuation characters from the text.
     *
     * For example, characters such as brackets, parentheses, angle brackets, braces, and asterisks.
     *
     * @param string $text The original text.
     *
     * @return string The text with extraneous punctuation removed.
     */
    public function removeExtraneousCharacters(string $text): string {
        return preg_replace('/[\[\]\(\)\{\}\<\>\*]/u', '', $text);
    }

    /**
     * Get a specific property from the service
     *
     * @param string $name The property name to get
     * @return mixed The value of the property
     */
    public function get(string $name)
    {
        if (!property_exists($this, $name)) {
            throw new \InvalidArgumentException(sprintf('Property "%s" does not exist', $name));
        }
        return $this->$name;
    }

    /**
     * Set a specific property for the service
     *
     * @param string $name The property name to set
     * @param mixed $value The value to set
     * @return self Returns the current instance
     */
    public function set(string $name, $value): self
    {
        if (!property_exists($this, $name)) {
            throw new \InvalidArgumentException(sprintf('Property "%s" does not exist', $name));
        }
        $this->$name = $value;
        return $this;
    }
}