<?php
/**
 * Default model for Solr records -- used when a more specific model based on
 * the recordtype field cannot be found.
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
 * @package  RecordDrivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_drivers Wiki
 */
namespace VuFind\RecordDriver;

/**
 * Default model for Solr records -- used when a more specific model based on
 * the recordtype field cannot be found.
 *
 * This should be used as the base class for all Solr-based record models.
 *
 * @category VuFind
 * @package  RecordDrivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_drivers Wiki
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 */
class SolrDefault extends DefaultRecord
{
    use HierarchyAwareTrait;

    /**
     * These Solr fields should be used for snippets if available (listed in order
     * of preference).
     *
     * @var array
     */
    protected $preferredSnippetFields = [
        'contents', 'topic'
    ];

    /**
     * These Solr fields should NEVER be used for snippets.  (We exclude author
     * and title because they are already covered by displayed fields; we exclude
     * spelling because it contains lots of fields jammed together and may cause
     * glitchy output; we exclude ID because random numbers are not helpful).
     *
     * @var array
     */
    protected $forbiddenSnippetFields = [
        'author', 'title', 'title_short', 'title_full',
        'title_full_unstemmed', 'title_auth', 'title_sub', 'spelling', 'id',
        'ctrlnum', 'author_variant', 'author2_variant', 'fullrecord'
    ];

    /**
     * These are captions corresponding with Solr fields for use when displaying
     * snippets.
     *
     * @var array
     */
    protected $snippetCaptions = [];

    /**
     * Should we include snippets in search results?
     *
     * @var bool
     */
    protected $snippet = false;

    /**
     * Highlighting details
     *
     * @var array
     */
    protected $highlightDetails = [];

    /**
     * Should we use hierarchy fields for simple container-child records linking?
     *
     * @var bool
     */
    protected $containerLinking = false;

    /**
     * Search results plugin manager
     *
     * @var \VuFindSearch\Service
     */
    protected $searchService = null;

    /**
     * Constructor
     *
     * @param \Zend\Config\Config $mainConfig     VuFind main configuration (omit for
     * built-in defaults)
     * @param \Zend\Config\Config $recordConfig   Record-specific configuration file
     * (omit to use $mainConfig as $recordConfig)
     * @param \Zend\Config\Config $searchSettings Search-specific configuration file
     */
    public function __construct($mainConfig = null, $recordConfig = null,
        $searchSettings = null
    ) {
        // Load snippet settings:
        $this->snippet = !isset($searchSettings->General->snippets)
            ? false : $searchSettings->General->snippets;
        if (isset($searchSettings->Snippet_Captions)
            && count($searchSettings->Snippet_Captions) > 0
        ) {
            foreach ($searchSettings->Snippet_Captions as $key => $value) {
                $this->snippetCaptions[$key] = $value;
            }
        }
        // Container-contents linking
        $this->containerLinking
            = !isset($mainConfig->Hierarchy->simpleContainerLinks)
            ? false : $mainConfig->Hierarchy->simpleContainerLinks;
        parent::__construct($mainConfig, $recordConfig, $searchSettings);
    }

    /**
     * Get highlighting details from the object.
     *
     * @return array
     */
    public function getHighlightDetails()
    {
        return $this->highlightDetails;
    }

    /**
     * Add highlighting details to the object.
     *
     * @param array $details Details to add
     *
     * @return void
     */
    public function setHighlightDetails($details)
    {
        $this->highlightDetails = $details;
    }

    /**
     * Get highlighted author data, if available.
     *
     * @return array
     */
    public function getRawAuthorHighlights()
    {
        // Don't check for highlighted values if highlighting is disabled:
        return ($this->highlight && isset($this->highlightDetails['author']))
            ? $this->highlightDetails['author'] : [];
    }

    /**
     * Given a Solr field name, return an appropriate caption.
     *
     * @param string $field Solr field name
     *
     * @return mixed        Caption if found, false if none available.
     */
    public function getSnippetCaption($field)
    {
        return isset($this->snippetCaptions[$field])
            ? $this->snippetCaptions[$field] : false;
    }

