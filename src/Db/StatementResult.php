<?php

declare(strict_types=1);

namespace NConf\Db;

use mysqli_result;

/*
 * Written for PHP 7.3.4 as well as 8.x: no typed properties or readonly, so
 * immutability comes from private properties that are only set here.
 */
final class StatementResult
{
    /** @var mysqli_result|bool */
    private $result;

    /** @var int */
    private $affectedRows;

    /** @var int|string */
    private $insertId;

    /**
     * @param mysqli_result|bool $result result set of a SELECT-like statement, TRUE for statements without one
     * @param int|string $insertId
     */
    public function __construct($result, int $affectedRows, $insertId)
    {
        $this->result = $result;
        $this->affectedRows = $affectedRows;
        $this->insertId = $insertId;
    }

    /**
     * @return mysqli_result|bool
     */
    public function result()
    {
        return $this->result;
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    /**
     * @return int|string
     */
    public function insertId()
    {
        return $this->insertId;
    }
}
