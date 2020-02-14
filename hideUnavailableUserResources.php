<?php
  // find connection details
  $configFile = fopen("/usr/local/vufind-5.0/local/config/vufind/config.ini", "r");
  $section = null;
  $mysqlProperties = [];
  $indexProperties = [];
  while( $line = fgets($configFile) ) {
    if( substr($line, 0, 1) == "[" ) {
      $section = substr($line, 1, strpos($line, "]") - 1);
    } else if( $section == "ScriptMysql" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $mysqlProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    } else if( $section == "Index" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $indexProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    }
  }
  fclose( $configFile );

  // get mysql connection
  $link = mysqli_connect($mysqlProperties["host"], $mysqlProperties["user"], $mysqlProperties["password"], $mysqlProperties["postgresScannerDbname"]);

  // see how many records that are currently not hidden
  $count = mysqli_fetch_assoc(mysqli_query($link, "select count(distinct(record_id)) as num from user_resource join resource on (resource_id=resource.id) where hideFlag='N'"));
  $index = 0;
  $mysqlPageSize = 20000;
  $solrPageSize = 100;

  // page through them
  while( $index < $count["num"] ) {
    $hideBibs = [];
    $results = mysqli_query($link, "select distinct(record_id),resource.id from user_resource join resource on (resource_id=resource.id) where hideFlag='N' limit " . $index . "," . $mysqlPageSize);
    while( $thisRow = mysqli_fetch_assoc($results) ) {
      // create the Solr request and our master list of bibs we're checking for
      $idsToCheck = [$thisRow["record_id"] => 1];
      $curl_url = $indexProperties["url"] . "/biblio/select?fl=id&q=*:*&fq=id:\"" . $thisRow["record_id"] . "\"";

      // request these in solr-sized pages
      for( $i=1; $i<$solrPageSize && $thisRow = mysqli_fetch_assoc($results); $i++ ) {
        $curl_url .= "+OR+id:\"" .  $thisRow["record_id"] . "\"";
        $idsToCheck[$thisRow["record_id"]] = 1;
      }
      $curl_url .= "&rows=" . $solrPageSize;

      // make the request
      $solrInfo = curl_init($curl_url);
      curl_setopt($solrInfo, CURLOPT_RETURNTRANSFER, true);
      $solrInfo = json_decode( curl_exec( $solrInfo ), true )["response"]["docs"] ?? false;

      // scan through and remove any that we found
      for( $i=0; $solrInfo && $i<count($solrInfo); $i++ ) {
        unset($idsToCheck[$solrInfo[$i]["id"]]);
      }

      // everything that's not found needs to be flagged as hidden
      $hideBibs = array_merge($hideBibs, $idsToCheck);
    }

    // now hide those resources
    if( count($hideBibs) ) {
      $hideQuery = "update user_resource join resource on (resource_id=resource.id) set hideFlag='Y' where record_id in (";
      $addComma = false;
      foreach( $hideBibs as $bib => $value ) {
        $hideQuery .= ($addComma ? "," : "") . "\"" . $bib . "\"";
        $addComma = true;
      }
      $hideQuery .= ")";
      mysqli_query($link, $hideQuery);
      //echo "Hid " . count($hideBibs) . " previously shown bibs\n";
    }

    // next page
    $index += $mysqlPageSize;
  }



  // see how many records that are currently hidden
  $count = mysqli_fetch_assoc(mysqli_query($link, "select count(distinct(record_id)) as num from user_resource join resource on (resource_id=resource.id) where hideFlag='Y'"));
  $index = 0;

  // page through them
  while( $index < $count["num"] ) {
    $showBibs = [];
    $results = mysqli_query($link, "select distinct(record_id),resource.id from user_resource join resource on (resource_id=resource.id) where hideFlag='Y' limit " . $index . "," . $mysqlPageSize);
    while( $thisRow = mysqli_fetch_assoc($results) ) {
      // create the Solr request and our master list of bibs we're checking for
      $curl_url = $indexProperties["url"] . "/biblio/select?fl=id&q=*:*&fq=id:\"" . $thisRow["record_id"] . "\"";

      // request these in solr-sized pages
      for( $i=1; $i<$solrPageSize && $thisRow = mysqli_fetch_assoc($results); $i++ ) {
        $curl_url .= "+OR+id:\"" .  $thisRow["record_id"] . "\"";
      }
      $curl_url .= "&rows=" . $solrPageSize;

      // make the request
      $solrInfo = curl_init($curl_url);
      curl_setopt($solrInfo, CURLOPT_RETURNTRANSFER, true);
      $solrInfo = json_decode( curl_exec( $solrInfo ), true )["response"]["docs"] ?? false;

      // scan through and add any that we found
      for( $i=0; $solrInfo && $i<count($solrInfo); $i++ ) {
        $showBibs[$solrInfo[$i]["id"]] = 1;
      }
    }

    // now show those resources
    if( count($showBibs) ) {
      $showQuery = "update user_resource join resource on (resource_id=resource.id) set hideFlag='N' where record_id in (";
      $addComma = false;
      foreach( $showBibs as $bib => $value ) {
        $showQuery .= ($addComma ? "," : "") . "\"" . $bib . "\"";
        $addComma = true;
      }
      $showQuery .= ")";
      mysqli_query($link, $showQuery);
      //echo "Showed " . count($showBibs) . " previously hidden bibs\n";
    }

    // next page
    $index += $mysqlPageSize;
  }

  mysqli_close($link);
?>