    /**
     * Pick one line from the highlighted text (if any) to use as a snippet.
     *
     * @return mixed False if no snippet found, otherwise associative array
     * with 'snippet' and 'caption' keys.
     */
    public function getHighlightedSnippet($lookfor="")
    {
        // Only process snippets if the setting is enabled:
        if ($this->snippet) {
            $highlights = [];

            // First check for preferred fields:
            foreach ($this->preferredSnippetFields as $current) {
                if (count($lookfor) > 0 && isset($this->highlightDetails[$current][0])) {
                    foreach( $this->highlightDetails[$current] as $thisHighlight ) {
                        $haystack = strtolower($thisHighlight);
                        foreach( $lookfor as $index => $value ) {
                            // skip empty needles
                            if( !$value ) {
                                continue;
                            }
                            // make sure it wasnt included in any of the other snippets we already added
                            foreach( $highlights as $greenLitHighlight ) {
                                $haystack2 = strtolower($greenLitHighlight["snippet"]);
                                if( strpos($haystack2, $value) !== false ) {
                                    unset($lookfor[$index]);
                                    continue 2;
                                }
                            }
                            if( strpos($haystack, $value) !== false ) {
                                $highlights[] = [
                                    'snippet' => $thisHighlight,
                                    'caption' => $this->getSnippetCaption($current)
                                ];
                                unset($lookfor[$index]);
                            }
                        }
                    }
                }
            }

            if( count($lookfor) == 0 ) {
                return $highlights;
            }

            // No preferred field found, so try for a non-forbidden field:
            if (isset($this->highlightDetails)
                && is_array($this->highlightDetails) && (count($lookfor) > 0)
            ) {
                foreach ($this->highlightDetails as $key => $value) {
                    $bits = explode("{{{{START_HILITE}}}}", $value[0]);
                    foreach( $bits as $bitIndex => $thisBit ) {
                        if( $bitIndex == 0 ) {
                            continue;
                        }
                        $highlight = strtolower(explode("{{{{END_HILITE}}}}", $thisBit, 2)[0]);
                        foreach ($lookfor as $index => $value2 ) {
                            // skip empty needles
                            if( !$value2 ) {
                                continue;
                            }
                            // make sure it wasnt included in any of the other snippets we already added
                            foreach( $highlights as $greenLitHighlight ) {
                                $haystackBits = explode("{{{{START_HILITE}}}}", $greenLitHighlight["snippet"]);
                                foreach( $haystackBits as $hBitIndex => $thisHBit ) {
                                    if( $hBitIndex == 0 ) {
                                        continue;
                                    }
                                    $haystackHighlight = strtolower(explode("{{{{END_HILITE}}}}", $thisHBit, 2)[0]);
                                    if( strpos($haystackHighlight, $value2) !== false ) {
                                        unset($lookfor[$index]);
                                        continue 3;
                                    }
                                }
                            }

                            if( strpos($highlight, $value2) !== false ) {
                                $highlights[] = [
                                    'snippet' => $value[0],
                                    'caption' => $this->getSnippetCaption($key)
                                ];
                                unset($lookfor[$index]);
                            }
                        }
                    }
                }
            }

            if( count($lookfor) == 0 ) {
                return $highlights;
            }

            // we still haven't found an exact match. do a fuzzy search and see if there are any close matches
            foreach ($this->highlightDetails as $key => $value) {
                $bits = explode("{{{{START_HILITE}}}}", $value[0]);
                foreach( $bits as $bitIndex => $thisBit ) {
                    if( $bitIndex == 0 ) {
                        continue;
                    }
                    $highlight = strtolower(explode("{{{{END_HILITE}}}}", $thisBit, 2)[0]);
                    foreach ($lookfor as $index => $value2 ) {
                        // make sure it wasnt included in any of the other snippets we already added
                        foreach( $highlights as $greenLitHighlight ) {
                            $haystackBits = explode("{{{{START_HILITE}}}}", $greenLitHighlight["snippet"]);
                            foreach( $haystackBits as $hBitIndex => $thisHBit ) {
                                if( $hBitIndex == 0 ) {
                                    continue;
                                }
                                $haystackHighlight = strtolower(explode("{{{{END_HILITE}}}}", $thisHBit, 2)[0]);
                                $count = similar_text($value2, $haystackHighlight, $percent);
                                if( $percent > 60 ) {
                                    unset($lookfor[$index]);
                                    continue 3;
                                }
                            }
                        }

                        $count = similar_text($value2, $highlight, $percent);
                        if( $percent > 60 ) {
                            $highlights[] = [
                                'snippet' => $value[0],
                                'caption' => $this->getSnippetCaption($key)
                            ];
                            unset($lookfor[$index]);
                        }
                    }
                }
            }

            return $highlights;
        }

        // If we got this far, no snippet was found:
        return false;
    }

    /**
     * Get a highlighted title string, if available.
     *
     * @return string
     */
    public function getHighlightedTitle()
    {
        // Don't check for highlighted values if highlighting is disabled:
        if (!$this->highlight) {
            return '';
        }
        return (isset($this->highlightDetails['title'][0]))
            ? $this->highlightDetails['title'][0] : '';
    }

    /**
     * Attach a Search Results Plugin Manager connection and related logic to
     * the driver
     *
     * @param \VuFindSearch\Service $service Search Service Manager
     *
     * @return void
     */
    public function attachSearchService(\VuFindSearch\Service $service)
    {
        $this->searchService = $service;
    }

