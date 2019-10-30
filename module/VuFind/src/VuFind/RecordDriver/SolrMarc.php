<?php
/**
 * Model for MARC records in Solr.
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2010.
 * Copyright (C) The National Library of Finland 2015.
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
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_drivers Wiki
 */
namespace VuFind\RecordDriver;

/**
 * Model for MARC records in Solr.
 *
 * @category VuFind
 * @package  RecordDrivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:record_drivers Wiki
 */
class SolrMarc extends SolrDefault
{
    use IlsAwareTrait;
    use MarcReaderTrait;
    use MarcAdvancedTrait;

    /**
     * Get the publication dates of the record.  See also getDateSpan().
     *
     * @return array
     */
    public function getPublicationDates()
    {
        return isset($this->fields['publishDate']) ?
            $this->fields['publishDate'] : [];
    }

    /**
     * Get human readable publication dates for display purposes (may not be suitable
     * for computer processing -- use getPublicationDates() for that).
     *
     * @return array
     */
    public function getHumanReadablePublicationDates()
    {
        return $this->getPublicationDates();
    }

    /**
     * Get the text of the part/section portion of the title.
     *
     * @return string
     */
    public function getTitleSection()
    {
        return isset($this->fields['title_section']) ? $this->fields['title_section'] : parent::getTitleSection();
    }

    /**
     * Return an array of associative URL arrays with one or more of the following
     * keys:
     *
     * <li>
     *   <ul>desc: URL description text to display (optional)</ul>
     *   <ul>url: fully-formed URL (required if 'route' is absent)</ul>
     *   <ul>route: VuFind route to build URL with (required if 'url' is absent)</ul>
     *   <ul>routeParams: Parameters for route (optional)</ul>
     *   <ul>queryString: Query params to append after building route (optional)</ul>
     * </li>
     *
     * @return array
     */
    public function getURLs()
    {
        $retVal = [];
        // Which fields/subfields should we check for URLs?
        $fieldsToCheck = [
            '856' => ['y', 'z', '3'],   // Standard URL
            '555' => ['a']         // Cumulative index/finding aids
        ];
        foreach ($fieldsToCheck as $field => $subfields) {
            $urls = $this->getMarcRecord()->getFields($field);
            if ($urls) {
                foreach ($urls as $url) {
                    // Is there an address in the current field?
                    $address = $url->getSubfield('u');
                    if ($address) {
                        $address = $address->getData();
                        // Is there a description?  If not, just use the URL itself.
                        foreach ($subfields as $current) {
                            $desc = $url->getSubfield($current);
                            if ($desc) {
                                break;
                            }
                        }
                        if ($desc) {
                            $desc = $desc->getData();
                        } else {
                            $desc = $address;
                        }
                        $type = "supplemental";
                        if( (($url->getIndicator(2) == '0') || ($url->getIndicator(2) == '1')) && !($url->getSubfield('3')) ) {
                            $type = "accessOnline";
                        }
                        $retVal[] = ['url' => $address, 'desc' => $desc, 'type' => $type];
                    }
                }
            }
        }
        // check Solr if we didn't get the entire MARC record
        if( !$retVal && isset($this->fields["url"]) ) {
            foreach( $this->fields["url"] as $thisURL ) {
                $retVal[] = json_decode($thisURL, true);
            }
        }
        if( !$retVal ) {
            $urls = parent::getURLs();
            // should be an array, but sometimes it comes back with an extra wrapper layer
            $retVal = $urls[0]["url"] ?? $urls;
        }
        return $retVal;
    }

    /**
     * Get a link for placing a title level hold.
     *
     * @return mixed A url if a hold is possible, boolean false if not
     */
    public function getRealTimeTitleHold()
    {
        if ($this->hasILS()) {
            $biblioLevel = strtolower($this->tryMethod('getBibliographicLevel'));
            if ("monograph" == $biblioLevel || "serial" == $biblioLevel || strstr("part", $biblioLevel)) {
                if ($this->ils->getTitleHoldsMode() != "disabled") {
                    return $this->titleHoldLogic->getHold($this->getUniqueID());
                }
            }
        }
        return false;
    }

    /**
     * Get an array of information about record holdings, obtained in real-time
     * from the ILS.
     *
     * @return array
     */
    public function getRealTimeHoldings()
    {
        $id = $this->getUniqueID();
        if( $this->hasILS() && !$this->ils->getMemcachedVar("items" . $id) ) {
            $this->ils->setMemcachedVar("items" . $id, $this->getItems(), 900);
        }
        if( $this->hasILS() && !$this->ils->getMemcachedVar("cachedJson" . $id) ) {
            $json = isset($this->fields['cachedJson']) ? $this->fields['cachedJson'] : "";
            $json = json_decode($json, true);
            $this->ils->setMemcachedVar("cachedJson" . $id, $json, 900);
        }
        return $this->hasILS() ? $this->holdLogic->getHoldings(
            $id, $this->getConsortialIDs()
        ) : [];
    }
}
