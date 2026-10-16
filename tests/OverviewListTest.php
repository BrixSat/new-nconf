<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OverviewListTest extends TestCase
{
    public function testTextFilterIsBoundNotPasted(): void
    {
        $where = overview_where(overview_columns('contact'), ['name' => "x' OR '1'='1"]);

        self::assertStringNotContainsString("OR '1'", $where->sql());
        self::assertSame(["%x' OR '1'='1%"], $where->params());
    }

    public function testLikeWildcardsInAFilterMatchLiterally(): void
    {
        $where = overview_where(overview_columns('contact'), ['name' => '50%_a!']);

        self::assertSame(['%50!%!_a!!%'], $where->params());
    }

    public function testSelectFilterMatchesExactly(): void
    {
        $where = overview_where(overview_columns('host'), ['os' => 'Linux']);

        self::assertSame(' WHERE os = ?', $where->sql());
        self::assertSame(['Linux'], $where->params());
    }

    public function testSkippedColumnIsLeftOut(): void
    {
        $where = overview_where(overview_columns('host'), ['os' => 'Linux', 'name' => 'web'], 'os');

        self::assertSame(['%web%'], $where->params());
    }

    public function testNoFiltersGiveNoWhere(): void
    {
        $where = overview_where(overview_columns('host'), ['name' => '', 'os' => '']);

        self::assertSame('', $where->sql());
    }

    public function testParseFiltersDropsUnknownKeys(): void
    {
        $filters = overview_parse_filters(['name' => 'a', 'evil' => 'b'], overview_columns('contact'));

        self::assertSame(['name' => 'a'], $filters);
    }

    public function testParseFiltersIgnoresArrays(): void
    {
        $filters = overview_parse_filters(['name' => ['x']], overview_columns('contact'));

        self::assertSame(['name' => ''], $filters);
    }

    public function testParseFiltersTrimsAndCutsLongValues(): void
    {
        $filters = overview_parse_filters(['name' => '  ' . str_repeat('a', 300)], overview_columns('contact'));

        self::assertSame(OVERVIEW_FILTER_MAX_LENGTH, mb_strlen($filters['name']));
    }

    public function testSortDirectionOfTheOrderedColumn(): void
    {
        self::assertSame('descending', overview_sort_direction('BIN_IP DESC', 'BIN_IP'));
    }

    public function testSortDirectionOfAnotherColumnIsNull(): void
    {
        self::assertNull(overview_sort_direction('hostname ASC', 'os'));
    }

    public function testFilterControlEscapesItsValue(): void
    {
        $html = overview_filter_control(new OverviewColumn('name', 'entryname'), '"><script>', [], 'name');

        self::assertStringNotContainsString('<script>', $html);
    }
}
