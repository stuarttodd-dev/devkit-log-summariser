<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

final class ExceptionExtractor
{
    /**
     * @return array{0: string, 1: string}
     */
    public function extract(string $fullText, string $headMessage): array
    {
        $text = trim($fullText);
        if ($text === '') {
            return ['Unknown', '(empty entry)'];
        }

        $matched = $this->matchSqlState($text);
        if ($matched !== null) {
            return $matched;
        }

        $matched = $this->matchQuotedException($text);
        if ($matched !== null) {
            return $matched;
        }

        $matched = $this->matchUncaught($text);
        if ($matched !== null) {
            return $matched;
        }

        $matched = $this->matchExceptionWithColon($text);
        if ($matched !== null) {
            return $matched;
        }

        $matched = $this->matchBuiltinError($text);
        if ($matched !== null) {
            return $matched;
        }

        $matched = $this->matchObjectDump($text, $headMessage);
        if ($matched !== null) {
            return $matched;
        }

        return $this->fallbackUnknown($text, $headMessage);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchSqlState(string $text): ?array
    {
        if (preg_match('/SQLSTATE\[[^\]]+\]/', $text) !== 1) {
            return null;
        }

        $sqlHeadline = $this->sqlstateHeadline($text);
        $class = 'Illuminate\\Database\\QueryException';
        if (preg_match('/Illuminate\\\\Database\\\\QueryException/', $text) === 1) {
            $class = 'Illuminate\\Database\\QueryException';
        } elseif (preg_match('/PDOException/', $text) === 1) {
            $class = 'PDOException';
        }

        return [$class, $sqlHeadline];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchQuotedException(string $text): ?array
    {
        $pattern = "/exception '((?:\\\\?[A-Za-z_][\\w\\\\]*))' with message '([^']*)'/s";
        if (preg_match($pattern, $text, $captures) !== 1) {
            return null;
        }

        return [$captures[1], $captures[2]];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchUncaught(string $text): ?array
    {
        if (preg_match('/^Uncaught\s+([A-Za-z_][\w\\\\]*):\s*([^\n]+)$/m', $text, $captures) !== 1) {
            return null;
        }

        return [$captures[1], trim($captures[2])];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchExceptionWithColon(string $text): ?array
    {
        if (preg_match('/^([A-Za-z_][\w\\\\]*Exception):\s*([^\n]+)$/m', $text, $captures) !== 1) {
            return null;
        }

        return [$captures[1], trim($captures[2])];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchBuiltinError(string $text): ?array
    {
        $pattern = '/^(TypeError|Error|ParseError|ArgumentCountError|ValueError):\s*([^\n]+)$/m';
        if (preg_match($pattern, $text, $captures) !== 1) {
            return null;
        }

        return [$captures[1], trim($captures[2])];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function matchObjectDump(string $text, string $headMessage): ?array
    {
        if (preg_match('/^\[object\]\s*\(([^)]+Exception)\)/', $text, $captures) !== 1) {
            return null;
        }

        $rest = preg_replace('/^\[object\]\s*\([^)]+\)/s', '', $text, 1);
        $msg = trim((string) $rest);
        if (strlen($msg) > 500) {
            $msg = substr($msg, 0, 500) . '…';
        }

        return [$captures[1], $msg !== '' ? $msg : $this->stripTrailingJson($headMessage)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fallbackUnknown(string $text, string $headMessage): array
    {
        $cleanHead = $this->stripTrailingJson($headMessage);

        return ['Unknown', $cleanHead !== '' ? $cleanHead : substr($text, 0, 500)];
    }

    private function sqlstateHeadline(string $text): string
    {
        if (preg_match('/(SQLSTATE\[[^\]]+\][^\n]{0,200})/s', $text, $captures) === 1) {
            return trim(preg_replace('/\s+/', ' ', $captures[1]) ?? '');
        }

        return 'SQLSTATE error';
    }

    private function stripTrailingJson(string $line): string
    {
        $line = trim($line);
        $brace = strrpos($line, '{"');
        if ($brace !== false) {
            return trim(substr($line, 0, $brace));
        }

        return $line;
    }
}
