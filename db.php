#!/usr/bin/env php
<?php

$db = new SQLiteDatabase('./lefetch.db');

$query = "
  CREATE TABLE feeds (
    id INTEGER PRIMARY KEY ASC,
    name TEXT,
    updated_at NUMERIC
  );
";
$db->query($query);

