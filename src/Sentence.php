<?php

namespace Vanderlee\Sentence;

/**
 * Segments sentences.
 * Clipping may not be perfect.
 * Sentence count should be VERY close to the truth.
 *
 * Multibyte.php safe (atleast for UTF-8), but rules based on germanic
 * language stucture (English, Dutch, German). Should work for most
 * latin-alphabet languages.
 *
 * @author Martijn van der Lee (@vanderlee)
 * @author @marktaw
 */
class Sentence
{

    /**
     * Specify this flag with the split method to trim whitespace.
     */
    const SPLIT_TRIM = 0x1;

    /**
     * List of characters used to terminate sentences.
     *
     * @var string[]
     */
    private $terminals = array('.', '!', '?');

    /**
     * List of characters used for abbreviations.
     *
     * @var string[]
     */
    private $abbreviators = array('.');

    /**
     * Breaks a piece of text into lines by linebreak.
     * Eats up any linebreak characters as if one.
     *
     * Multibyte.php safe
     *
     * @param string $text
     * @return string[]
     */
    private static function linebreakSplit($text)
    {
        $lines = array();
        $line = '';

        foreach (Multibyte::split('([\r\n]+)', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            $line .= $part;
            if (Multibyte::trim($part) === '') {
                $lines[] = $line;
                $line = '';
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /**
     * Replace
     *
     * @staticvar array $chr_map
     * @param string $string
     * @return string
     */
    private static function cleanUnicode($string)
    {
        //https://stackoverflow.com/questions/20025030/convert-all-types-of-smart-quotes-with-php
        static $character_map = array(
            // Windows codepage 1252
            "\xC2\x82" => "'", // U+0082⇒U+201A single low-9 quotation mark
            "\xC2\x84" => '"', // U+0084⇒U+201E double low-9 quotation mark
            "\xC2\x8B" => "'", // U+008B⇒U+2039 single left-pointing angle quotation mark
            "\xC2\x91" => "'", // U+0091⇒U+2018 left single quotation mark
            "\xC2\x92" => "'", // U+0092⇒U+2019 right single quotation mark
            "\xC2\x93" => '"', // U+0093⇒U+201C left double quotation mark
            "\xC2\x94" => '"', // U+0094⇒U+201D right double quotation mark
            "\xC2\x9B" => "'", // U+009B⇒U+203A single right-pointing angle quotation mark
            // Regular Unicode     // U+0022 quotation mark (")
            // U+0027 apostrophe     (')
            "\xC2\xAB" => '"', // U+00AB left-pointing double angle quotation mark
            "\xC2\xBB" => '"', // U+00BB right-pointing double angle quotation mark
            "\xE2\x80\x98" => "'", // U+2018 left single quotation mark
            "\xE2\x80\x99" => "'", // U+2019 right single quotation mark
            "\xE2\x80\x9A" => "'", // U+201A single low-9 quotation mark
            "\xE2\x80\x9B" => "'", // U+201B single high-reversed-9 quotation mark
            "\xE2\x80\x9C" => '"', // U+201C left double quotation mark
            "\xE2\x80\x9D" => '"', // U+201D right double quotation mark
            "\xE2\x80\x9E" => '"', // U+201E double low-9 quotation mark
            "\xE2\x80\x9F" => '"', // U+201F double high-reversed-9 quotation mark
            "\xE2\x80\xB9" => "'", // U+2039 single left-pointing angle quotation mark
            "\xE2\x80\xBA" => "'", // U+203A single right-pointing angle quotation mark
        );

        $character = array_keys($character_map); // but: for efficiency you should
        $replace = array_values($character_map); // pre-calculate these two arrays
        return str_replace($character, $replace, html_entity_decode($string, ENT_QUOTES, "UTF-8"));
    }

    /**
     * Splits an array of lines by (consecutive sequences of)
     * terminals, keeping terminals.
     *
     * Multibyte.php safe (atleast for UTF-8)
     *
     * For example:
     *    "There ... is. More!"
     *        ... becomes ...
     *    [ "There ", "...", " is", ".", " More", "!" ]
     *
     * @param string $line
     * @return string[]
     */
    private function punctuationSplit($line)
    {
        $parts = array();

        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY); // This is UTF8 multibyte safe!
        $is_terminal = in_array($chars[0], $this->terminals);

        $part = '';
        foreach ($chars as $index => $char) {
            if (in_array($char, $this->terminals) !== $is_terminal) {
                $parts[] = $part;
                $part = '';
                $is_terminal = !$is_terminal;
            }
            $part .= $char;
        }

        if (!empty($part)) {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Appends each terminal item after it's preceding
     * non-terminals.
     *
     * Multibyte.php safe (atleast for UTF-8)
     *
     * For example:
     *    [ "There ", "...", " is", ".", " More", "!" ]
     *        ... becomes ...
     *    [ "There ... is.", "More!" ]
     *
     * @param string[] $punctuations
     * @return string[]
     */
    private function punctuationMerge($punctuations)
    {
        $definite_terminals = array_diff($this->terminals, $this->abbreviators);

        $merges = array();
        $merge = '';

        foreach ($punctuations as $punctuation) {
            if ($punctuation !== '') {
                $merge .= $punctuation;
                if (mb_strlen($punctuation) === 1
                    && in_array($punctuation, $this->terminals)) {
                    $merges[] = $merge;
                    $merge = '';
                } else {
                    foreach ($definite_terminals as $terminal) {
                        if (mb_strpos($punctuation, $terminal) !== false) {
                            $merges[] = $merge;
                            $merge = '';
                            break;
                        }
                    }
                }
            }
        }
        if (!empty($merge)) {
            $merges[] = $merge;
        }

        return $merges;
    }

    /**
     * Looks for capitalized abbreviations & includes them with the following fragment.
     *
     * For example:
     *    [ "Last week, former director of the F.B.I. James B. Comey was fired. Mr. Comey was not available for comment." ]
     *        ... becomes ...
     *    [ "Last week, former director of the F.B.I. James B. Comey was fired." ]
     *  [ "Mr. Comey was not available for comment." ]
     *
     * @param string[] $fragments
     * @return string[]
     */
    private function abbreviationMerge($fragments)
    {
        $return_fragment = array();

        $previous_string = '';
        $previous_is_abbreviation = false;
        $i = 0;

        foreach ($fragments as $fragment) {
            $current_string = $fragment;
            $words = mb_split('\s+', Multibyte::trim($fragment));

            $word_count = count($words);

            // if last word of fragment starts with a Capital, ends in "." & has less than 3 characters, trigger "is abbreviation"
            $last_word = trim($words[$word_count - 1]);
            $last_is_capital = preg_match('#^\p{Lu}#u', $last_word);
            $last_is_abbreviation = substr(trim($fragment), -1) === '.';
            $is_abbreviation = $last_is_capital > 0
                && $last_is_abbreviation > 0
                && mb_strlen($last_word) <= 3;

            // merge previous fragment with this
            if ($previous_is_abbreviation === true) {
                $current_string = $previous_string . $current_string;
            }
            $return_fragment[$i] = $current_string;

            $previous_is_abbreviation = $is_abbreviation;
            $previous_string = $current_string;
            // only increment if this isn't an abbreviation
            if ($is_abbreviation === false) {
                $i++;
            }
        }
        return $return_fragment;
    }

    /**
     * Merges any part starting with a closing parenthesis ')' to the previous
     * part.
     *
     * @param string[] $parts
     * @return string[]
     */
    private function parenthesesMerge($parts)
    {
        $subsentences = array();

        foreach ($parts as $part) {
            if ($part[0] === ')') {
                $subsentences[count($subsentences) - 1] .= $part;
            } else {
                $subsentences[] = $part;
            }
        }

        return $subsentences;
    }

    /**
     * Looks for closing quotes to include them with the previous statement.
     * "That was very interesting," he said.
     * "That was very interesting."
     *
     * @param string[] $statements
     * @return string[]
     */
    private function closeQuotesMerge($statements)
    {
        $i = 0;
        $previous_statement = "";
        $return = array();
        foreach ($statements as $statement) {
            // detect end quote - if the entire string is a quotation mark, or it's [quote, space, lowercase]
            if (trim($statement) === '"'
                || trim($statement) === "'"
                || (
                    (substr($statement, 0, 1) === '"'
                        || substr($statement, 0, 1) === "'")
                    && substr($statement, 1, 1) === ' '
                    && ctype_lower(substr($statement, 2, 1)) === true
                )
            ) {
                $statement = $previous_statement . $statement;
            } else {
                $i++;
            }

            $return[$i] = $statement;
            $previous_statement = $statement;
        }

        return $return;
    }

    /**
     * Merges items into larger sentences.
     * Multibyte.php safe
     *
     * @param string[] $shorts
     * @return string[]
     */
    private function sentenceMerge($shorts)
    {
        $non_abbreviating_terminals = array_diff($this->terminals, $this->abbreviators);

        $sentences = array();

        $sentence = '';
        $has_words = false;
        $previous_word_ending = null;
        foreach ($shorts as $short) {
            $word_count = count(mb_split('\s+', Multibyte::trim($short)));
            $after_non_abbreviating_terminal = in_array($previous_word_ending, $non_abbreviating_terminals);

            if ($after_non_abbreviating_terminal
                || ($has_words && $word_count > 1)) {
                $sentences[] = $sentence;
                $sentence = '';
                $has_words = $word_count > 1;
            } else {
                $has_words = ($has_words
                    || $word_count > 1);
            }

            $sentence .= $short;
            $previous_word_ending = mb_substr($short, -1);
        }
        if (!empty($sentence)) {
            $sentences[] = $sentence;
        }

        return $sentences;
    }

    /**
     * Return the sentences sentences detected in the provided text.
     * Set the Sentence::SPLIT_TRIM flag to trim whitespace.
     * @param string $text
     * @param integer $flags
     * @return string[]
     */
    public function split($text, $flags = 0)
    {
        $sentences = array();

        // clean funny quotes
        $text = self::cleanUnicode($text);

        // Split
        foreach (self::linebreakSplit($text) as $line) {
            if (Multibyte::trim($line) !== '') {
                $punctuations = $this->punctuationSplit($line);
                $parentheses = $this->parenthesesMerge($punctuations); // also works after punctuationMerge or abbreviationMerge
                $merges = $this->punctuationMerge($parentheses);
                $shorts = $this->abbreviationMerge($merges);
                $quotes = $this->closeQuotesMerge($shorts);
                $sentences = array_merge($sentences, $this->sentenceMerge($quotes));
            }
        }

        // Post process
        if ($flags & self::SPLIT_TRIM) {
            return self::trimSentences($sentences);
        }

        return $sentences;
    }

    /**
     * Multibyte.php trim each string in an array.
     * @param string[] $sentences
     * @return string[]
     */
    private static function trimSentences($sentences)
    {
        $trimmed = array();
        foreach ($sentences as $sentence) {
            $trimmed[] = Multibyte::trim($sentence);
        }
        return $trimmed;
    }

    /**
     * Return the number of sentences detected in the provided text.
     * @param string $text
     * @return integer
     */
    public function count($text)
    {
        return count($this->split($text));
    }

}