<?php

declare(strict_types=1);

/*
 * Description: Fails when a PHP statement builds SQL by pasting a variable or
 *              call result into an SQL string instead of binding it as a "?"
 *              parameter (see src/Db/Sql.php).
 * Version:     1.03.000
 * Usage:       php tools/check_sql_concat.php [path ...]   (default: project root)
 *              composer check-sql
 * Env vars:    (none)
 * Depends:     php 7.3.4+ or 8.x (tokenizer)
 * Concurrent:  Yes — read-only.
 *
 * Allowed inside SQL: string literals, constants, Sql::placeholders(),
 * Sql::identifier() (NConf\Db\Sql), and (int)/(float) casts. A statement that is safe for a
 * reason this check cannot see is marked with a "sql-safe: <reason>" comment.
 */

/** Upper-case SQL keywords: the code base writes SQL in capitals, HTML and messages rarely are. */
const SQL_HINT = '/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|WHERE|VALUES|FROM|JOIN|AND|OR|IN|LIKE|SET|ORDER\s+BY|GROUP\s+BY|HAVING|LIMIT)\b/';

/** Functions whose result is safe to paste into SQL text. */
const SAFE_FUNCTIONS = ['count', 'intval', 'floatval'];

/** Static methods (class short name::method, lower case) whose result is safe to paste into SQL text. */
const SAFE_STATIC_METHODS = ['sql::placeholders', 'sql::identifier'];

const SKIP_DIRS = ['vendor', '.git', 'tests', 'tools', 'temp', 'output'];

// PHP 7.3 has no single-token names or "?->"; SqlToken::tokenize() builds names under these ids
if (!defined('T_NAME_QUALIFIED')) {
    define('T_NAME_QUALIFIED', -101);
}
if (!defined('T_NAME_FULLY_QUALIFIED')) {
    define('T_NAME_FULLY_QUALIFIED', -102);
}
if (!defined('T_NULLSAFE_OBJECT_OPERATOR')) {
    define('T_NULLSAFE_OBJECT_OPERATOR', -103);
}

/**
 * PhpToken (PHP 8.0+) for PHP 7.3 too: same is()/text/line, and names such as
 * \NConf\Db\Sql come out as one token on every version.
 */
final class SqlToken
{
    /** @var int token id, or ord() of a single-character token */
    public $id;

    /** @var string */
    public $text;

    /** @var int */
    public $line;

    public function __construct(int $id, string $text, int $line)
    {
        $this->id = $id;
        $this->text = $text;
        $this->line = $line;
    }

