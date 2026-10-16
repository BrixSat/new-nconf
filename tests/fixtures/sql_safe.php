<?php
// Fixture for CheckSqlConcatTest: bound values, allowed helpers and a marked exception.

$query = 'SELECT * FROM ConfigItems WHERE id_item = ?';
$query = 'SELECT * FROM ConfigItems WHERE id_item IN (' . Sql::placeholders($ids) . ')';
$query = 'SELECT ' . \NConf\Db\Sql::identifier($column) . ' FROM ConfigItems LIMIT ' . (int) $limit;
$query = 'SELECT * FROM ConfigClasses WHERE nav_privs = "' . NConf\Gui::PRIV_ADMIN . '"';
$query = 'SELECT * FROM ConfigItems ORDER BY ' . $order; // sql-safe: $order is from an allowlist
$html = '<a href="detail.php?id=' . $id . '">';
