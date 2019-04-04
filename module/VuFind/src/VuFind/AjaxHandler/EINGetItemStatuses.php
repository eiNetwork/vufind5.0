<?php
/**
 * "EIN Get Item Status" AJAX handler
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2018.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  AJAX
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Chris Delis <cedelis@uillinois.edu>
 * @author   Tuan Nguyen <tuan@yorku.ca>
 * @author   Brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
namespace VuFind\AjaxHandler;

use VuFind\Exception\ILS as ILSException;
use VuFind\I18n\Translator\TranslatorAwareInterface;
use VuFind\ILS\Connection;
use VuFind\ILS\Logic\Holds;
use VuFind\Record\Loader;
use VuFind\Session\Settings as SessionSettings;
use Zend\Config\Config;
use Zend\Mvc\Controller\Plugin\Params;
use Zend\View\Renderer\RendererInterface;

/**
 * "EIN Get Item Status" AJAX handler
 *
 * This is responsible for printing the holdings information for a
 * collection of records in JSON format.
 *
 * @category VuFind
 * @package  AJAX
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Chris Delis <cedelis@uillinois.edu>
 * @author   Tuan Nguyen <tuan@yorku.ca>
 * @author   Brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class EINGetItemStatuses extends GetItemStatuses
{
    /**
     * Record loader
     *
     * @var Loader
     */
    protected $loader;

    /**
     * Logged in user (or false)
     *
     * @var User|bool
     */
    protected $user;

    /**
     * Constructor
     *
     * @param SessionSettings   $ss        Session settings
     * @param Config            $config    Top-level configuration
     * @param Connection        $ils       ILS connection
     * @param RendererInterface $renderer  View renderer
     * @param Holds             $holdLogic Holds logic
     * @param Loader            $loader    Record loader
     * @param User|bool         $user      Logged in user (or false)
     */
    public function __construct(SessionSettings $ss, Config $config, Connection $ils,
        RendererInterface $renderer, Holds $holdLogic, $loader, $user
    ) {
        parent::__construct($ss, $config, $ils, $renderer, $holdLogic);

        $this->loader = $loader;
        $this->user = $user;
    }

    /**
     * Support method for getItemStatuses() -- process a single bibliographic record
     * for location settings other than "group".
     *
     * @param array  $record            Information on items linked to a single bib
     *                                  record
     * @param array  $messages          Custom status HTML
     *                                  (keys = available/unavailable)
     * @param string $locationSetting   The location mode setting used for
     *                                  pickValue()
     * @param string $callnumberSetting The callnumber mode setting used for
     *                                  pickValue()
     * @param array  $holds             The logged in user's holds
     * @param array  $checkedOutItems   The logged in user's checked out items
     *
     * @return array                    Summarized availability information
     */
    protected function getItemStatus($record, $messages, $locationSetting,
        $callnumberSetting, $holds = [], $checkedOutItems = []
    ) {
        // grab the driver
        $bib = $record[0]['id'];
        $fullID = is_numeric($bib) ? (".b" . $bib . $this->ils->getCheckDigit($bib)) : $bib;
        $shortID = (is_numeric($bib) || (substr($bib, 0, 2) != ".b")) ? $bib : substr($bib, 2, -1);
        $driver = $this->loader->load( $fullID );
        $canHold = $driver->tryMethod('getRealTimeTitleHold');
        $isHolding = false;
        $isOverDrive = false;
        $isOneClick = false;
        $isCheckedOut = false;
        $accessOnline = $driver->hasOnlineAccess();
        $overDriveInfo = ["canCheckOut" => false];
        $holdArgs = "";
        $hasVolumes = false;

        // see whether or not this bib has different volumes
        foreach($record as $item) {
            if( isset($item["number"]) && $item["number"]) {
                $hasVolumes = true;
            }
            if( isset($item["isOverDrive"]) && $item["isOverDrive"] ) {
              $isOverDrive = true;
              $overDriveInfo["numberOfHolds"] = $item["numberOfHolds"];
              $overDriveInfo["copiesOwned"] = $item["copiesOwned"];
            }
        }

        // see if they already have a hold on it
        if($canHold && $this->user && !$hasVolumes) {
            foreach($holds as $thisHold) {
                if($thisHold['id'] == $shortID) {
                    $canHold = false;
                    $isHolding = true;
                }
            }
        }

        // see which volumes they have a hold on
        $heldVolumes = array();
        if( $hasVolumes ) {
            foreach($record as $item) {
                if( isset($item["number"]) && $item["number"]) {
                    foreach($holds as $thisHold) {
                        if(substr($thisHold["item_id"], 2, -1) == $item["itemId"]) {
                            $heldVolumes[$item["itemId"]] = $item["number"];
                        }
                    }
                }
            }
        }

        // if not, see whether there is a holdable copy available
        if( $isOverDrive && $canHold ) {
            $args=array();
            foreach($record as $item) {
                // look for a hold/checkout link
                $overdriveLinkOK = $isOverDrive && ($item["copiesOwned"] > 0);
                if($overdriveLinkOK && (isset($canHold['action']) && ($canHold['action'] == "Hold"))) {
                    foreach(explode('&',$canHold['query']) as $piece) {
                        $pieces = explode('=', $piece);
                        $args[$pieces[0]] = $pieces[1];
                    }
                    $args["id"] = $canHold["record"];
                    foreach(explode('&',$canHold['query']) as $piece) {
                        $pieces = explode('=', $piece);
                        $args[$pieces[0]] = $pieces[1];
                    }
                    $overDriveInfo["canHold"] = true;
                    $args["action"] = ($item["copiesAvailable"] == 0) ? "placeHold" : "doCheckout";
                    $canHold = ($item["copiesAvailable"] == 0);
                    $overDriveInfo["canCheckOut"] = ($item["copiesAvailable"] > 0);
                    break;
                }
            }
            $holdArgs = str_replace("\"", "'", json_encode($args));
            if( count($args) == 0 ) {
                $holdArgs = "";
            }
        } else if( $canHold ) {
            $args=array();
            foreach($record as $item) {
                // look for a hold link
                $marcHoldOK = isset($item['statusCode']) && in_array(trim($item['statusCode']), ['-','t','!','i','p','order']);
                if($marcHoldOK && (isset($canHold['action']) && ($canHold['action'] == "Hold"))) {
                    foreach(explode('&',$canHold['query']) as $piece) {
                        $pieces = explode('=', $piece);
                        $args[$pieces[0]] = $pieces[1];
                    }
                    $args["id"] = $canHold["record"];
                    foreach(explode('&',$canHold['query']) as $piece) {
                        $pieces = explode('=', $piece);
                        $args[$pieces[0]] = $pieces[1];
                    }
                    break;
                }
            }
            $holdArgs = str_replace("\"", "'", json_encode($args));
            if( count($args) == 0 ) {
                $canHold = false;
                $holdArgs = "";
            }
        }

        // make sure they don't already have it checked out
        if( $this->user && ($overDriveInfo["canCheckOut"] || $canHold) ) {
            foreach($checkedOutItems as $thisItem) {
                if($thisItem['fullID'] == $bib) {
                    $overDriveInfo["canCheckOut"] = false;
                    // if this bib has volumes, they still still place holds on other volumes even if they have one checked out
                    $canHold = $hasVolumes;
                    $isCheckedOut = true;
                    if( isset($thisItem["reserveId"]) ) {
                        $overDriveInfo["canReturn"] = ($thisItem["actions"]["earlyReturn"] ?? false) ? ("/Overdrive/Hold?od_id=".$driver->getOverDriveID()."&rec_id=".$driver->getUniqueID()."&action=returnTitle") : false;
                        $overDriveInfo["availableFormats"] = $thisItem["format"];

                        // get instant links
                        $OD_type_mapping = ['ebook-mediado' => 'mediaDo', 'ebook-overdrive' => 'overdriveRead', 'magazine-overdrive' => 'overdriveRead', 'video-streaming' => 'streamingVideo', 'audiobook-overdrive' => 'overdriveListen'];
                        foreach( $OD_type_mapping as $formatType => $linkKey ) {
                            if( in_array($formatType, $thisItem["availableFormats"]) ) {
                                $overDriveInfo[$linkKey] = $thisItem[$linkKey]->data->downloadLink;
                            }
                        }

                        // get the download links
                        $downloadableFormats = [];
                        $notDownloadableFormats = ['ebook-mediado','magazine-overdrive','ebook-overdrive','video-streaming','audiobook-overdrive'];
                        foreach($thisItem["availableFormats"] as $possibleFormat) {
                            if( !in_array($possibleFormat, $notDownloadableFormats) ) {
                                $downloadableFormats[] = ["id" => $possibleFormat, "URL" => "/Overdrive/SelectFormat?rec_id=".$thisItem['id']."&od_id=".$thisItem['reserveId']."&parentURL="];
                            }
                        }
                        // add the video-streaming option if it's not locked in yet
                        foreach($thisItem["actions"]["format"]["fields"] ?? [] as $thisFormat) {
                            if( $thisFormat["name"] == "formatType" && !$thisItem["isFormatLockedIn"] ) {
                                $downloadableFormats[] = ["id" => $thisFormat["options"][0], "URL" => "/Overdrive/SelectFormat?rec_id=".$thisItem['id']."&od_id=".$thisItem['reserveId']."&parentURL="];
                            }
                        }

                        $overDriveInfo["downloadFormats"] = $downloadableFormats;
                        $overDriveInfo["formatLocked"] = $thisItem["isFormatLockedIn"];
                    }
                }
            }
        }

        // Summarize call number, location and availability info across all items:
        $currentLocation = $this->ils->getCurrentLocation();
        $callNumbers = $locations = $volumeNumbers = [];
        $use_unknown_status = $available = false;
        $services = [];
        $ownedItems = 0;
        $orderedItems = 0;
        $availableItems = 0;
        $libraryOnly = false;
        $availableLocations = [];
        $onOrderLocations = [];
        $unavailableLocations = [];
        $onOrder = false;
        foreach ($record as $info) {
            // Find an available copy
            if ($info['availability']) {
                $available = true;
                $availableItems += (isset($info["copiesAvailable"])) ? $info["copiesAvailable"] : 1;
                if( !$isOverDrive ) {
                    if( !isset($availableLocations[$info['branchName']]) ) {
                        $availableLocations[$info['branchName']] = 0;
                    }
                    $availableLocations[$info['branchName']] += (isset($info["copiesAvailable"])) ? $info["copiesAvailable"] : 1;
                }
            } else if (isset($info['statusCode']) && ((trim($info['statusCode']) == 'order') || (trim($info['statusCode']) == 'i'))) {
                $onOrder = true;
                if( !isset($onOrderLocations[$info['branchName']]) ) {
                    $onOrderLocations[$info['branchName']] = 0;
                }
                $onOrderLocations[$info['branchName']] += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
            } else if( !$isOverDrive && $info['location'] != "CHECKIN_RECORDS" ) {
                if( !isset($unavailableLocations[$info['branchName']]) ) {
                    $unavailableLocations[$info['branchName']] = 0;
                }
                $unavailableLocations[$info['branchName']] += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
            }
            if( isset($info["copiesOwned"]) ) {
                if( isset($info['statusCode']) && ((trim($info['statusCode']) == 'order') || (trim($info['statusCode']) == 'i')) ) {
                    $orderedItems += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
                } else {
                    $ownedItems += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
                }
            }
            // Check for a use_unknown_message flag
            if (isset($info['use_unknown_message'])
                && $info['use_unknown_message'] == true
            ) {
                $use_unknown_status = true;
            }
            // Store call number/location info:
            $callNumbers[] = isset($info['callnumber']) ? $info['callnumber'] : null;
            $volumeNumbers[] = isset($info['number']) ? $info['number'] : null;
            $locations[] = isset($info['location']) ? $info['location'] : null;
            if( !$isOverDrive ) {
                if( (!isset($itsHere) || (trim($itsHere['statusCode']) == 'o')) && $currentLocation && $info['availability'] && ($currentLocation['code'] == ($info['branchCode'] ?? null)) ) {
                    $itsHere = $info;
                } else if( $this->user && !isset($atPreferred) && $info['availability'] && (($info['branchCode'] == $this->user->preferred_library) || ($info['branchCode'] == $this->user->alternate_library) || ($info['branchCode'] == $this->user->home_library)) ) {
                    $atPreferred = true;
                }
                if( !isset($holdableCopyHere) && $currentLocation && $info['availability'] && ($currentLocation["code"] == ($info['branchCode'] ?? null)) && (trim($info['statusCode']) != 'o') && (trim($info['statusCode']) != 'order')) {
                    $holdableCopyHere = $info;
                }
                if( !$canHold && ($info["statusCode"] ?? null) == "o" ) {
                    $libraryOnly = true;
                }
            }
            // Store all available services
            if (isset($info['services'])) {
                $services = array_merge($services, $info['services']);
            }
        }
        $totalItems = $ownedItems + $orderedItems;

        $callnumberHandler = $this->getCallnumberHandler(
            $callNumbers, $callnumberSetting
        );

        // Determine call number string based on findings:
        $callNumber = $this->pickValue(
            $callNumbers, $callnumberSetting, 'Multiple Call Numbers'
        );

        // Determine volume number string based on findings:
        $volumeNumber = $this->pickValue(
            $volumeNumbers, $callnumberSetting, 'Multiple Volumes'
        );

        // Determine location string based on findings:
        $location = $this->pickValue(
            $locations, $locationSetting, 'Multiple Locations', 'location_'
        );

        $checkinRecords = ($record[0]['location'] == "CHECKIN_RECORDS");
        if ($checkinRecords) {
            $checkinRecords = false;
            foreach( $record[0]["checkinRecords"] as $thisRecord ) {
                $checkinRecords |= isset($thisRecord["libHas"]);
            }
        }

        $availability_message = $accessOnline ? ($messages['online'] . ((($ownedItems + $orderedItems) > 0) ? "<br><div style=\"height:5px\"></div>" : "")) : "";
        if (!empty($services)) {
            $availability_message = $this->reduceServices($services);
        } else if( !$accessOnline || (($ownedItems + $orderedItems) > 0) ) {
            $availability_message .= $use_unknown_status
                ? $messages['unknown']
                : $messages[(($onOrder && ($ownedItems == 0)) ? 'order' : 
                             ((isset($itsHere) && $itsHere) ? 'itshere' :
                              ((isset($libraryOnly) && $libraryOnly) ? 'inlibrary' : 
                               ($available ? 'available' : 
                                ($isOneClick ? 'oneclick' : 'unavailable')))))];
            $cache = $this->ils->getMemcachedVar("holdingID" . $bib)["CACHED_INFO"];
            $numberOfHolds = ($cache && !$cache["doUpdate"]) ? $cache["numberOfHolds"] : ($isOverDrive ? $overDriveInfo["numberOfHolds"] : 0);
            $waitlistText = $numberOfHolds ? ("<br><i class=\"fa fa-clock-o\" style=\"padding-right:6px\"></i>" . (($numberOfHolds > 1) ? ($numberOfHolds . " people") : "1 person") . " on waitlist") : "";
            if ($checkinRecords) {
                $inLibMessage = str_replace("<countText>", (count($record[0]["checkinRecords"]) . " location" . ((count($record[0]["checkinRecords"]) == 1) ? "" : "s")) , $messages['inlibrary']);
                $serialCheckinRecords = false;
                foreach( $record[0]["checkinRecords"] as $thisRecord ) {
                    if( $currentLocation && in_array($currentLocation["code"], $thisRecord["branchCode"]) ) {
                        $inLibMessage .= "<div class=\"availableCopyText\">It's here at " . $thisRecord["location"] . "</div>";
                        break;
                    }
                    $serialCheckinRecords |= isset($thisRecord["libHas"]);
                }
                if( $ownedItems > 0 ) {
                    $inLibMessage = [$inLibMessage, str_replace("<countText>", (($ownedItems > 0) ? ($availableItems . " of ") : "") . $ownedItems . " cop" . (($ownedItems == 1) ? "y" : "ies") . $waitlistText, $availability_message)];
                }
                $availability_message = $inLibMessage;
            } else if( $isOverDrive && $overDriveInfo["copiesOwned"] == 999999 ) {
                $availability_message = str_replace("<countText>", "Always Available", $availability_message);
            } else if( $ownedItems == 0 && $orderedItems > 0 ) {
                $availability_message = str_replace("<countText>", $orderedItems . " cop" . (($orderedItems == 1) ? "y" : "ies") . $waitlistText, $availability_message);
            } else {
                $availability_message = str_replace("<countText>", (($ownedItems > 0) ? ($availableItems . " of ") : "") . $ownedItems . " cop" . (($ownedItems == 1) ? "y" : "ies") . $waitlistText, $availability_message);
                if( isset($itsHere) ) {
                    $availability_message = str_replace("<itsHereText>", $itsHere["shelvingLocation"] . ((isset($itsHere["shelvingLocation"]) && isset($itsHere["callnumber"])) ? "<br>" : "") . $itsHere["callnumber"] . (isset($itsHere["number"]) ? (" " . $itsHere["number"]) : ""), $availability_message);
                }
            }
            if( !isset($itsHere) ) {
                if( $isOverDrive ) {
                    $availability_message = str_replace("<modifyAvailableText>", " from OverDrive", $availability_message);
                } else if( isset($atPreferred) ) {
                    $availability_message = str_replace("<modifyAvailableText>", " at your preferred Libraries!", $availability_message);
                } else if( $currentLocation ) {
                    $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " other Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
                } else {
                    $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
                }
            } else {
                $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
            }
        }

        // see if we have any urls we should show
        $urls = $driver->getURLs();
        foreach($urls as $key => $thisUrl) {
          if( $isOverDrive && (strpos($thisUrl["url"], "http://excerpts.contentreserve.com") === false) ):
            unset($urls[$key]);
          elseif( strpos($thisUrl["url"], "http://www.carnegielibrary.org/research/music/pittsburgh/pghlps.html") !== false ):
            unset($urls[$key]);
          elseif( strpos($thisUrl["url"], "http://carnegielbyofpittpa.oneclickdigital.com") !== false ):
            $isOneClick = true;
          endif;
        }

        // Collect the details:
        $details = [
            'id' => $record[0]['id'],
            'fullID' => $fullID,
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'availability_details' => ($availableLocations || $onOrderLocations || $unavailableLocations) ? json_encode(["available" => $availableLocations, "onOrder" => $onOrderLocations, "unavailable" => $unavailableLocations]) : null,
            'location' => htmlentities($location, ENT_COMPAT, 'UTF-8'),
            'locationList' => false,
            'reserve' =>
                ((isset($record[0]['reserve']) && ($record[0]['reserve'] == 'Y')) ? 'true' : 'false'),
            'reserve_message' => (isset($record[0]['reserve']) && ($record[0]['reserve'] == 'Y'))
                ? $this->translate('on_reserve')
                : $this->translate('Not On Reserve'),
            'callnumber' => htmlentities($callNumber, ENT_COMPAT, 'UTF-8'),
            'hasVolumes' => $hasVolumes,
            'volume_number' => htmlentities($volumeNumber, ENT_COMPAT, 'UTF-8'),
            'isOverDrive' => $isOverDrive,
            'isCheckedOut' => $isCheckedOut,
            'isHolding' => $isHolding,
            'checkinRecords' => $checkinRecords,
            'itsHere' => isset($itsHere),
            'holdableCopyHere' => isset($holdableCopyHere),
            'holdArgs' => $holdArgs,
            'libraryOnly' => ($libraryOnly || $checkinRecords),
            'accessOnline' => $accessOnline,
            'heldVolumes' => json_encode($heldVolumes),
            'urls' => json_encode($urls)
        ];

        // add the info URL if we need it for overdrive
        if( $isOverDrive && ($ownedItems == 0) ) {
          $overDriveInfo["learnMoreURL"] = $driver->getURLs()[0]["url"] ?? null;
        }

        // add in the overdrive info if needed
        if( $isOverDrive && ($overDriveInfo["canCheckOut"] || count($overDriveInfo) > 1) ) {
/* maybe need this when we get to that context of the button? not sure. if not, just delete it.
            $renderer = $this->getViewRenderer();
            if( $overDriveInfo["canCheckOut"] ) {
                $overDriveInfo["checkoutLink"] = $renderer->recordLink()->getActionUrl($driver, 'Checkout');
            }
            if( $overDriveInfo["canReturn"] ) {
                $overDriveInfo["returnLink"] = $renderer->recordLink()->getActionUrl($driver, 'Return');
            }
            if( $canHold ) {
                $overDriveInfo["holdLink"] = $renderer->recordLink()->getActionUrl($driver, 'Hold') . "?hashKey=" . json_decode(str_replace("'", "\"", $holdArgs))->hashKey;
            }
            $overDriveInfo["idArgs"] = str_replace("\"", "'", json_encode(["id" => $bib]));
*/
            $details = array_merge($details, $overDriveInfo);
        }

        // Send back the collected details:
        return $details;
    }

    /**
     * Handle a request.
     *
     * @param Params $params Parameter helper from controller
     *
     * @return array [response data, HTTP status code]
     */
    public function handleRequest(Params $params)
    {
        $this->disableSessionWrites();  // avoid session write timing bug
        $ids = $params->fromPost('id', $params->fromQuery('id', []));
        $results = [];
        try {
            foreach( $ids as $thisID ) {
                $driver = $this->loader->load( $thisID );
                // see if we have cached holdings already. if not, grab them.
                if( !($cache = $this->ils->getMemcachedVar("holdingID" . $thisID)) || !isset($cache["CACHED_INFO"]["holding"]) ) {
                    $cachedItems = $driver->getCachedItems();
                    $cache = ["CACHED_INFO" => $cachedItems];
                    $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                    $this->ils->setMemcachedVar("holdingID" . $thisID, $cache, $time);
                }
                $cache = $this->ils->getMemcachedVar("holdingID" . $thisID);
                // see if there are any status updates we are supposed to be making
                $changesToMake = false;
                if( $changes = $this->ils->getMemcachedVar("updatesID" . $thisID) ) {
                    foreach( $changes as $key => $thisChange ) {
                        // if they've already been taken care of, ignore them
                        $changesToMake |= !$thisChange["handled"];
                    }
                }
                if( !isset($cache["CACHED_INFO"]["processedHoldings"]["holdings"]) || $changesToMake ) {
                    $holdings = $driver->getRealTimeHoldings();
                    $cache = $this->ils->getMemcachedVar("holdingID" . $thisID);

                    $cache["CACHED_INFO"]["processedHoldings"] = $holdings;
                    $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                    $this->ils->setMemcachedVar("holdingID" . $thisID, $cache, $time);
                }
                $holdings = $cache["CACHED_INFO"]["processedHoldings"]["holdings"];
                $items = [];
                foreach($holdings as $holding) {
                    $items = array_merge($items, $holding["items"]);
                }
                $results[] = $items;
            }
        } catch (ILSException $e) {
            // If the ILS fails, send an error response instead of a fatal
            // error; we don't want to confuse the end user unnecessarily.
            error_log($e->getMessage());
            foreach ($ids as $id) {
                $results[] = [
                    [
                        'id' => $id,
                        'error' => 'An error has occurred'
                    ]
                ];
            }
        }

        if (!is_array($results)) {
            // If getStatuses returned garbage, let's turn it into an empty array
            // to avoid triggering a notice in the foreach loop below.
            $results = [];
        }

        $holds = [];
        $checkedOutItems = [];
        if($this->user) {
            $patron = $this->ils->patronLogin($this->user->cat_username, $this->user->cat_password);
            $holds = $this->ils->getMyHolds($patron);
            $checkedOutItems = $this->ils->getMyTransactions($patron);
        }

        // In order to detect IDs missing from the status response, create an
        // array with a key for every requested ID.  We will clear keys as we
        // encounter IDs in the response -- anything left will be problems that
        // need special handling.
        $missingIds = array_flip($ids);

        // Load messages for response:
        $messages = [
            'inlibrary' => $this->renderer->render('ajax/status-inlibrary.phtml'),
            'itshere' => $this->renderer->render('ajax/status-itshere.phtml'),
            'available' => $this->renderer->render('ajax/status-available.phtml'),
            'oneclick' => $this->renderer->render('ajax/status-oneclick.phtml'),
            'online' => $this->renderer->render('ajax/status-online.phtml'),
            'unavailable' =>
                $this->renderer->render('ajax/status-unavailable.phtml'),
            'order' => $this->renderer->render('ajax/status-order.phtml'),
            'unknown' => $this->renderer->render('ajax/status-unknown.phtml')
        ];

        // Load callnumber and location settings:
        $callnumberSetting = isset($this->config->Item_Status->multiple_call_nos)
            ? $this->config->Item_Status->multiple_call_nos : 'msg';
        $locationSetting = isset($this->config->Item_Status->multiple_locations)
            ? $this->config->Item_Status->multiple_locations : 'msg';
        $showFullStatus = isset($this->config->Item_Status->show_full_status)
            ? $this->config->Item_Status->show_full_status : false;

        // Loop through all the status information that came back
        $statuses = [];
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                // Check for errors
                if (!empty($record[0]['error'])) {
                    $current = $this
                        ->getItemStatusError($record, $messages['unknown']);
                } elseif ($locationSetting === 'group') {
                    $current = $this->getItemStatusGroup(
                        $record, $messages, $callnumberSetting
                    );
                } else {
                    $current = $this->getItemStatus(
                        $record, $messages, $locationSetting, $callnumberSetting, $holds, $checkedOutItems
                    );
                }
                // If a full status display has been requested, append the HTML:
                if ($showFullStatus) {
                    $current['full_status'] = $this->renderer->render(
                        'ajax/status-full.phtml', [
                            'statusItems' => $record,
                            'callnumberHandler' => $this->getCallnumberHandler()
                         ]
                    );
                }
                $current['record_number'] = array_search($current['id'], $ids);
                $statuses[] = $current;

                // The current ID is not missing -- remove it from the missing list.
                unset($missingIds[$current['fullID']]);
            }
        }

        // If any IDs were missing, send back appropriate dummy data
        foreach ($missingIds as $missingId => $recordNumber) {
            // see if we have any urls we should show
            $driver = $this->loader->load( $missingId );
            $urls = $driver->getURLs();
            foreach($urls as $key => $thisUrl) {
                if( strpos($thisUrl["url"], "http://carnegielbyofpittpa.oneclickdigital.com") !== false ):
                    $isOneClick = true;
                elseif( strpos($thisUrl["url"], "http://www.carnegielibrary.org/research/music/pittsburgh/pghlps.html") !== false ):
                    unset($urls[$key]);
                endif;
            }
            $accessOnline = $driver->hasOnlineAccess();

            $statuses[] = [
                'id'                   => $missingId,
                'fullID'               => $missingId,
                'availability'         => 'false',
                'availability_message' => $messages['unavailable'],
                'availability_details' => false,
                'location'             => $this->translate('Unknown'),
                'locationList'         => false,
                'reserve'              => 'false',
                'reserve_message'      => $this->translate('Not On Reserve'),
                'callnumber'           => '',
                'hasVolumes'           => false,
                'volume_number'        => '',
                'missing_data'         => true,
                'record_number'        => $recordNumber,
                'isHolding'            => false,
                'checkinRecords'       => false,
                'itsHere'              => false,
                'holdableCopyHere'     => false,
                'holdArgs'             => '',
                'accessOnline'         => $accessOnline,
                'libraryOnly'          => false,
                'heldVolumes'          => '[]',
                'urls'                 => json_encode($urls)
            ];
        }

        // Done
        return $this->formatResponse(compact('statuses'));
    }
}
