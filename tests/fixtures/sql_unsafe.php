<?php
// Fixture for CheckSqlConcatTest: each statement pastes a value into SQL.

$query = 'SELECT * FROM ConfigItems WHERE id_item = "' . $_GET['id'] . '"';
$query = "DELETE FROM ConfigItems WHERE id_item = $id";
$query = 'SELECT * FROM ConfigItems WHERE id_item = ' . Request::get('id');
$query = 'SELECT * FROM ConfigItems WHERE ' . $filter->column . ' = 1';
