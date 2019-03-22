<?php
/**
 * Record Controller
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2010.
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
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
namespace VuFind\Controller;

use Zend\Config\Config;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Record Controller
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
class RecordController extends AbstractRecord
{
    use HoldsTrait;
    use ILLRequestsTrait;
    use StorageRetrievalRequestsTrait;

    /**
     * Constructor
     *
     * @param ServiceLocatorInterface $sm     Service manager
     * @param Config                  $config VuFind configuration
     */
    public function __construct(ServiceLocatorInterface $sm, Config $config)
    {
        // Call standard record controller initialization:
        parent::__construct($sm);

        // Load default tab setting:
        $this->fallbackDefaultTab = isset($config->Site->defaultRecordTab)
            ? $config->Site->defaultRecordTab : 'Holdings';
    }

    /**
     * Is the result scroller active?
     *
     * @return bool
     */
    protected function resultScrollerActive()
    {
        $config = $this->serviceLocator->get('VuFind\Config\PluginManager')
            ->get('config');
        return isset($config->Record->next_prev_navigation)
            && $config->Record->next_prev_navigation;
    }

    /**
     * Create a new ViewModel.
     *
     * @param array $params Parameters to pass to ViewModel constructor.
     *
     * @return \Zend\View\Model\ViewModel
     */
    protected function createViewModel($params = null)
    {
        $view = parent::createViewModel($params);

        // short version of this
        if( isset($params["skip"]) && $params["skip"] ) {
          return $view;
        }

        // load this up so we can check some things
        $catalog = $this->getILS();
        $driver = $this->loadRecord();
        $bib = $this->driver->getUniqueID();
        if( $cache = $catalog->getMemcachedVar("holdingID" . $bib) ) {
            $cache["CACHED_INFO"]["doUpdate"] = true;
            $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
            $catalog->setMemcachedVar("holdingID" . $bib, $cache, $time);
        }
        $holdings = $this->driver->getRealTimeHoldings();
        $view->holdings = $holdings["holdings"] ?? [];

        // see whether the driver can hold
        $holdingTitleHold = $driver->tryMethod('getRealTimeTitleHold');
        $canHold = (!empty($holdingTitleHold));
        $canCheckOut = false;
        $hasVolumes = false;

        // see whether or not this bib has different volumes
        $overDriveHolds = -1;
        foreach($view->holdings as $entry) {
          foreach($entry["items"] as $item) {
            if( isset($item["number"]) && $item["number"]) {
              $hasVolumes = true;
            }
            if( isset($item["isOverDrive"]) && $item["isOverDrive"] ) {
              $overDriveHolds = $item["numberOfHolds"];
            }
          }
        }

        // see whether they already have a hold on it
        if($canHold && ($user = $this->getUser()) && !$hasVolumes) {
            $patron = $this->catalogLogin();
            $holds = $catalog->getMyHolds($patron);
            foreach($holds as $thisHold) {
                if($thisHold['id'] == substr($bib, 2, -1)) {
                    $canHold = false;
                    $view->isTitleHeld = true;
                }
            }
        }

        // if not, see whether there is a holdable copy available
        if( $canHold ) {
            $args=array();
            foreach($view->holdings as $holding) {
                foreach($holding['items'] as $item) {
                    // look for a hold link
                    $marcHoldOK = isset($item['statusCode']) && in_array(trim($item['statusCode']), ['-','t','!','i','p','order']);
                    $overdriveHoldOK = isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] == 0);
                    if(($marcHoldOK || $overdriveHoldOK) && $holdingTitleHold['action'] == "Hold") {
                        $args["id"] = $bib;
                        foreach(explode('&',$holdingTitleHold['query']) as $piece) {
                            $pieces = explode('=', $piece);
                            $args[$pieces[0]] = $pieces[1];
                        }
                        break 2;
                    }
                }
            }
            $view->holdArgs = str_replace("\"", "'", json_encode($args));
            if( count($args) == 0 ) {
                $canHold = false;
            }
        }

        // see if they can check this out
        $libraryOnly = false;
        if( !$canHold ) {
            foreach($view->holdings as $holding) {
                foreach($holding['items'] as $item) {
                    $canCheckOut |= isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] > 0);
                    if( isset($item['statusCode']) && trim($item['statusCode']) == "o" ) {
                        $libraryOnly = true;
                    }
                }
            }
            if( $canCheckOut ) {
                $args = array();
                foreach(explode('&',$holdingTitleHold['query']) as $piece) {
                    $pieces = explode('=', $piece);
                    $args[$pieces[0]] = $pieces[1];
                }
                $view->holdArgs = str_replace("\"", "'", json_encode($args));
            }
        }
        $view->libraryOnly = $libraryOnly;

        // make sure they don't already have it checked out
        if( $user && ($canCheckOut || $canHold) ) {
            $patron = (isset($patron) ? $patron : $this->catalogLogin());
            $checkedOutItems = $catalog->getMyTransactions($patron);
            foreach($checkedOutItems as $thisItem) {
                if($thisItem['fullID'] == $bib) {
                    $canCheckOut = false;
                    // if this bib has volumes, they still still place holds on other volumes even if they have one checked out
                    $canHold = $hasVolumes;
                    $view->isTitleCheckedOut = true;
                    if( isset($thisItem["reserveId"]) ) {
                        $view->canReturn = $thisItem["actions"]["earlyReturn"] ?? false;
                        $view->availableFormats = $thisItem["format"];

                        // look for instant options
                        $OD_type_mapping = ['ebook-mediado' => 'mediaDo', 'ebook-overdrive' => 'overdriveRead', 'magazine-overdrive' => 'overdriveRead', 'video-streaming' => 'streamingVideo', 'audiobook-overdrive' => 'overdriveListen'];
                        foreach( $OD_type_mapping as $formatType => $linkKey ) {
                            if( in_array($formatType, $thisItem["availableFormats"]) && ($thisItem[$linkKey]->data->downloadLink ?? false) ) {
                                $view->setVariable($linkKey, $thisItem[$linkKey]->data->downloadLink);
                            }
                        }

                        // get the download links
                        $downloadableFormats = [];
                        $notDownloadableFormats = ['ebook-mediado','magazine-overdrive','ebook-overdrive','video-streaming','audiobook-overdrive'];
                        foreach($thisItem["availableFormats"] as $possibleFormat) {
                            if( !in_array($possibleFormat, $notDownloadableFormats) ) {
                                $downloadableFormats[] = $possibleFormat;
                            }
                        }
                        // add the video-streaming option if it's not locked in yet
                        foreach($thisItem["actions"]["format"]["fields"] ?? [] as $thisFormat) {
                            if( $thisFormat["name"] == "formatType" && !$thisItem["isFormatLockedIn"] ) {
                                $downloadableFormats[] = $thisFormat["options"][0];
                            }
                        }

                        $view->downloadFormats = $downloadableFormats;
                        $view->formatLocked = $thisItem["isFormatLockedIn"];
                    }
                }
            }
        }

        $view->canCheckOut = $canCheckOut;
        $view->canHold = $canHold;
        $view->numberOfHolds = ($overDriveHolds == -1) ? $catalog->getNumberOfHoldsOnRecord($bib) : $overDriveHolds;
        $view->idArgs = str_replace("\"", "'", json_encode(["id" => $bib]));

        // see whether they have this item in any lists
        if( $user ) {
            $lists = $user->getLists();
            $hasOnList = false;
            foreach($lists as $thisList) {
                if($thisList->contains($driver->getResourceSource() . "|" . $bib)) {
                    $hasOnList = true;
                    break;
                }
            }
            $view->hasOnList = $hasOnList;
            $view->myLists = $lists;
        }

        $view->currentLocation = $catalog->getCurrentLocation();

        /***** For now, we're just showing the highlights on the search results page *****\
        $rawTerms = explode("lookfor", $this->getSearchMemory()->retrieve());
        unset($rawTerms[0]);
        $searchTerms = [];
        foreach($rawTerms as $key => $value) {
            $equals = strpos($value, "=") + 1;
            $ampersand = strpos($value, "&", $equals);
            $searchTerms = array_merge($searchTerms, explode("+", substr($value, $equals, $ampersand - $equals)));
        }
        $view->searchMemory = $searchTerms;
        \***** For now, we're just showing the highlights on the search results page *****/

        if( substr($bib, 0, 2) == ".b" ) {
            $view->classicLink = $catalog->getClassicLink($bib);
        }

        // change the tab
        $view->itemDetailsTab = ($overDriveHolds != -1) ? "details3" : (isset($_COOKIE["itemDetailsTab"]) ? $_COOKIE["itemDetailsTab"] : "details1");

        $cache = $catalog->getMemcachedVar("holdingID" . $bib);
        if( !$cache ) {
            $cache = ["CACHED_INFO" => []];
        }
        $cache["CACHED_INFO"]["doUpdate"] = false;
        $cache["CACHED_INFO"]["numberOfHolds"] = $view->numberOfHolds;
        $cache["CACHED_INFO"]["processedHoldings"] = $holdings;
        $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
        $catalog->setMemcachedVar("holdingID" . $bib, $cache, $time);

        return $view;
    }
}
