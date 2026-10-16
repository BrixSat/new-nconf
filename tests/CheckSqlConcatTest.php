<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckSqlConcatTest extends TestCase
{
    /**
     * @return array{int, string}
     */
    private function check(string $fixture): array
    {
        $tool = dirname(__DIR__) . '/tools/check_sql_concat.php';
        $output = [];
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' '
                . escapeshellarg(__DIR__ . '/fixtures/' . $fixture) . ' 2>/dev/null',
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }

    public function testPastedValueIsReported(): void
    {
        [$exitCode, $output] = $this->check('sql_unsafe.php');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('sql_unsafe.php:4', $output);
    }

    public function testInterpolatedValueIsReported(): void
    {
        [, $output] = $this->check('sql_unsafe.php');

        self::assertStringContainsString('sql_unsafe.php:5', $output);
    }

    public function testUnknownStaticCallIsReported(): void
    {
        [, $output] = $this->check('sql_unsafe.php');

        self::assertStringContainsString('sql_unsafe.php:6', $output);
    }

    public function testPropertyIsReported(): void
    {
        [, $output] = $this->check('sql_unsafe.php');

        self::assertStringContainsString('sql_unsafe.php:7', $output);
    }

    public function testBoundAndAllowedCodePasses(): void
    {
        [$exitCode] = $this->check('sql_safe.php');

        self::assertSame(0, $exitCode);
    }
}
