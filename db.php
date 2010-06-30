#!/usr/bin/env php
<?php
/**
 * only thing this does is create the sqlite database 
 * used to store feed update information in
 */

$db = new SQLiteDatabase(dirname(__FILE__) . '/lefetch.db');
$query = "
  CREATE TABLE feeds (
    id INTEGER PRIMARY KEY ASC,
    name TEXT,
    updated_at NUMERIC
  );
";
$db->query($query);
