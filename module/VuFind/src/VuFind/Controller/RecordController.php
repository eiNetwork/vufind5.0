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
                if(($thisHold['id'] == substr($bib, 2, -1)) || ($thisHold['id'] == $bib)) {
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

    /**
     * Save action - Allows the save template to appear,
     *   passes containingLists & nonContainingLists
     *
     * @return mixed
     */
    public function saveAction() {
        try {
            // keep a hold of the referring page since we are skipping the submit step
            $referer = $this->getRequest()->getServer()->get('HTTP_REFERER');
            if (substr($referer, -5) != '/Save'
                && stripos($referer, 'MyResearch/EditList/NEW') === false
            ) {
                $this->setFollowupUrlToReferer();
            } else {
                $this->clearFollowupUrl();
            }
            // clear the cached contents
            $post = $this->getRequest()->getPost()->toArray();
            $this->getILS()->clearMemcachedVar("cachedList" . $post['list']);
            return parent::saveAction();
        } catch (\Exception $e) {
            switch(get_class($e)) {
            case 'VuFind\Exception\ListSize':
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
                return $this->redirect()->toUrl($referer);
            case 'VuFind\Exception\LoginRequired':
                return $this->forceLogin();
            default:
                throw $e;
            }
        }
    }

    /**
     * Select Item action - Make patron choose a specific item (used for multi-volume bibs)
     *
     * @return mixed
     */
    public function selectItemAction() {
        // Retrieve user object and force login if necessary:
        if (!is_array($patron = $this->catalogLogin())) {
            $patron->followup = "['Record','SelectItem',{'id':'" . $this->params()->fromQuery('id') . "','hashKey':'" . $this->params()->fromQuery('hashKey') . "'}]";
            return $patron;
        }

        // grab the holdings, then split them into holdable and not holdable
        $driver = $this->loadRecord();
        $holdings = $driver->getRealTimeHoldings()["holdings"];
        $availableHoldings = [];
        $unavailableHoldings = [];
        $currentLocation = $this->getILS()->getCurrentLocation();
        $locationMappings = [];
        $canHold = (!empty($driver->tryMethod('getRealTimeTitleHold')));
        foreach($holdings as $thisBib) {
            foreach($thisBib["items"] as $item) {
                if($item["location"] == "CHECKIN_RECORDS") {
                    continue;
                } else if( $canHold && ($currentLocation["code"] != $item["branchCode"] || !$item["availability"]) && (($item["statusCode"] == '-') || ($item["statusCode"] == 't') || ($item["statusCode"] == '!')) ) {
                    for($j=0; $j<count($availableHoldings) && (($availableHoldings[$j]["sierraLocation"] < $item["sierraLocation"]) || (($availableHoldings[$j]["sierraLocation"] == $item["sierraLocation"]) && ($availableHoldings[$j]["number"] < $item["number"]))); $j++ ) {}
                    array_splice($availableHoldings, $j, 0, [$item]);
                } else {
                    for($j=0; $j<count($unavailableHoldings) && (($unavailableHoldings[$j]["sierraLocation"] < $item["sierraLocation"]) || (($unavailableHoldings[$j]["sierraLocation"] == $item["sierraLocation"]) && ($unavailableHoldings[$j]["number"] < $item["number"]))); $j++ ) {}
                    array_splice($unavailableHoldings, $j, 0, [$item]);
                }
            }
        }

        $view = $this->createViewModel();
        $view->id = $driver->getUniqueID();
        $view->hashKey = $this->params()->fromQuery('hashKey');
        $view->availableHoldings = $availableHoldings;
        $view->unavailableHoldings = $unavailableHoldings;
        $view->setTemplate('record/selectItem');
        return $view;
    }
}
