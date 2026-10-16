<?php

declare(strict_types=1);

/*
 * Server-side filtering, sorting and paging for overview.php.
 * Column expressions and sort orders come from the fixed lists below, never
 * from the request; only filter values reach the SQL, and only as bound
 * parameters.
 * Written for PHP 7.3.4 as well as 8.x: no typed properties, union types,
 * match, arrow functions or array spread.
 */

use NConf\Db\Sql;

/** Shown, and filtered on, for hosts without a linked collector. */
const OVERVIEW_NOT_MONITORED = 'not monitored';

/** Longer filter values are cut, so a pasted blob cannot bloat every query. */
const OVERVIEW_FILTER_MAX_LENGTH = 200;

/** GET form that owns the filter controls (they join it via form=""). */
const OVERVIEW_FILTER_FORM = 'overview_filter';

final class OverviewColumn
{
    /** @var string request key: filter[<key>] */
    private $key;

    /** @var string SQL over one overview row (derived table t); fixed, never user input */
    private $expression;

    /** @var bool dropdown of the values present, matched exactly, instead of a text box */
    private $isSelect;

    public function __construct(string $key, string $expression, bool $isSelect = false)
    {
        $this->key = $key;
        $this->expression = $expression;
        $this->isSelect = $isSelect;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function isSelect(): bool
    {
        return $this->isSelect;
    }
}

final class SqlCondition
{
    /** @var string */
    private $sql;

    /** @var list<string> one per "?" in $sql */
    private $params;

    /**
     * @param list<string> $params one per "?" in $sql
     */
    public function __construct(string $sql, array $params)
    {
        $this->sql = $sql;
        $this->params = $params;
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return list<string>
     */
    public function params(): array
    {
        return $this->params;
    }
}

final class OverviewPage
{
    /** @var mysqli_result */
    private $rows;

    /** @var int rows matching the filters */
    private $total;

    /** @var int rows before filtering */
    private $unfiltered;

    /** @var int offset actually used: past the end it is moved to the last page */
    private $start;

    /** @var array<string, array<int|string, int>> */
    private $options;

    /**
     * @param array<string, array<int|string, int>> $options select column key => value => rows holding it
     *                                                    (numeric values become int keys in PHP arrays)
     */
    public function __construct(mysqli_result $rows, int $total, int $unfiltered, int $start, array $options)
    {
        $this->rows = $rows;
        $this->total = $total;
        $this->unfiltered = $unfiltered;
        $this->start = $start;
        $this->options = $options;
    }

