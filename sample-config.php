<?php
// just add feed url's to download from other blogs
$feeds = array(
  'http://www.berri.dk/feed/',
);

// where to put the downloaded files
$target = dirname(__FILE__) . '/library/';

// if you change this you should also change the path in the "db.php" file.
$sqlite = dirname(__FILE__) . '/lefetch.db';

// add your email address here to recive an email when new music has ben downloaded.
$mailto = '';
