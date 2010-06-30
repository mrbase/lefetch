#!/usr/bin/env php
<?php

// just add feed url's to download from other blogs
$feeds = array(
  'http://www.berri.dk/feed/',
);

// where to put the downloaded files
$target = dirname(__FILE__) . '/libaray/';

// if you change this you should also change the path in the "db.php" file.
$sqlite = dirname(__FILE__) . '/lefetch.db';

// add your email address here to recive an email when new music has ben downloaded.
$mailto = '';

// that's it for settings...

/**
 * downloader function
 *
 * @param string $file
 * @param string $target
 * @return int
 */
function download($file, $target) {
  $out = $target .  str_replace(
    array('"', ' '),
    array('', '_',),
    basename(urldecode($file))
  );

  if (is_file($out)) {
    continue;
  }

  $src = fopen($file, 'r');
  $dest = fopen($out, 'wb+');

  return stream_copy_to_stream($src, $dest);
}

// open up sqlite database
$db = new SQLiteDatabase($sqlite);
if (!$db instanceof  SQLiteDatabase) {
  die("hey hey... the database {$sqlite} could not be opened.. i'm bailing out.\n");
}

$new = array();
foreach ($feeds as $feed) {
  $rss = simplexml_load_file($feed, 'SimpleXMLElement', LIBXML_NOCDATA);

  $title = (string) str_replace(array('http://', '/', ' '), array('', '', '_'), $rss->channel->title);
  $updated = $rss->channel->lastBuildDate ? strtotime($rss->channel->lastBuildDate) : strtotime($rss->channel->pubDate);
  $base = $target . $title . '/';

  // create target dir if it does not exist
  if (!is_dir($base)) {
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
    if (strtotime($item->pubDate) <= $record->updated_at) {
      continue;
    }

    // we need to set the content namepace to get embeded media files
    $item->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $encoded = $item->xpath('content:encoded');

    if ($encoded[0] instanceof SimpleXMLElement) {
      preg_match_all('~href="((?:.+).mp3)"~i', $encoded[0][0], $matches);

      if (count($matches[1])) {
        foreach ($matches[1] as $file) {
          download($file, $target . $title . '/');
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

      download($file, $target . $title . '/');
    }
  }

  if (empty($new[$title])) {
    unset($new[$title]);
  }
}

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

  mail($mailto, $title, $content);
}