    public function rows(): mysqli_result
    {
        return $this->rows;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function unfiltered(): int
    {
        return $this->unfiltered;
    }

    public function start(): int
    {
        return $this->start;
    }

    /**
     * @return array<string, array<int|string, int>>
     */
    public function options(): array
    {
        return $this->options;
    }
}

/**
 * @return array<string, OverviewColumn> keyed by OverviewColumn::key()
 */
function overview_columns(string $class): array
{
    switch ($class) {
        case 'host':
            $columns = [
                new OverviewColumn('name', 'hostname'),
                new OverviewColumn('ip', 'IP'),
                new OverviewColumn('collector', "IFNULL(NULLIF(collector, ''), '" . OVERVIEW_NOT_MONITORED . "')", true),
                new OverviewColumn('os', 'os', true),
            ];
            break;
        case 'service':
            // the list shows "host: service", so that is what the filter searches
            $columns = [new OverviewColumn('name', "CONCAT_WS(': ', hostname, entryname)")];
            break;
        case 'checkcommand':
            // the order decides which name is shown first; either one matches
            $columns = [new OverviewColumn('name', "CONCAT_WS(' ', entryname, default_service_name)")];
            break;
        default:
            $columns = [new OverviewColumn('name', 'entryname')];
    }

    $keyed = [];
    foreach ($columns as $column) {
        $keyed[$column->key()] = $column;
    }

    return $keyed;
}

/**
 * ORDER BY clauses the header links may request; the first is the default.
 *
 * @return list<string>
 */
function overview_orders(string $class): array
{
    $orders = [
        'host' => [
            'hostname ASC',
            'hostname DESC',
            'BIN_IP ASC',
            'BIN_IP DESC',
            'collector ASC',
            'collector DESC',
            'os ASC',
            'os DESC',
        ],
        'service' => [
            'hostname ASC, entryname ASC',
            'hostname DESC, entryname DESC',
        ],
        'checkcommand' => [
            'entryname ASC',
            'entryname DESC',
            'sorting ASC, entryname ASC',
            'sorting DESC, entryname DESC',
        ],
    ];

    return $orders[$class] ?? ['entryname ASC', 'entryname DESC'];
}

/**
 * aria-sort value for $column under $order, or null when the list is ordered
 * by another column; a null $column matches whatever column comes first.
 */
function overview_sort_direction(string $order, ?string $column = null): ?string
{
    $first = explode(' ', explode(',', $order)[0]);
    if ($column !== null && $first[0] !== $column) {
        return null;
    }

    return (($first[1] ?? 'ASC') === 'DESC') ? 'descending' : 'ascending';
}

/**
 * @param mixed $raw filter[] from the request or the session
 * @param array<string, OverviewColumn> $columns
 * @return array<string, string> column key => trimmed value ('' = not filtered)
 */
function overview_parse_filters($raw, array $columns): array
{
    $filters = [];
    foreach ($columns as $key => $column) {
        $value = is_array($raw) ? ($raw[$key] ?? '') : '';
        $filters[$key] = is_string($value)
            ? mb_substr(trim($value), 0, OVERVIEW_FILTER_MAX_LENGTH)
            : '';
    }

    return $filters;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $changes
 */
function overview_url(array $state, array $changes = []): string
{
    return 'overview.php?' . http_build_query(array_merge($state, $changes));
}

/**
 * @param array<string, OverviewColumn> $columns
 * @param array<string, string> $filters
 * @param ?string $skipKey leaves one column's own filter out, for listing its options
 */
function overview_where(array $columns, array $filters, ?string $skipKey = null): SqlCondition
{
    $clauses = [];
    $params = [];
    foreach ($filters as $key => $value) {
        if ($value === '' || $key === $skipKey || !isset($columns[$key])) {
            continue;
        }
        $column = $columns[$key];
        if ($column->isSelect()) {
            $clauses[] = $column->expression() . ' = ?';
            $params[] = $value;
        } else {
            // "!" as escape character works whatever sql_mode says about backslashes
            $clauses[] = $column->expression() . " LIKE ? ESCAPE '!'"; // sql-safe: fixed column expression
            $params[] = '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
    }

    return new SqlCondition(
        // sql-safe: only fixed column expressions and "?" placeholders are joined here
        $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        $params
    );
}

/**
 * @param list<string> $params
 * @throws RuntimeException when MySQL rejects the query (mysqli reporting is off in this app)
 */
function overview_query(mysqli $dbh, string $sql, array $params): mysqli_result
{
    $statement = Sql::run($dbh, $sql, $params);
    if ($statement === false) {
        throw new RuntimeException('Overview query failed: ' . mysqli_error($dbh));
    }
    $result = $statement->result();
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Overview query returned no result set.');
    }

    return $result;
}

/**
 * @param list<string> $fromParams values for the "?"s in $from, which come before the WHERE ones
 */
function overview_count(mysqli $dbh, string $from, array $fromParams, SqlCondition $where): int
{
    $result = overview_query(
        $dbh,
        'SELECT COUNT(*) AS n' . $from . $where->sql(), // sql-safe: parts built here, values bound
        array_merge($fromParams, $where->params())
    );

    return (int) (mysqli_fetch_assoc($result)['n'] ?? 0);
}

/**
 * Runs $baseQuery (one row per item, without ORDER BY or LIMIT) as a derived
 * table, so filters and order can use its computed columns by name.
 *
 * @param array<string, OverviewColumn> $columns
 * @param array<string, string> $filters
 * @param string $orderBy from overview_orders() plus a unique tie-breaker, so pages never overlap
 * @param int $pageSize 0 = all rows
 * @param list<string> $baseParams values for the "?"s in $baseQuery; the caller never pastes values into it
 */
function overview_fetch_page(
    mysqli $dbh,
    string $baseQuery,
    array $columns,
    array $filters,
    string $orderBy,
    int $start,
    int $pageSize,
    array $baseParams = []
): OverviewPage {
    $from = ' FROM (' . $baseQuery . ') AS t'; // sql-safe: base query values arrive in $baseParams
    $where = overview_where($columns, $filters);

    $total = overview_count($dbh, $from, $baseParams, $where);
    $unfiltered = ($where->sql() === '')
        ? $total
        : overview_count($dbh, $from, $baseParams, new SqlCondition('', []));

    if ($pageSize === 0) {
        $start = 0;
    } elseif ($start >= $total) {
        $start = ($total > 0) ? intdiv($total - 1, $pageSize) * $pageSize : 0;
    }
    // both values are ints, and bound as strings LIMIT is rejected by some MySQL versions
    $limit = ($pageSize > 0) ? ' LIMIT ' . $start . ', ' . $pageSize : ''; // sql-safe: two ints

    $rows = overview_query(
        $dbh,
        // sql-safe: $orderBy comes from overview_orders(), the rest is built here with "?"s
        'SELECT *' . $from . $where->sql() . ' ORDER BY ' . $orderBy . $limit,
        array_merge($baseParams, $where->params())
    );

    // each dropdown lists only the values reachable under the other filters
    $options = [];
    foreach ($columns as $key => $column) {
        if (!$column->isSelect()) {
            continue;
        }
        $others = overview_where($columns, $filters, $key);
        $result = overview_query(
            $dbh,
            // sql-safe: fixed column expression, values bound
            'SELECT ' . $column->expression() . ' AS opt_value, COUNT(*) AS n'
                . $from . $others->sql()
                . ' GROUP BY opt_value',
            array_merge($baseParams, $others->params())
        );
        $counts = [];
        while ($row = mysqli_fetch_assoc($result)) {
            if ($row['opt_value'] === null || $row['opt_value'] === '') {
                continue;
            }
            $counts[(string) $row['opt_value']] = (int) $row['n'];
        }
        $options[$key] = $counts;
    }

    return new OverviewPage($rows, $total, $unfiltered, $start, $options);
}

/**
 * Header cell whose link re-sorts the list; $labelHtml is trusted markup
 * (friendly names and FRIENDLY_NAME_* constants).
 *
 * @param ?string $direction aria-sort value when the list is ordered by this column
 */
function overview_header_cell(
    string $labelHtml,
    string $href,
    ?string $direction,
    string $attributes = ''
): string {
    $sorted = '';
    $indicator = '';
    if ($direction !== null) {
        $sorted = ' class="ntsf-sorted" aria-sort="' . $direction . '"';
        $indicator = '<span class="ntsf-ind" aria-hidden="true">'
            . ($direction === 'ascending' ? '▲' : '▼')
            . '</span>';
    }

    return '<td' . $attributes . $sorted . '>'
        . '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" data-list-link>'
        . $labelHtml
        . '</a>' . $indicator . '</td>';
}

/**
 * @param array<int|string, int> $counts value => rows holding it, from OverviewPage::options()
 */
function overview_filter_control(OverviewColumn $column, string $value, array $counts, string $label): string
{
    $esc = static function (string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };
    $common = ' class="ntsf-filter" name="filter[' . $esc($column->key()) . ']"'
        . ' form="' . OVERVIEW_FILTER_FORM . '"'
        . ' aria-label="' . $esc('Filter ' . $label) . '"';

    if (!$column->isSelect()) {
        // size="1": the column's data, not the box's default width, decides how wide the column is
        return '<input type="search"' . $common . ' value="' . $esc($value) . '" placeholder="filter" size="1">';
    }

    // a chosen value stays listed even when the other filters leave no rows for it
    if ($value !== '' && !array_key_exists($value, $counts)) {
        $counts[$value] = 0;
    }
    uksort($counts, static function ($a, $b): int {
        return strnatcasecmp((string) $a, (string) $b);
    });

    $html = '<select' . $common . '><option value="">all</option>';
    foreach ($counts as $option => $count) {
        $option = (string) $option;
        $selected = ($option === $value) ? ' selected' : '';
        $html .= '<option value="' . $esc($option) . '"' . $selected . '>'
            . $esc($option . ' (' . $count . ')')
            . '</option>';
    }

    return $html . '</select>';
}

/**
 * Pager arrow; without $href it is drawn disabled and loses its pointer cursor.
 */
function overview_pager_link(string $id, string $iconHtml, ?string $href): string
{
    if ($href === null) {
        return '<a id="' . $id . '" class="disabled" aria-disabled="true">'
            . str_replace('pointer ', '', $iconHtml)
            . '</a>';
    }

    return '<a id="' . $id . '" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" data-list-link>'
        . $iconHtml
        . '</a>';
}
