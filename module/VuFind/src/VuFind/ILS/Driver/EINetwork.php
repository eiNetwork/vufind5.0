<?php

/**
 * EINetwork-specific adaptation of SierraRest ILS driver
 */

namespace VuFind\ILS\Driver;

use Memcached;

class EINetwork extends SierraRest implements
    \VuFind\Db\Table\DbTableAwareInterface
{
    use \VuFind\Db\Table\DbTableAwareTrait;

    protected $memcached = null;

    /**
     * Mappings from item status codes to VuFind strings
     *
     * @var array
     */
    protected $itemStatusMappings = [
        '!' => 'ON HOLDSHELF',
        't' => 'IN TRANSIT',
        'o' => 'NONCIRCULATING',
        'k' => 'REPAIR',
        'm' => 'MISSING',
        'n' => 'BILLED',
        '$' => 'LOST AND PAID',
        'p' => 'DISPLAY',
        'z' => 'CLMS RETD',
        's' => 'ON SEARCH',
        'd' => 'DAMAGED',
        'q' => 'BINDERY',
        '%' => 'ILL RETURNED',
        'f' => 'LONG OVERDUE',
        '$' => 'LOST AND PAID',
        'v' => 'ONLINE',
        'y' => 'ONLINE REFERENCE',
        '^' => 'RENOVATION',
        'r' => 'REPAIR',
        'u' => 'STAFF USE',
        '?' => 'STORAGE',
        'w' => 'ILL RETURNED',
        'i' => 'IN PROCESSING',
        'order' => 'ON ORDER'
    ];
    protected $itemStatusReverseMappings;

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        parent::init();

        // start memcached
        $this->memcached = new Memcached();
        $this->memcached->addServer('localhost', 11211);
        if( !$this->memcached->get("globalRefreshTimer") ) {
            $this->memcached->set("globalRefreshTimer", time());
        }

        $this->itemStatusReverseMappings = array_flip($this->itemStatusMappings);
        $this->itemStatusReverseMappings['AVAILABLE'] = "-";
        $this->itemStatusReverseMappings['CHECKED OUT'] = "-";
        $this->itemStatusReverseMappings['NONCIRCULATING'] = "Lib Use Only";
    }

    public function getSessionVar($name) {
        return isset($this->sessionCache[$name]) ? $this->sessionCache[$name] : null;
    }

    public function setSessionVar($name, $value) {
        $this->sessionCache[$name] = $value;
    }

    public function clearSessionVar($name) {
        unset($this->sessionCache[$name]);
    }

    public function getMemcachedVar($name) {
        return $this->memcached->get($name);
    }

    public function setMemcachedVar($name, $value, $time=null) {
        if( $time ) {
            $this->memcached->set($name, $value, $time);
        } else {
            $this->memcached->set($name, $value);
        }
    }



    public function getClassicLink($bib) {
        return $this->config["Catalog"]["classic_url"] . "/record=" . ((substr($bib, 0, 2) == ".b") ? substr($bib, 1, -1) : $bib);
    }

    public function getCurrentLocation() {
        $myIP = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
        if( !$this->memcached->get("locationByIP" . $myIP) ) {
            $this->memcached->set("locationByIP" . $myIP, $this->getDbTable('Location')->getCurrentLocation($myIP));
        }
        return $this->memcached->get("locationByIP" . $myIP);
    }

    public function getHolding($id, array $patron = null)
    {
        // see if it's there
        if( ($overDriveId = $this->getOverDriveID($id)) ) {
            $availability = $this->getProductAvailability($overDriveId);
            return [["id" => $id,
                     "location" => "OverDrive",
                     "isOverDrive" => true,
                     "isOneClick" => false,
                     "copiesOwned" => $availability->collections[0]->copiesOwned,
                     "copiesAvailable" => $availability->collections[0]->copiesAvailable,
                     "numberOfHolds" => $availability->collections[0]->numberOfHolds,
                     "availability" => ($availability->collections[0]->copiesAvailable > 0)
                   ]];
        }

        $cachedInfo = ($this->memcached->get("holdingID" . $id) && ($this->memcached->get("holdingID" . $id))["CACHED_INFO"]) ? ($this->memcached->get("holdingID" . $id))["CACHED_INFO"] : null;

        if( $cachedInfo && !$cachedInfo["doUpdate"] && isset($cachedInfo["holding"]) ) {
            $results = $cachedInfo["holding"];

            // if we haven't processed these holdings yet, run through the order records
            if( !isset($cachedInfo["processedHoldings"]) && ($cachedJson = $this->memcached->get("cachedJson" . $id)) !== null ) {
                if( isset($cachedJson["orderRecords"]) ) {
                    foreach( $cachedJson["orderRecords"] as $locationCode => $details ) {
                        $results[] = [
                                         "id" => $id,
                                         "itemId" => null,
                                         "availability" => false,
                                         "status" => "order",
                                         "location" => $details["location"],
                                         "reserve" => "N",
                                         "callnumber" => null,
                                         "duedate" => null,
                                         "returnDate" => false,
                                         "number" => null,
                                         "barcode" => null,
                                         "locationCode" => $locationCode,
                                         "copiesOwned" => $details["copies"]
                                     ];
                    }
                }
            }
        } else {
            $results = parent::getHolding($id, $patron);
        }

        // make any status updates we are supposed to be making
        if( $changes = $this->memcached->get("updatesID" . $id) ) {
            foreach( $changes as $key => $thisChange ) {
                // if they've already been taken care of, ignore them
                if( !$thisChange["handled"] ) {
                    foreach( $results as $hKey => $thisHolding ) {
                        if( $thisHolding["itemId"] == $thisChange["inum"] ) {
                            if( isset($thisChange["status"]) ) {
                                $thisHolding["status"] = $thisChange["status"];
                            }
                            if( isset($thisChange["duedate"]) ) {
                                $thisHolding["duedate"] = ($thisChange["duedate"] != "NULL") ? strftime("%m-%d-%y", strtotime($thisChange["duedate"])) : null;
                                $thisHolding["availability"] = (($thisChange["status"] == "-") && !$thisHolding["duedate"]);
                            }
                            $results[$hKey] = $thisHolding;
                        }
                    }
                }
            }
            if( ($cache = $this->memcached->get("holdingID" . $id)) && isset($cache["CACHED_INFO"]["holding"]) ) {
                $cache["CACHED_INFO"]["holding"] = $results;
                $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                $this->memcached->set("holdingID" . $id, $cache, $time);
            }
        }

        // add in the extra details we need
        $results2 = [];
        for($i=0; $i<count($results); $i++) {
            // throw out online items
            if( $results[$i]['location'] == "xronl" ) {
                continue;
            }

            // clean call number
            $pieces = explode("|f", $results[$i]['callnumber']);
            $results[$i]['callnumber'] = "";
            foreach( $pieces as $piece ) {
                $results[$i]['callnumber'] .= (($results[$i]['callnumber'] == "") ? "" : "<br>") . trim($piece);
            }

            // get shelving details
            if( !$this->memcached->get("shelvingLocationByCode" . $results[$i]['location']) ) {
                $this->memcached->set("shelvingLocationByCode" . $results[$i]['location'], $this->getDBTable('shelvinglocation')->getByCode($results[$i]['location']));
            }
            $shelfLoc = $this->memcached->get("shelvingLocationByCode" . $results[$i]['location'] );
            $locationId = (isset($shelfLoc) && $shelfLoc) ? $shelfLoc->locationId : null;
            if( $locationId && !$this->memcached->get("locationByID" . $locationId) ) {
                $this->memcached->set("locationByID" . $locationId, $this->getDBTable('location')->getByLocationId($locationId));
            } else if( !$locationId && (strlen($results[$i]['location']) == 2) && !$this->memcached->get("locationByCode" . $results[$i]['location']) ) {
                $this->memcached->set("locationByCode" . $results[$i]['location'], $this->getDBTable('location')->getByCode($results[$i]['location']));
            }
            $location = $locationId ? $this->memcached->get("locationByID" . $locationId ) : ((strlen($results[$i]['location']) == 2) ? $this->memcached->get("locationByCode" . $results[$i]['location']) : null);
            $results[$i]['branchName'] = $location ? $location->displayName : (($results[$i]['status'] == 'order') ? $results[$i]['location'] : null);
            $results[$i]['branchCode'] = $location ? $location->code : null;
            $results[$i]['shelvingLocation'] = $shelfLoc ? $shelfLoc->shortName : null;

            for($j=0; $j<count($results2) && (($results[$i]['branchName'] > $results2[$j]['branchName']) || (($results[$i]['branchName'] == $results2[$j]['branchName']) && ($results[$i]['number'] > $results2[$j]['number']))); $j++) {}
            array_splice($results2, $j, 0, [$results[$i]]);
        }

        // if this is a magazine, we need to add the checkin records info
        if( $this->isSerial($id) ) {
            // get all of the locations we need to speak for
            $neededLocations = [];
            foreach( $results2 as $thisItem ) {
                if( !isset($neededLocations[$thisItem["location"]]) ) {
                    $neededLocations[$thisItem["location"]] = $thisItem["location"];
                }
            }

            // grab the checkin records and store the location info
            $results3 = [];
            if( $cachedInfo && !$cachedInfo["doUpdate"] && isset($cachedInfo["checkinRecords"]) ) {
                $checkinRecords = $cachedInfo["checkinRecords"];
            } else {
                $checkinRecords = $this->getCheckinRecords($id);
            }

            foreach( array_keys($checkinRecords) as $key ) {
                // find this location in the database
                if( !$this->memcached->get("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"])) ) {
                    $this->memcached->set("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"]), $this->getDBTable('shelvinglocation')->getBySierraName($checkinRecords[$key]["location"])->toArray());
                }
                $checkinRecords[$key]["code"] = [];
                $checkinRecords[$key]["branchCode"] = [];
                foreach( $this->memcached->get("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"])) as $row ) {
                    $checkinRecords[$key]["code"][] = $row["code"];
                    $checkinRecords[$key]["branchCode"][] = $row["branchCode"];
                    unset($neededLocations[$row["code"]]);
                }
                $results3[] = $checkinRecords[$key];
            }

            // add details for locations with no checkin records but held items
            foreach( $neededLocations as $code ) {
                if( !$this->memcached->get("shelvingLocationByCode" . $code) ) {
                    $this->memcached->set("shelvingLocationByCode" . $code, $this->getDBTable('shelvinglocation')->getByCode($code));
                }
                $shelfLoc = $this->memcached->get("shelvingLocationByCode" . $code );
                if( $shelfLoc == null ) {
                    if( !$this->memcached->get("locationByCode" . $code) ) {
                        $this->memcached->set("locationByCode" . $code, $this->getDBTable('location')->getByCode($code));
                    }
                    $shelfLoc = $this->memcached->get("locationByCode" . $code );
                }
                $thisCode = [];
                $thisCode["location"] = isset($shelfLoc->sierraName) ? $shelfLoc->sierraName : $shelfLoc->displayName;
                $thisCode["code"][] = $code;
                $thisCode["branchCode"][] = isset($shelfLoc->branchCode) ? $shelfLoc->branchCode : $code;
                for( $j=0; $j<count($results3) && ($results3[$j]['location'] < $thisCode["location"]); $j++) {}
                array_splice($results3, $j, 0, [$thisCode]);
                unset($neededLocations[$code]);
            }

            array_splice($results2, 0, 0, [["id" => $id, "location" => "CHECKIN_RECORDS", "availability" => false, "status" => "?", "items" => [], "copiesOwned" => 0, "checkinRecords" => $results3]]);
        }
        return $results2;
    }

    /**
     * Get status for an item
     *
     * @param array $item Item from Sierra
     *
     * @return array Status string, possible due date and any notes
     */
    protected function getItemStatus($item)
    {
        $duedate = '';
        $notes = [];
        $statusCode = trim($item['status']['code']);
        if (isset($this->itemStatusMappings[$statusCode])) {
            $status = $this->itemStatusMappings[$statusCode];
        } else {
            $status = isset($item['status']['display'])
                ? ($item['status']['display'])
                : '-';
        }
        $status = trim($status);

        // For some reason at least API v2.0 returns "ON SHELF" even when the
        // item is out. Use duedate to check if it's actually checked out.
        if (isset($item['status']['duedate'])) {
            $duedate = $this->dateConverter->convertToDisplayDate(
                \DateTime::ISO8601,
                $item['status']['duedate']
            );
            $status = 'CHECKED OUT';
        } else {
            switch ($status) {
            case '-':
                $status = 'AVAILABLE';
                break;
            case 'Lib Use Only':
                $status = 'NONCIRCULATING';
                break;
            }
        }
        if ($status == 'AVAILABLE') {
            // Check for checkin date
            $today = $this->dateConverter->convertToDisplayDate('U', time());
            if (isset($item['fixedFields']['68'])) {
                $checkedIn = $this->dateConverter->convertToDisplayDate(
                    \DateTime::ISO8601, $item['fixedFields']['68']['value']
                );
                if ($checkedIn == $today) {
                    $notes[] = $this->translate('Returned today');
                }
            }
        }
        return [$status, $duedate, $notes];
    }

    /**
     * Get Item Statuses
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return array An associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    protected function getItemStatusesForBib($id)
    {
        // sanitize the id if necessary
        if( substr($id, 0, 2) == ".b" ) {
            $id = substr($id, 2, -1);
        }

        $results = parent::getItemStatusesForBib($id);

        // add in the status code
        foreach( $results as $hKey => $thisHolding ) {
            $thisHolding["statusCode"] = $this->itemStatusReverseMappings[$thisHolding["status"]];
            $results[$hKey] = $thisHolding;
        }

        return $results;
    }

    /**
     * Convenience function to test whether a given Solr ID value corresponds to an OverDrive item
     *
     * @param  string $id a Solr ID value
     *
     * @return mixed  OverDrive ID if the Solr ID maps to an OverDrive item, false if not
     */
    public function getOverDriveID($id) {
        // see if it's there
        if( !$this->memcached->get("overdriveID" . $id) ) {
            // grab a bit more information from Solr
            $solrBaseURL = $this->config['Solr']['url'];
            $curl_url = $solrBaseURL . "/biblio/select?q=*%3A*&fq=id%3A%22" . $id . "%22&fl=econtent_source,externalId&wt=csv";
            $curl_connection = curl_init($curl_url);
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            $sresult = curl_exec($curl_connection);
            $values = explode("\n", $sresult);

            // is it an OverDrive item?
            if( count($values) > 1 && explode(",", $values[1])[0] == "OverDrive" ) {
                $this->memcached->set("overdriveID" . $id, explode(",", $values[1])[1]);
            }
        }

        // send it back
        return $this->memcached->get("overdriveID" . $id);
    }

    /**
     * Test Serial
     *
     * This checks the API to see if this bib has a serial type.
     *
     * @param string $id The record id to test the bibLevel
     *
     * @return bool  Whether or not this bib is a serial type (used to determine if we need to look for checkin records)
     */
    public function isSerial($id)
    {
        // grab a bit more information from Solr
        $solrBaseURL = $this->config['Solr']['url'];
        $curl_url = $solrBaseURL . "/biblio/select?q=*%3A*&fq=id%3A%22" . strtolower($id) . "%22&fl=bib_level&wt=csv";
        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        $sresult = curl_exec($curl_connection);
        $values = explode("\n", $sresult);

        // is it a Solr item?
        return (count($values) > 2) && ($values[1] == "s");
    }

    /**
     * Translate location name
     *
     * @param array $location Location
     *
     * @return string
     */
    protected function translateLocation($location)
    {
        return $location['code'];
    }
}