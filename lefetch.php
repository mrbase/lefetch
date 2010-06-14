#!/usr/bin/env php
<?php

// just add feed url's to download from other blogs
$feeds = array(
  'http://www.berri.dk/feed/',
);

$target = '/path/to/mp3/target/dir/';
$sqlite = '/path/to/lefetch.db';
$mailto = ''; // add your email address here to recive an email when new music has ben downloaded.

// that's it for settings...

$db = new SQLiteDatabase($sqlite);

$new = array();
foreach ($feeds as $feed) {
  $rss = simplexml_load_file($feed, 'SimpleXMLElement', LIBXML_NOCDATA);

  $title = (string) str_replace(array('http://', '/', ' '), array('', '', '_'), $rss->channel->title);
  $updated = $rss->channel->lastBuildDate ? strtotime($rss->channel->lastBuildDate) : strtotime($rss->channel->pubDate);
  $base = $target . $title . '/';

  if (!is_dir($base)) {
    mkdir($base, 0775, true);
  }

  $result = $db->query("SELECT id, updated_at FROM feeds WHERE name = '".sqlite_escape_string($title)."'");

  if ($result instanceof SQLiteResult && $result->numRows()) {
    $record = $result->fetch();
  }
  else {
    $record = array();
    $db->query("INSERT INTO feeds (name, updated_at) VALUES ('" . sqlite_escape_string($title) . "', 0)");
    $record['id'] = $db->lastInsertRowid();
    $record['updated_at'] = 0;
  }

  $record = (object) $record;

  if ($record->updated_at >= $updated) {
    continue;
  }

  $db->query("UPDATE feeds SET updated_at = '{$updated}' WHERE id = {$record->id}");

  $new[$title] = array();
  foreach ($rss->channel->item as $item)
  {
    if (strtotime($item->pubDate) <= $record->updated_at) {
      continue;
    }

    $item->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $encoded = $item->xpath('content:encoded');
    if ($encoded[0] instanceof SimpleXMLElement) {
      preg_match_all('~href="((?:.+).mp3)"~i', $encoded[0][0], $matches);

      if (count($matches[1])) {
        foreach ($matches[1] as $file) {

          $url = urldecode($file);
          $out = $base .  str_replace(
            array('"', ' ', '/'), 
            array('', '_', ''), 
            basename($url)
          );

          if (is_file($out)) {
            continue;
          }

          exec("/usr/bin/wget -q \"{$file}\" -O \"{$out}\"");
          $new[$title][] = basename($url);
        }
      }
    }

    $content = (string) $item->description;

    foreach ($item->enclosure as $part)
    {
      $raw = (string) $part['url'];

      if (substr($raw, -3) !== 'mp3') {
        continue;
      }

      $url = urldecode($raw);
      $out = $base .  str_replace(
        array('"', ' ', '/'), 
        array('', '_', ''), 
        basename($url)
      );

      if (is_file($out)) {
        continue;
      }

      exec("/usr/bin/wget -q {$raw} -O \"{$out}\"");
      $new[$title][] = basename($url);
    }
  }

  if (empty($new[$title])) {
    unset($new[$title]);
  }
}

if ($mailto) {
  if (count($new)) {
    $title = "Nye numre hentet:";
    $content = "";
  
    foreach ($new as $title => $items) {
      $content .= $title.":\n";
      foreach ($items as $item) {
        $content .= ' · ' . $item . "\n";
      }
    }
  
    mail($mailto, $title, $content);
  }
}

