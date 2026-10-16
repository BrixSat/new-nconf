<?php

declare(strict_types=1);

namespace NConf\Db;

use InvalidArgumentException;
use mysqli;

/*
 * Prepared-statement helpers behind db_handler().
 * Values always travel as bound parameters, never inside the SQL text; the
 * only things pasted into SQL are placeholders and identifiers that passed
 * Sql::identifier().
 * Written for PHP 7.3.4 as well as 8.x: no union types, no execute($params).
 */
final class Sql
{
    /**
     * Prepares and runs $sql with one value per "?".
     *
     * @param array<mixed> $params callers are untyped legacy pages, so each value is checked here
     * @return StatementResult|false false when MySQL rejects the statement (mysqli reporting is off
     *                               in this app, so callers read mysqli_error() as with mysqli_query())
     * @throws InvalidArgumentException for values that cannot be bound (arrays, objects)
     */
    public static function run(mysqli $dbh, string $sql, array $params)
    {
        $values = [];
        foreach ($params as $param) {
            // legacy lookups return FALSE for "not found"; pasted into SQL that read as '', so bind it the same way
            if (is_bool($param)) {
                $param = $param ? '1' : '';
            }
            if (!is_int($param) && !is_float($param) && !is_string($param) && $param !== null) {
                throw new InvalidArgumentException(
                    'SQL parameters must be int, float, string or null, got '
                        . (is_object($param) ? get_class($param) : gettype($param))
                );
            }
            // all as strings ("s"), as pasted SQL compared them; MySQL converts for numeric columns
            $values[] = ($param === null) ? null : (string) $param;
        }

        $stmt = mysqli_prepare($dbh, $sql);
        if ($stmt === false) {
            return false;
        }
        // bind_param takes references, so it gets the elements of a local array
        if ($values !== [] && !mysqli_stmt_bind_param($stmt, str_repeat('s', count($values)), ...$values)) {
            return false;
        }
        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        $result = mysqli_stmt_get_result($stmt);
        if ($result === false) {
            // no result set is normal for INSERT/UPDATE/DELETE; an error number means it failed
            if (mysqli_stmt_errno($stmt) !== 0) {
                return false;
            }
            $result = true;
        }

        // int, or a numeric string beyond PHP_INT_MAX; the PHP 7.3 stubs only promise mixed
        $insertId = mysqli_stmt_insert_id($stmt);
        if (!is_int($insertId) && !is_string($insertId)) {
            $insertId = 0;
        }

        return new StatementResult(
            $result,
            (int) mysqli_stmt_affected_rows($stmt),
            $insertId
        );
    }

    /**
     * "?, ?, ?" for an IN (...) list of count($values) entries.
     *
     * @param array<mixed> $values
     * @throws InvalidArgumentException on an empty list: "IN ()" is invalid SQL, callers must skip the query
     */
    public static function placeholders(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('An IN list needs at least one value.');
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * A table or column name that must go into the SQL text, because identifiers cannot be bound.
     *
     * @throws InvalidArgumentException when $name is not a plain identifier
     */
    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Not a valid SQL identifier: ' . $name);
        }

        return '`' . $name . '`';
    }
}
