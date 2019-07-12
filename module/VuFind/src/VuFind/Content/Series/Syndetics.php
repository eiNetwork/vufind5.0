<?php
/**
 * Syndetics series content loader.
 *
 * PHP version 5
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
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Content
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
namespace VuFind\Content\Series;

/**
 * Syndetics review content loader.
 *
 * @category VuFind2
 * @package  Content
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class Syndetics extends \VuFind\Content\AbstractSyndetics
{
    /**
     * List of syndetic review sources
     *
     * @var array
     */
    protected $sourceList = [
        'SERIES' => [
            'title' => 'Series',
            'file' => 'SERIES.HTML',
            'div' => '<div id="syn_series"></div>'
        ]
    ];

    /**
     * This method is responsible for connecting to Syndetics and abstracting
     * series info.
     *
     * It first queries the master url for the ISBN entry seeking a series URL.
     * If a series URL is found, the script will then use HTTP request to
     * retrieve the script. The script will then parse the series according to
     * US MARC (I believe). It will provide a link to the URL master HTML page
     * for more information.
     *
     * @param string           $key     API key (unused here)
     * @param \VuFindCode\ISBN $isbnObj ISBN object
     *
     * @throws \Exception
     * @return array     Returns array with series data.
     * @author Joel Timothy Norman <joel.t.norman@wmich.edu>
     * @author Andrew Nagy <vufind-tech@lists.sourceforge.net>
     * @author Brad Patton <pattonb@einetwork.net>
     */
    public function loadByIsbn($key, \VuFindCode\ISBN $isbnObj)
    {
        // Initialize return value
        $series = [];

        // Find out if there are any series
        $isbn = $this->getIsbn10($isbnObj);
        $url = $this->getIsbnUrl($isbn, $key);
        $result = $this->getHttpClient($url)->send();
        if (!$result->isSuccess()) {
            return $series;
        }

        // Test XML Response
        if (!($xmldoc = $this->xmlToDOMDocument($result->getBody()))) {
            throw new \Exception('Invalid XML');
        }

        $i = 0;
        foreach ($this->sourceList as $source => $sourceInfo) {
            $nodes = $xmldoc->getElementsByTagName($source . ($i + 1));
            if ($nodes->length) {
                // Load reviews
                $url = $this->getIsbnUrl($isbn, $key, str_replace(".HTML", ($i + 1). ".HTML", $sourceInfo['file']));
                $result2 = $this->getHttpClient($url)->send();
                if (!$result2->isSuccess()) {
                    continue;
                }

                // Test HTML Response
                $htmldoc = $this->htmlToDOMDocument($result2->getBody());
                if (!$htmldoc) {
                    throw new \Exception('Invalid HTML');
                }

                // get the series name
                $divList = $htmldoc->getElementsByTagName("div");
                foreach( $divList as $thisDiv ) {
                    if( $thisDiv->getAttribute("class") == "series_title" ) {
                        $series[$i]["title"] = trim($thisDiv->nodeValue);
                    }
                    if( $thisDiv->getAttribute("class") == "seriesnotes" ) {
                        $series[$i]["notes"] = trim($thisDiv->nodeValue);
                    }
                }

                // get the series' entries
                $tdList = $htmldoc->getElementsByTagName("td");
                $lastNum = -1;
                foreach( $tdList as $thisTD ) {
                    if( $thisTD->getAttribute("class") == "seriesnumcol" ) {
                        $lastNum = trim($thisTD->nodeValue);
                    } else if( $thisTD->getAttribute("class") == "seriescol" ) {
                        $links = $thisTD->getElementsByTagName("a");
                        if( $links->length ) {
                            $series[$i][$lastNum]["title"] = trim($links->item(0)->nodeValue);
                            $isbn = trim($links->item(0)->getAttribute("href"));
                            $isbnIndex = stripos($isbn, "isbn=") + 5;
                            $isbn = substr($isbn, $isbnIndex, strpos($isbn, "/", 2) - $isbnIndex);
                            $series[$i][$lastNum]["ISBN"] = $isbn;
                        }
                    }
                }

                $i++;
            }
        }

        return $series;
    }
}