    /**
     * @param int|string|array<int|string> $kind token id(s) or exact text(s), as PhpToken::is()
     */
    public function is($kind): bool
    {
        foreach (is_array($kind) ? $kind : [$kind] as $one) {
            if (is_int($one) ? $this->id === $one : $this->text === $one) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<SqlToken>
     */
    public static function tokenize(string $code): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($code) as $raw) {
            $token = is_array($raw)
                ? new self($raw[0], $raw[1], $raw[2])
                : new self(ord($raw), $raw, $line);
            $line = $token->line + substr_count($token->text, "\n");

            // PHP 7.3 tokenizes "\A\B" as "\", "A", "\", "B"; join them like PHP 8 does
            $last = $tokens === [] ? null : $tokens[count($tokens) - 1];
            $joinable = $token->is([T_NS_SEPARATOR, T_STRING]);
            if ($joinable && $last !== null
                && ($last->is([T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                    || ($last->is(T_STRING) && $token->is(T_NS_SEPARATOR)))
            ) {
                $text = $last->text . $token->text;
                $id = ($text[0] === '\\') ? T_NAME_FULLY_QUALIFIED : T_NAME_QUALIFIED;
                $tokens[count($tokens) - 1] = new self($id, $text, $last->line);
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }
}

/**
 * @return list<string>
 */
function php_files(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                return !($f->isDir() && in_array($f->getFilename(), SKIP_DIRS, true));
            }
        )
    );
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Splits a token stream into statements at ";" (and "{" / "}"), keeping only significant tokens.
 *
 * @param list<SqlToken> $tokens
 * @return list<list<SqlToken>>
 */
function statements(array $tokens): array
{
    $statements = [];
    $current = [];
    $endLine = -1;
    foreach ($tokens as $token) {
        if ($token->is([T_WHITESPACE, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML])) {
            continue;
        }
        // a comment on the line a statement ended on ("...; // sql-safe: ...") belongs to that statement
        if ($current === [] && $statements !== [] && $token->is(T_COMMENT) && $token->line === $endLine) {
            $statements[count($statements) - 1][] = $token;
            continue;
        }
        $current[] = $token;
        $endLine = $token->line;
        if ($token->text === ';' || $token->text === '{' || $token->text === '}') {
            $statements[] = $current;
            $current = [];
        }
    }
    if ($current !== []) {
        $statements[] = $current;
    }

    return $statements;
}

function is_sql_literal(SqlToken $token): bool
{
    if (!$token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
        return false;
    }

    return preg_match(SQL_HINT, $token->text) === 1;
}

/**
 * Index of the "(" matching the ")" at $close, or -1.
 *
 * @param list<SqlToken> $stmt
 */
function matching_open(array $stmt, int $close): int
{
    $depth = 0;
    for ($i = $close; $i >= 0; $i--) {
        if ($stmt[$i]->text === ')') {
            $depth++;
        } elseif ($stmt[$i]->text === '(') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return -1;
}

/**
 * "class::method" (short class name, lower case) when $stmt[$method] is the method of a static call.
 *
 * @param list<SqlToken> $stmt
 */
function static_call_name(array $stmt, int $method): ?string
{
    $colons = $stmt[$method - 1] ?? null;
    $class = $stmt[$method - 2] ?? null;
    if ($colons === null || !$colons->is(T_DOUBLE_COLON) || $class === null) {
        return null;
    }
    $parts = explode('\\', $class->text);

    return strtolower(end($parts) . '::' . $stmt[$method]->text);
}

/**
 * Whether the operand that ends at $i (left of a ".") or starts at $i (right of it) is unsafe.
 *
 * @param list<SqlToken> $stmt
 */
function unsafe_operand(array $stmt, int $i, bool $leftSide): bool
{
    $token = $stmt[$i] ?? null;
    if ($token === null) {
        return false;
    }
    if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_INT_CAST, T_DOUBLE_CAST])) {
        return false;
    }
    if ($token->is(T_VARIABLE)) {
        // right side: "(int)$x" never reaches here; a bare variable is unsafe on either side
        return !($leftSide && isset($stmt[$i - 1]) && $stmt[$i - 1]->is([T_INT_CAST, T_DOUBLE_CAST]));
    }
    if ($token->is(T_STRING) || $token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED)) {
        // $obj->name / $obj?->name: a property, not a constant
        $previous = $stmt[$i - 1] ?? null;
        if ($leftSide && $previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return true;
        }
        $next = $stmt[$i + 1] ?? null;
        // Class::method(...) is a call; Class::CONSTANT is not
        if ($next !== null && $next->is(T_DOUBLE_COLON)) {
            $member = $stmt[$i + 2] ?? null;
            $after = $stmt[$i + 3] ?? null;
            if ($member === null || $after === null || $after->text !== '(') {
                return false;
            }

            return !in_array(static_call_name($stmt, $i + 2), SAFE_STATIC_METHODS, true);
        }
        if ($next !== null && $next->text === '(') {
            return !in_array(strtolower(ltrim($token->text, '\\')), SAFE_FUNCTIONS, true);
        }

        return false; // a constant
    }
    if ($leftSide && $token->text === ')') {
        $open = matching_open($stmt, $i);
        $before = $stmt[$open - 1] ?? null;
        $staticCall = ($before !== null && $before->is(T_STRING)) ? static_call_name($stmt, $open - 1) : null;
        if ($staticCall !== null) {
            return !in_array($staticCall, SAFE_STATIC_METHODS, true);
        }
        if ($before !== null && ($before->is(T_STRING) || $before->is(T_NAME_FULLY_QUALIFIED))) {
            return !in_array(strtolower(ltrim($before->text, '\\')), SAFE_FUNCTIONS, true);
        }
        // a parenthesised expression or a cast like "(int)($x)": look inside its first operand
        $first = $stmt[$open + 1] ?? null;

        return !($first !== null && $first->is([T_INT_CAST, T_DOUBLE_CAST]));
    }
    if ($token->text === '(') {
        $inner = $stmt[$i + 1] ?? null;

        return !($inner !== null && $inner->is([T_INT_CAST, T_DOUBLE_CAST, T_CONSTANT_ENCAPSED_STRING]));
    }
    if ($leftSide && $token->text === ']') {
        return true; // $row['x'] and the like
    }
    if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) || $token->is(T_DOUBLE_COLON)) {
        return true;
    }

    return false;
}

