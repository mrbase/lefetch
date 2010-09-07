#!/usr/bin/env php
<?php

// load configuration
$cfg = dirname(__FILE__) . '/config.php';
if (!is_file($cfg)) {
  die("
woops!
 the configuration file is missing.
 copy the sample-config.php file to config.php
 edit the settings within and try agian.

");
}

require $cfg;

// check database
if (!is_file($sqlite)) {
  die("
woops!
 remember to run 'db.php' to create the sqlite database.

");
}

define('DEBUG', ((isset($argv[1]) && $argv[1] = '--debug') ? true : false));

/**
 * downloader function
 * uses stream_copy_to_stream to avoid running into memory limits or wget system calls
 *
 * @param string $file
 * @param string $target
 * @param int $date
 * @return int
 */
function download($file, $target, $date) {
  $out = $target .  str_replace(
    array('"', ' '),
    array('', '_',),
    basename(urldecode($file))
  );

  if (is_file($out)) {
    return;
  }

  $src = fopen($file, 'r');
  $dest = fopen($out, 'wb+');

  if (DEBUG) { echo "- downloading:\n    {$file} to\n    {$out}\n"; }

  $result = (bool) stream_copy_to_stream($src, $dest);

  // close file handlers
  fclose($src);
  fclose($dest);

  // set file time.
  touch($out, $date, $date);

  return $result;
}

// open up sqlite database
$db = new SQLiteDatabase($sqlite);
if (!$db instanceof  SQLiteDatabase) {
  die("hey hey... the database {$sqlite} could not be opened.. i'm bailing out.\n");
}

if (DEBUG) { echo "\nStarting feed fetcher run...\n"; }

$new = array();
foreach ($feeds as $feed) {
  $rss = simplexml_load_file($feed, 'SimpleXMLElement', LIBXML_NOCDATA);

  $title = (string) str_replace(array('http://', '/', ' '), array('', '', '_'), $rss->channel->title);
  $updated = $rss->channel->lastBuildDate ? strtotime($rss->channel->lastBuildDate) : strtotime($rss->channel->pubDate);
  $base = $target . $title . '/';

  if (DEBUG) { echo "- parsing '{$title}' target dir is: {$base}\n"; }

  // create target dir if it does not exist
  if (!is_dir($base)) {
    if (DEBUG) { echo "- creating target dir: {$base}\n"; }
    mkdir($base, 0775, true);
  }

  $result = $db->query("
    SELECT
      id,
      updated_at
    FROM
      feeds
    WHERE
      name = '" . sqlite_escape_string($title) . "'
  ");

  $record = array();
  if ($result instanceof SQLiteResult && $result->numRows()) {
    $record = $result->fetch();
  }
  else {
    $db->query("
      INSERT INTO
        feeds (
          name,
          updated_at
        ) VALUES (
          '" . sqlite_escape_string($title) . "',
          0
        )
    ");
    $record['id'] = $db->lastInsertRowid();
    $record['updated_at'] = 0;
  }

  $record = (object) $record;

  // only process new posts
  if ($record->updated_at >= $updated) {
    if (DEBUG) { echo "- no new feed entries, skipping to next feed.\n"; }
    continue;
  }

  $db->query("
    UPDATE
      feeds
    SET
      updated_at = '{$updated}'
    WHERE
      id = {$record->id}
  ");

  $new[$title] = array();
  foreach ($rss->channel->item as $item) {
    // skip feeds that has not changed since last run.
    $published = strtotime($item->pubDate);
    if ($published <= $record->updated_at) {
      if (DEBUG) { echo "- not a new entry, skipping.\n"; }
      continue;
    }

    // we need to set the content namepace to get embeded media files
    $item->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $encoded = $item->xpath('content:encoded');

    if ($encoded[0] instanceof SimpleXMLElement) {
      preg_match_all('~href="((?:.+).mp3)"~i', $encoded[0][0], $matches);

      if (count($matches[1])) {
        foreach ($matches[1] as $file) {
          download($file, $target . $title . '/', $published);
        }
      }
    }

    // loop through media attachments
    foreach ($item->enclosure as $part) {
      $file = (string) $part['url'];

      // only fetch mp3 files
      if (substr($file, -3) !== 'mp3') {
        continue;
      }

      download($file, $target . $title . '/', $published);
    }
  }

  if (empty($new[$title])) {
    unset($new[$title]);
  }
}

if (DEBUG) { echo "- out of feeds.\n"; }

// if an email address is set, send a notification with any new files fetched
if ($mailto && count($new)) {
  $title = "New numbers:";
  $content = "";

  foreach ($new as $title => $items) {
    $content .= $title.":\n";
    foreach ($items as $item) {
      $content .= ' · ' . $item . "\n";
    }
  }

  if (DEBUG) { echo "- status mail sent to {$mailto}\n"; }
  mail($mailto, $title, $content);
}

if (DEBUG) { echo "we are done! over and out.\n\n"; }
