<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use JsonException;

/**
 * Pulls the trailing JSON context object from a Laravel-style log head line
 * (e.g. `... message {"request_id":"...","userId":42}`) and returns it as a
 * flattened associative array with dotted keys.
 */
final class LogContextExtractor
{
    /**
     * @return array{0: array<string, scalar|null>, 1: string} context, headWithoutJson
     */
    public function extract(string $headLine): array
    {
        $brace = strrpos($headLine, '{"');
        if ($brace === false) {
            return [[], $headLine];
        }

        $candidate = substr($headLine, $brace);
        try {
            $decoded = json_decode($candidate, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [[], $headLine];
        }

        if (! is_array($decoded)) {
            return [[], $headLine];
        }

        return [$this->flatten($decoded), trim(substr($headLine, 0, $brace))];
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($value === [] || $this->isList($value)) {
                    $out[$path] = $this->scalariseList($value);
                    continue;
                }

                $out = $out + $this->flatten($value, $path);
                continue;
            }

            $out[$path] = $this->coerceScalar($value);
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /**
     * @param array<int|string, mixed> $list
     */
    private function scalariseList(array $list): string
    {
        $parts = [];
        foreach ($list as $item) {
            if (is_scalar($item) || $item === null) {
                $parts[] = (string) $item;
                continue;
            }

            $encoded = json_encode($item);
            $parts[] = $encoded === false ? '?' : $encoded;
        }

        return implode(',', $parts);
    }

    private function coerceScalar(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
