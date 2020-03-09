<?php
/**
 * "EIN Get List Contents" AJAX handler
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

use VuFind\Db\Table\UserList;
use VuFind\ILS\Connection;
use VuFind\Record\Loader;
use VuFind\Recommend\PluginManager;
use VuFind\Search\RecommendListener;
use VuFind\Search\SearchRunner;
use VuFind\Session\Settings as SessionSettings;
use Zend\Config\Config;
use Zend\Mvc\Controller\Plugin\Params;
use Zend\View\Renderer\RendererInterface;

/**
 * "EIN Get List Contents" AJAX handler
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
class EINGetListContents extends AbstractBase //implements TranslatorAwareInterface
{
    /**
     * Logged in user (or false)
     *
     * @var UserList
     */
    protected $listTable;

    /**
     * Loader
     *
     * @var Loader
     */
    protected $loader;

    /**
     * Recommend plugin manager
     *
     * @var PluginManager
     */
    protected $rpm;

    /**
     * Search Runner
     *
     * @var SearchRunner
     */
    protected $runner;

    /**
     * Logged in user (or false)
     *
     * @var User|bool
     */
    protected $user;

    /**
     * Constructor
     *
     * @param SessionSettings        $ss        Session settings
     * @param Config                 $config    Top-level configuration
     * @param Connection             $ils       ILS connection
     * @param RendererInterface      $renderer  View renderer
     * @param SearchRunner           $runner    Search runner
     * @param RecommendPluginManager $loader    Recommend plugin manager
     * @param User|bool              $user      Logged in user (or false)
     */
    public function __construct(SessionSettings $ss, Config $config, Connection $ils,
        RendererInterface $renderer, PluginManager $rpm, SearchRunner $runner, Loader $loader, UserList $listTable, $user
    ) {
        $this->sessionSettings = $ss;
        $this->config = $config;
        $this->ils = $ils;
        $this->renderer = $renderer;

        $this->rpm = $rpm;
        $this->runner = $runner;
        $this->loader = $loader;
        $this->listTable = $listTable;
        $this->user = $user;
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

        if( $this->user ) {
            $id = $params->fromPost('id', $params->fromQuery('id', []))[0];
            $page = $params->fromPost('page', $params->fromQuery('page', []))[0];
            $path = $params->fromPost('path', $params->fromQuery('path', []))[0];
            $sort = $params->fromPost('sort', $params->fromQuery('sort', []))[0];
            $continue = true;
            $items = [];
            $results = [];
            $cachedListContents = $this->ils->getMemcachedVar("cachedList" . $id);
            $sortHtml = "";
            $bulkHtml = "";
            if( !$cachedListContents ) {
                $cachedListContents = [];
            }
            if( !isset($cachedListContents[$sort]) ) {
                $cachedListContents[$sort] = ["done" => false, "items" => [], "sortList" => []];
            }
            if( !$cachedListContents[$sort]["done"] ) {
                $limit = 20;
                $request = ['id' => $id, 'limit' => $limit, 'page' => $page, 'listContents' => true, 'sort' => $sort];
                // limit to only needed fields
                $request["fl"] = $this->config->LimitedSearchFields->shortList;
                // Set up listener for recommendations:
                $runner = $this->runner;
                $rpm = $this->rpm;
                $setupCallback = function ($runner, $params, $searchId) use ($rpm) {
                    $listener = new RecommendListener($this->rpm, $searchId);
                    $listener->setConfig(
                        $params->getOptions()->getRecommendationSettings()
                    );
                    $listener->attach($runner->getEventManager()->getSharedManager());
                };
                $runnerItems = $this->runner->run($request, 'Favorites', $setupCallback);
                foreach($runnerItems->getResults() as $i => $thisResult) {
                    $newItem = ["ID" => $thisResult->getUniqueID(), "source" => $thisResult->getResourceSource()];
                    $items[] = $newItem;
                    $cachedListContents[$sort]["items"][(($page - 1) * $limit) + $i] = $newItem;
                }
                $continue = (($page * $limit) < $runnerItems->getResultTotal());
                $cachedListContents[$sort]["done"] = !$continue;
                $cachedListContents[$sort]["sortArgs"] = ['sortList' => $runnerItems->getParams()->getSortList(), 'id' => $id, 'list' => $id, 'path' => $path];
                $cachedListContents[$sort]["bulkArgs"] = ['idPrefix' => '', 'list' => $id];
                $this->ils->setMemcachedVar("cachedList" . $id, $cachedListContents, 300);
            } else {
                $items = $cachedListContents[$sort]["items"];
                $continue = false;
            }
            $sortHtml = $this->renderer->render('search/controls/sort.phtml', $cachedListContents[$sort]["sortArgs"]);
            $bulkArgs = $cachedListContents[$sort]["bulkArgs"];
            $bulkArgs["list"] = $this->listTable->getExisting($bulkArgs["list"]);
            $bulkHtml = $this->renderer->render('myresearch/bulk-action-buttons.phtml', $bulkArgs);
            foreach($items as $i => $thisResult) {
                $record = $this->loader->load($thisResult["ID"], DEFAULT_SEARCH_BACKEND, true);
                if( !($record instanceof \VuFind\RecordDriver\Missing) ) {
                    $results[] = $record;
                } else {
                    $results[] = $thisResult;
                }
            }
            $html = $this->renderer->render('myresearch/listContents.phtml', ['results' => $results, 'list' => $this->listTable->getExisting($id), 'cachedResults' => $cachedResults ?? []]);
            $output = ['status' => 'OK', 'html' => $html, 'id' => $id, 'page' => $page, 'continue' => $continue, 'sortHtml' => $sortHtml, 'bulkHtml' => $bulkHtml];
        }
        return $this->formatResponse($output ?? []);
    }
}