    /**
     * Get the number of child records belonging to this record
     *
     * @return int Number of records
     */
    public function getChildRecordCount()
    {
        // Shortcut: if this record is not the top record, let's not find out the
        // count. This assumes that contained records cannot contain more records.
        if (!$this->containerLinking
            || empty($this->fields['is_hierarchy_id'])
            || null === $this->searchService
        ) {
            return 0;
        }

        $safeId = addcslashes($this->fields['is_hierarchy_id'], '"');
        $query = new \VuFindSearch\Query\Query(
            'hierarchy_parent_id:"' . $safeId . '"'
        );
        // Disable highlighting for efficiency; not needed here:
        $params = new \VuFindSearch\ParamBag(['hl' => ['false']]);
        return $this->searchService
            ->search($this->sourceIdentifier, $query, 0, 0, $params)
            ->getTotal();
    }

    /**
     * Get the container record id.
     *
     * @return string Container record id (empty string if none)
     */
    public function getContainerRecordID()
    {
        return $this->containerLinking
            && !empty($this->fields['hierarchy_parent_id'])
            ? $this->fields['hierarchy_parent_id'][0] : '';
    }

    /**
     * Get an array of all the formats associated with the record.
     *
     * @return array
     */
    public function getFormats()
    {
        if( isset($this->fields['format']) ) {
            $formats = $this->fields['format'];
            // weed out categories
            foreach($formats as $key => $value) {
                if( strpos($value, "Category:") !== false ) {
                    unset($formats[$key]);
                }
            }
            return $formats;
        } else {
            return [];
        }
    }

    /**
     * Get the format category associated with the record.
     *
     * @return string
     */
    public function getFormatCategory()
    {
        if( isset($this->fields['format']) ) {
            $formats = $this->fields['format'];
            // weed out categories
            foreach($formats as $key => $value) {
                if( strpos($value, "Category:") !== false ) {
                    return substr($value, 10);
                }
            }
        } else {
            return "";
        }
    }

    /**
     * Get the items attached to the record.
     *
     * @return array
     */
    public function getItems()
    {
        $items = [];
        $json = isset($this->fields['cachedJson']) ? $this->fields['cachedJson'] : "";
        $json = json_decode($json, true);
        if( isset($json["holding"]) ) {
            foreach( $json["holding"] as $thisJson ) {
                $items[] = "i" . $thisJson["itemId"];
            }
        }
        return $items;
    }

    public function hasOnlineAccess()
    {
        // our OverDrive model doesn't support multi-use
        if( isset($this->fields['econtent_source']) && in_array("OverDrive", $this->fields['econtent_source']) ) {
            return false;
        }
        foreach( $this->getUrls() as $thisUrl ) {
            if( $thisUrl["type"] == "accessOnline" ) {
                return true;
            }
        }
        return false;
    }

    public function getCachedItems()
    {
        $json = isset($this->fields['cachedJson']) ? $this->fields['cachedJson'] : "";
        $json = json_decode($json, true);
        foreach( ($json["holding"] ?? []) as $key => $thisJson ) {
            $locCodeRow = $this->getDbTable('ShelvingLocation')->getByCode($thisJson["locationID"]);
            if( $locCodeRow ) {
                $json["holding"][$key]["location"] = $locCodeRow->sierraName;
            }
            if( !isset($json["holding"][$key]["item_id"]) ) {
                $json["holding"][$key]["item_id"] = $json["holding"][$key]["itemId"] ?? null;
            }
        }
        foreach( ($json["orderRecords"] ?? []) as $key => $thisJson ) {
            // find this location in the database
            $row = $this->getDBTable('shelvinglocation')->getByCode($key);
            $row = $row ? $row->toArray() : [];
            // test to see if it's a branch name instead of shelving location
            if( count($row) == 0 ) {
                // find this location in the database
                $row = $this->getDBTable('location')->getByName($thisJson["location"]);
                $row = $row ? $row->toArray() : [];
            }
            // if we got results, send them back
            if( count($row) > 0 ) {
                $json["orderRecords"][$key] = [
                    "id" => $this->getUniqueID(),
                    "item_id" => null,
                    "availability" => false,
                    "statusCode" => "order",
                    "status" => "ON ORDER",
                    "location" => $thisJson["location"],
                    "reserve" => "N",
                    "callnumber" => null,
                    "duedate" => null,
                    "returnDate" => false,
                    "number" => null,
                    "barcode" => null,
                    "locationID" => $row["code"],
                    "copiesOwned" => $thisJson["copies"]
                ];
            } else {
                unset($json["orderRecords"][$key]);
            }
        }
        return $json;
    }
}
