<?php

namespace DevTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    private const SKIP_VALUE = '__SKIP__';

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Builds an SQL query based on a template and an array of parameters.
     *
     * @param string $query The SQL query template.
     * @param array $args Array of parameters to substitute into the query.
     * @return string The built SQL query.
     * @throws \InvalidArgumentException If the number of parameters does not match the number of specifiers.
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $query = $this->processConditionalBlocks($query, $args);

        foreach ($args as $value) {
            $placeholderPosition = strpos($query, '?');
            if ($placeholderPosition === false) {
                throw new \InvalidArgumentException("Too many arguments provided.");
            }

            $nextChar = substr($query, $placeholderPosition + 1, 1);
            $replacement = '';

            switch ($nextChar) {
                case 'd':
                    $replacement = $value === null ? 'NULL' : (int)$value;
                    $query = substr_replace($query, $replacement, $placeholderPosition, 2);
                    break;
                case 'f':
                    $replacement = $value === null ? 'NULL' : (float)$value;
                    $query = substr_replace($query, $replacement, $placeholderPosition, 2);
                    break;
                case 'a':
                    if (is_array($value)) {
                        if ($this->isAssociativeArray($value)) {
                            $replacement = implode(', ', array_map(function ($key, $val) {
                                $escapedKey = '`' . addslashes($key) . '`';
                                $escapedVal = $this->formatValue($val);
                                return "$escapedKey = $escapedVal";
                            }, array_keys($value), $value));
                        } else {
                            $replacement = implode(', ', array_map([$this, 'formatValue'], $value));
                        }
                    } else {
                        throw new \InvalidArgumentException("Expected an array for placeholder ?a.");
                    }
                    $query = substr_replace($query, $replacement, $placeholderPosition, 2);
                    break;
                case '#':
                    if (is_array($value)) {
                        $replacement = implode(', ', array_map(function ($v) {
                            return '`' . addslashes($v) . '`';
                        }, $value));
                    } else {
                        $replacement = $value === null ? 'NULL' : '`' . addslashes($value) . '`';
                    }
                    $query = substr_replace($query, $replacement, $placeholderPosition, 2);
                    break;
                default:
                    $replacement = $this->formatValue($value);
                    $query = substr_replace($query, $replacement, $placeholderPosition, 1);
                    break;
            }
        }

        if (strpos($query, '?') !== false) {
            throw new \InvalidArgumentException("Not enough arguments provided.");
        }

        return $query;
    }

    /**
     * Returns the value used to skip blocks in the query.
     *
     * @return string The value used to skip blocks.
     */
    public function skip()
    {
        return self::SKIP_VALUE;
    }

    /**
     * Checks if an array is associative.
     *
     * @param array $array The array to check.
     * @return bool Returns true if the array is associative, false otherwise.
     */
    private function isAssociativeArray(array $array): bool {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Formats a value for insertion into an SQL query.
     *
     * @param mixed $value The value to format.
     * @return string The formatted value.
     * @throws \InvalidArgumentException If the value type is not supported.
     */
    private function formatValue($value): string {
        if (is_int($value)) {
            return (string)$value;
        } elseif (is_float($value)) {
            return (string)$value;
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_null($value)) {
            return 'NULL';
        } elseif (is_string($value)) {
            return "'" . addslashes($value) . "'";
        } else {
            throw new \InvalidArgumentException("Unsupported value type.");
        }
    }

    /**
     * Processes conditional blocks in the SQL query, removing or keeping them depending on the parameter values.
     *
     * @param string $query The SQL query with conditional blocks.
     * @param array &$args The array of parameters to be substituted into the query.
     * @return string The processed SQL query.
     */
    private function processConditionalBlocks(string $query, array &$args): string {
        $pattern = '/\{(.*?)\}/s';

        preg_match_all($pattern, $query, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $key => $match) {
            $block = $match[0];
            $blockWithoutBrackets = $matches[1][$key][0];
            $blockStart = $match[1];

            $prefix = substr($query, 0, $blockStart);
            $prefixSpecCount = substr_count($prefix, '?');

            $blockSpecCount = substr_count($block, '?');

            $blockArgs = array_slice($args, $prefixSpecCount, $blockSpecCount);

            $blockSkip = false;

            foreach ($blockArgs as $arg) {
                if ($arg === $this->skip()) {
                    $blockSkip = true;
                    break;
                }
            }

            if ($blockSkip) {
                $query = substr_replace($query, '', $blockStart, strlen($block));
                $args = array_merge(
                    array_slice($args, 0, $prefixSpecCount),
                    array_slice($args, $prefixSpecCount + $blockSpecCount)
                );
            } else {
                $query = substr_replace($query, $blockWithoutBrackets, $blockStart, strlen($block));
            }
        }

        return $query;
    }

}
