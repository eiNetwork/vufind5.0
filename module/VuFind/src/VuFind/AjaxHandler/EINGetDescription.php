<?php
/**
 * "EIN Get Description" AJAX handler
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

use VuFind\ILS\Connection;
use VuFind\Record\Loader;
use VuFind\RecordTab\PluginManager;
use VuFind\Session\Settings as SessionSettings;
use Zend\Config\Config;
use Zend\Mvc\Controller\Plugin\Params;

/**
 * "EIN Get Description" AJAX handler
 *
 * This is responsible for printing the description for a
 * particular record in JSON format.
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
class EINGetDescription extends AbstractBase
{
    /**
     * Loader
     *
     * @var Loader
     */
    protected $loader;

    /**
     * RecordTab plugin manager
     *
     * @var PluginManager
     */
    protected $rtpm;

    /**
     * Constructor
     *
     * @param SessionSettings        $ss        Session settings
     * @param Config                 $config    Top-level configuration
     * @param Connection             $ils       ILS connection
     * @param RendererInterface      $renderer  View renderer
     * @param SearchRunner           $runner    Search runner
     * @param RecordTabPluginManager $loader    RecordTab plugin manager
     * @param User|bool              $user      Logged in user (or false)
     */
    public function __construct(SessionSettings $ss, array $config, Connection $ils, PluginManager $rtpm, Loader $loader ) {
        $this->sessionSettings = $ss;
        $this->config = $config;
        $this->ils = $ils;

        $this->rtpm = $rtpm;
        $this->loader = $loader;
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

        $id = $params->fromQuery('id');
        $driver = $this->loader->load( $id );

        $desc = "";

        $tabConfig = $this->config["vufind"]["recorddriver_tabs"];
        $allTabs = $this->rtpm->getTabsForRecord($driver, $tabConfig);
        $summarySources = (isset($allTabs['Summaries']) ? $allTabs['Summaries']->getContent($driver->getCleanISBN()) : []);
        foreach( $summarySources as $thisSource ) {
            foreach( $thisSource as $thisSummary ) {
                $summary = $thisSummary["Content"] ?? $thisSummary;
                if( strncasecmp($summary, "<fld520", 7) == 0 ) {
                    $summary = substr($summary, strpos($summary, ">") + 1);
                    $summary = substr($summary, 0, strrpos($summary, "<"));
                }
                if( strpos($desc, $summary) === false ) {
                    $desc .= (($desc != "") ? "<br><br>" : "") . $summary;
                }
            }
        }

        // check marc record if no description yet
        if( $desc == "" ) {
            foreach( $driver->getSummary() as $i => $thisDesc ) {
                if( strpos($desc, $thisDesc) === false ) {
                    $desc .= ($i ? "<br><br>" : "") . $thisDesc;
                }
            }
        }

        // Done
        return $this->formatResponse(["status" => 'OK', "description" => $desc]);
    }
}