/**
 * @param list<SqlToken> $stmt
 */
function statement_is_unsafe(array $stmt): bool
{
    $hasSql = false;
    foreach ($stmt as $token) {
        if (is_sql_literal($token)) {
            $hasSql = true;
            break;
        }
    }
    if (!$hasSql) {
        return false;
    }

    foreach ($stmt as $i => $token) {
        // "...$var..." interpolation inside an SQL string
        if ($token->text === '"' || $token->is(T_START_HEREDOC)) {
            for ($j = $i + 1; isset($stmt[$j]) && $stmt[$j]->text !== '"' && !$stmt[$j]->is(T_END_HEREDOC); $j++) {
                if ($stmt[$j]->is([T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    return true;
                }
            }
        }
        if ($token->text !== '.') {
            continue;
        }
        if (unsafe_operand($stmt, $i - 1, true) || unsafe_operand($stmt, $i + 1, false)) {
            return true;
        }
    }

    return false;
}

/**
 * Line of the statement's first code token; comments before it would point at the wrong line.
 *
 * @param list<SqlToken> $stmt
 */
function first_code_line(array $stmt): int
{
    foreach ($stmt as $token) {
        if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
            return $token->line;
        }
    }

    return $stmt[0]->line;
}

/**
 * @param list<SqlToken> $stmt
 */
function is_marked_safe(array $stmt): bool
{
    foreach ($stmt as $token) {
        if ($token->is([T_COMMENT, T_DOC_COMMENT]) && strpos($token->text, 'sql-safe:') !== false) {
            return true;
        }
    }

    return false;
}

$root = dirname(__DIR__);
$args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$paths = array_values(array_filter(array_slice($args, 1), 'is_string'));
if ($paths === []) {
    $paths = [$root];
}

$findings = [];
foreach ($paths as $path) {
    foreach (php_files($path) as $file) {
        $code = file_get_contents($file);
        if ($code === false) {
            fwrite(STDERR, "cannot read $file\n");
            exit(2);
        }
        // comments must stay in the stream so a "sql-safe:" marker is seen with its statement
        $tokens = SqlToken::tokenize($code);
        foreach (statements($tokens) as $stmt) {
            if (statement_is_unsafe($stmt) && !is_marked_safe($stmt)) {
                $findings[] = ltrim(str_replace($root, '', $file), '/') . ':' . first_code_line($stmt);
            }
        }
    }
}

foreach ($findings as $finding) {
    echo $finding, "\n";
}
if ($findings !== []) {
    fwrite(STDERR, count($findings) . " statement(s) paste values into SQL; bind them as ? parameters instead.\n");
    exit(1);
}
echo "No SQL built by concatenation found.\n";
