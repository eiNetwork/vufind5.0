<?php
  // start memcached
  $memcached = new Memcached();
  $memcached->addServer('localhost', 11212);

  // grab the start time for our circ_trans query (the time the last extract ran - 8:00PM yesterday)
  $circTransTime = strftime("%Y-%m-%d %T", strtotime("yesterday 20:00:00")); 
  $now = strftime("%Y-%m-%d %T", time()); 

  // calculate the check digit for a given bib number
  function getCheckDigit($id)
  {
    // pull off the item type if they included it
    if( !is_numeric($id) ) {
      $id = substr($id, 1);
    }
    // make sure it's a number
    if( !is_numeric($id) ) {
      return null;
    }

    // calculate it
    $checkDigit = 0;
    $multiple = 2;
    while( $id > 0 ) {
      $digit = $id % 10;
      $checkDigit += $multiple * $digit;
      $id = ($id - $digit) / 10;
      $multiple++;
    }
    $checkDigit = $checkDigit % 11;
    return ($checkDigit == 10) ? "x" : $checkDigit;
  }

  // get the cache for a given bib
  function getCache($thisRow) 
  {
    global $memcached;

    $cacheKey = "holdingID.b" . $thisRow["bib_num"] . getCheckDigit($thisRow["bib_num"]);
    $cachedStatus = $memcached->get($cacheKey);
    if( !$cachedStatus ) {
      $cachedStatus = ["CACHED_INFO" => []];
    } else if( !isset($cachedStatus["CACHED_INFO"]) ) {
      $cachedStatus["CACHED_INFO"] = [];
    }
    $updateKey = "updatesID.b" . $thisRow["bib_num"] . getCheckDigit($thisRow["bib_num"]);
    $updateStatus = $memcached->get($updateKey);
    if( !$updateStatus ) {
      $updateStatus = [];
    }
    return ["key" => $cacheKey, "value" => $cachedStatus, "updateKey" => $updateKey, "updateValue" => $updateStatus];
  }

  // find connection details
  $configFile = fopen("/usr/local/vufind2/local/config/vufind/config.ini", "r");
  $section = null;
  $postgresProperties = [];
  $mysqlProperties = [];
  while( $line = fgets($configFile) ) {
    if( substr($line, 0, 1) == "[" ) {
      $section = substr($line, 1, strpos($line, "]") - 1);
    } else if( $section == "ScriptPostgres" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $postgresProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    } else if( $section == "ScriptMysql" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $mysqlProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    }
  }
  fclose( $configFile );

  // get mysql connection
  $sqlDB = mysqli_connect($mysqlProperties["host"], $mysqlProperties["user"], $mysqlProperties["password"], $mysqlProperties["circTransDbname"]);

  // get postgres connection
  $db = pg_connect("host=" . $postgresProperties["host"] . " port=" . $postgresProperties["port"] . " dbname=" . $postgresProperties["dbname"] . " user=" . $postgresProperties["user"] . " password=" . $postgresProperties["password"]);

  // this query gets all item status changes happening after the cutoff time
  $results = pg_query("select md1.id as mdID, md1.record_num as item_num, is_suppressed, md2.record_num as bib_num, item_status_code, md1.record_last_updated_gmt, due_gmt " .
                      "from sierra_view.record_metadata as md1 " .
                      "join sierra_view.bib_record_item_record_link on md1.id=bib_record_item_record_link.item_record_id " .
                      "join sierra_view.record_metadata as md2 on bib_record_item_record_link.bib_record_id=md2.id " .
                      "join sierra_view.item_record on md1.id=item_record.id " .
                      "left join sierra_view.checkout on checkout.item_record_id=md1.id " .
                      "where md1.record_last_updated_gmt between '" . $circTransTime . "' and '" . $now . "' and md1.record_type_code='i' " .
                      "order by md1.record_last_updated_gmt desc");
  while($thisRow = pg_fetch_array($results)) {
    $cache = getCache($thisRow);

    // make sure we don't have a more current status for this item
    if( ($cache["updateValue"][$thisRow["item_num"]]["time"] ?? null) > $thisRow["record_last_updated_gmt"] ) {
      continue;
    }

    // build this change
    $thisChange = ["status" => $thisRow["item_status_code"], "duedate" => ($thisRow["due_gmt"] ?? "NULL"), "dateOnly" => ($thisRow["due_gmt"] ? strftime("%m-%d-%y", strtotime($thisRow["due_gmt"])) : null), "inum" => $thisRow["item_num"], "bnum" => $thisRow["bib_num"], "time" => $thisRow["record_last_updated_gmt"], "suppressed" => ($thisRow["is_suppressed"] == "t"), "handled" => false];

    // get this item if it exists
    $thisCachedItem = null;
    foreach( ($cache["value"]["CACHED_INFO"]["holding"] ?? []) as $thisItem ) {
      if( $thisItem["itemId"] == $thisChange["inum"] ) {
        $thisCachedItem = $thisItem;
      }
    }

    // see if we already handled it
    if( isset($thisCachedItem) && ($thisCachedItem["status"] == $thisChange["status"]) && ($thisCachedItem["duedate"] != $thisChange["dateOnly"]) ) {
      $thisChange["handled"] = true;
    }

    // push it to the changes to make
    $cache["updateValue"][$thisRow["item_num"]] = $thisChange;
    $memcached->set($cache["updateKey"], $cache["updateValue"]);
  }
?>