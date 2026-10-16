<?php

declare(strict_types=1);

use NConf\Db\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testPlaceholdersHasOneMarkPerValue(): void
    {
        self::assertSame('?, ?, ?', Sql::placeholders([4, 5, 6]));
    }

    public function testPlaceholdersRefusesAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::placeholders([]);
    }

    public function testIdentifierQuotesAPlainName(): void
    {
        self::assertSame('`attr_value`', Sql::identifier('attr_value'));
    }

    public function testIdentifierRefusesInjectedSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::identifier('id; DROP TABLE ConfigItems');
    }

    public function testIdentifierRefusesABacktick(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::identifier('a`b');
    }
}
