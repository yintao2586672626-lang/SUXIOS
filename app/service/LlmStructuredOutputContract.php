<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use stdClass;

/** The structured-output subset used by SUXIOS; business facts need separate validation. */
final class LlmStructuredOutputContract
{
    public static function openAiSchema(array $schema): array
    {
        unset($schema['x-governance']);
        if (in_array('object', (array)($schema['type'] ?? []), true)) {
            $required = (array)($schema['required'] ?? []);
            $properties = (array)($schema['properties'] ?? []);
            foreach ($properties as $key => $child) {
                $child = self::openAiSchema($child);
                // The wire format requires every key. Null represents an omitted optional key.
                $properties[$key] = in_array($key, $required, true)
                    ? $child
                    : ['anyOf' => [$child, ['type' => 'null']]];
            }
            $schema['properties'] = $properties === [] ? new stdClass() : $properties;
            $schema['required'] = array_keys($properties);
            $schema['additionalProperties'] = false;
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::openAiSchema($schema['items']);
        }
        foreach ((array)($schema['anyOf'] ?? []) as $index => $branch) {
            $schema['anyOf'][$index] = self::openAiSchema($branch);
        }
        return $schema;
    }

    public static function decode(string $json, array $schema, string $provider = ''): array
    {
        $value = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || (!is_array($value) && !$value instanceof stdClass)) {
            throw new RuntimeException('LLM did not return valid structured JSON');
        }
        if ($provider === 'openai') {
            self::validate($value, self::openAiSchema($schema));
            $value = self::restoreOptionalFields($value, $schema);
        }
        self::validate($value, $schema);
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function restoreOptionalFields(mixed $value, array $schema): mixed
    {
        if (isset($schema['anyOf'])) {
            $siblings = $schema;
            unset($siblings['anyOf']);
            // A value already accepted by a business branch must keep its explicit nulls.
            // Only try the more permissive wire branches after the original branches.
            foreach ([false, true] as $restoreWireBranch) {
                foreach ($schema['anyOf'] as $branch) {
                    try {
                        self::validate($value, $restoreWireBranch ? self::openAiSchema($branch) : $branch);
                        $candidate = self::copyJsonValue($value);
                        if ($restoreWireBranch) {
                            $candidate = self::restoreOptionalFields($candidate, $branch);
                        }
                        $candidate = self::restoreOptionalFields($candidate, $siblings);
                        self::validate($candidate, $schema);
                        return $candidate;
                    } catch (RuntimeException) {
                        // Failed candidates must not mutate later branches, including nested objects.
                    }
                }
            }
            throw new RuntimeException('LLM structured JSON anyOf mismatch while restoring optional fields');
        }
        if ($value instanceof stdClass) {
            foreach ((array)($schema['properties'] ?? []) as $key => $child) {
                if (!property_exists($value, (string)$key)) {
                    continue;
                }
                if ($value->{$key} === null && !in_array($key, (array)($schema['required'] ?? []), true)) {
                    // Keep explicit null if the original business contract accepts it.
                    try {
                        self::validate(null, $child);
                    } catch (RuntimeException) {
                        unset($value->{$key});
                        continue;
                    }
                }
                $value->{$key} = self::restoreOptionalFields($value->{$key}, $child);
            }
        } elseif (is_array($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $value[$index] = self::restoreOptionalFields($item, $schema['items']);
            }
        }
        return $value;
    }

    private static function copyJsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = self::copyJsonValue($item);
            }
            return $copy;
        }
        return is_array($value) ? array_map(self::copyJsonValue(...), $value) : $value;
    }

    public static function validate(mixed $value, array $schema, string $path = '$'): void
    {
        if (isset($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $branch) {
                try {
                    self::validate($value, $branch, $path);
                    $matched = true;
                    break;
                } catch (RuntimeException) {
                    // Try the remaining explicitly allowed shapes.
                }
            }
            if (!$matched) {
                throw new RuntimeException('LLM structured JSON anyOf mismatch at ' . $path);
            }
        }
        if (isset($schema['enum'])) {
            $matched = false;
            foreach ($schema['enum'] as $allowed) {
                if (self::jsonValuesEqual($value, $allowed)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new RuntimeException('LLM structured JSON enum mismatch at ' . $path);
            }
        }
        $types = (array)($schema['type'] ?? 'any');
        $valid = false;
        foreach ($types as $type) {
            $valid = $valid || match ($type) {
                '', 'any' => true,
                'object' => $value instanceof stdClass,
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
        }
        if (!$valid) {
            throw new RuntimeException('LLM structured JSON type mismatch at ' . $path);
        }
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            $properties = (array)($schema['properties'] ?? []);
            foreach ((array)($schema['required'] ?? []) as $required) {
                if (!array_key_exists($required, $fields)) {
                    throw new RuntimeException('LLM structured JSON missing required field at ' . $path . '.' . $required);
                }
            }
            foreach ($fields as $key => $item) {
                if (isset($properties[$key])) {
                    self::validate($item, $properties[$key], $path . '.' . $key);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    throw new RuntimeException('LLM structured JSON additional field at ' . $path . '.' . $key);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    self::validate($item, $schema['additionalProperties'], $path . '.' . $key);
                }
            }
        }
        if (is_array($value)) {
            self::bounds(count($value), $schema, 'minItems', 'maxItems', $path);
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    self::validate($item, $schema['items'], $path . '[' . $index . ']');
                }
            }
        }
        if (is_string($value)) {
            self::bounds(mb_strlen($value), $schema, 'minLength', 'maxLength', $path);
        }
        if (is_int($value) || is_float($value)) {
            self::bounds($value, $schema, 'minimum', 'maximum', $path);
        }
    }

    private static function jsonValuesEqual(mixed $left, mixed $right): bool
    {
        $leftObject = $left instanceof stdClass || (is_array($left) && !array_is_list($left));
        $rightObject = $right instanceof stdClass || (is_array($right) && !array_is_list($right));
        if ($leftObject || $rightObject) {
            if (!$leftObject || !$rightObject) {
                return false;
            }
            $left = (array)$left;
            $right = (array)$right;
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $item) {
                if (!array_key_exists($key, $right) || !self::jsonValuesEqual($item, $right[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($left) || is_array($right)) {
            if (!is_array($left) || !is_array($right) || count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $item) {
                if (!self::jsonValuesEqual($item, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        if ((is_int($left) && is_float($right)) || (is_float($left) && is_int($right))) {
            $integer = is_int($left) ? $left : $right;
            $number = is_float($left) ? $left : $right;
            return is_finite($number) && floor($number) === $number
                && $number >= PHP_INT_MIN && $number < PHP_INT_MAX
                && $integer === (int)$number;
        }
        return $left === $right;
    }

    private static function bounds(int|float $value, array $schema, string $min, string $max, string $path): void
    {
        if ((isset($schema[$min]) && $value < $schema[$min]) || (isset($schema[$max]) && $value > $schema[$max])) {
            throw new RuntimeException('LLM structured JSON bounds mismatch at ' . $path);
        }
    }
}
