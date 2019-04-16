<?php
/**
 * Default Controller
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

use VuFind\Exception\Mail as MailException;

/**
 * Redirects the user to the appropriate default VuFind action.
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
class SearchController extends AbstractSolrSearch
{
    /**
     * Handle an advanced search
     *
     * @return mixed
     */
    public function advancedAction()
    {
        // Standard setup from base class:
        $view = parent::advancedAction();

        // Set up facet information:
        $view->formatCategories = $this->getConfig()->FormatCategories;

        return $view;
    }

    /**
     * Show facet list for Solr-driven collections.
     *
     * @return mixed
     */
    public function collectionfacetlistAction()
    {
        $this->searchClassId = 'SolrCollection';
        return $this->facetListAction();
    }

    /**
     * Email action - Allows the email form to appear.
     *
     * @return mixed
     */
    public function emailAction()
    {
        // If a URL was explicitly passed in, use that; otherwise, try to
        // find the HTTP referrer.
        $mailer = $this->serviceLocator->get('VuFind\Mailer\Mailer');
        $view = $this->createEmailViewModel(null, $mailer->getDefaultLinkSubject());
        $mailer->setMaxRecipients($view->maxRecipients);
        // Set up reCaptcha
        $view->useRecaptcha = $this->recaptcha()->active('email');
        $view->url = $this->params()->fromPost(
            'url', $this->params()->fromQuery(
                'url',
                $this->getRequest()->getServer()->get('HTTP_REFERER')
            )
        );

        // Force login if necessary:
        $config = $this->getConfig();
        if ((!isset($config->Mail->require_login) || $config->Mail->require_login)
            && !$this->getUser()
        ) {
            return $this->forceLogin(null, ['emailurl' => $view->url]);
        }

        // Check if we have a URL in login followup data -- this should override
        // any existing referer to avoid emailing a login-related URL!
        $followupUrl = $this->followup()->retrieveAndClear('emailurl');
        if (!empty($followupUrl)) {
            $view->url = $followupUrl;
        }

        // Fail if we can't figure out a URL to share:
        if (empty($view->url)) {
            throw new \Exception('Cannot determine URL to share.');
        }

        // Process form submission:
        if ($this->formWasSubmitted('submit', $view->useRecaptcha)) {
            // Attempt to send the email and show an appropriate flash message:
            try {
                // If we got this far, we're ready to send the email:
                $cc = $this->params()->fromPost('ccself') && $view->from != $view->to
                    ? $view->from : null;
                $mailer->sendLink(
                    $view->to, $view->from, $view->message,
                    $view->url, $this->getViewRenderer(), $view->subject, $cc
                );
                $this->flashMessenger()->addMessage('email_success', 'success');
                return $this->redirect()->toUrl($view->url);
            } catch (MailException $e) {
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
            }
        }
        return $view;
    }

    /**
     * Handle search history display && purge
     *
     * @return mixed
     */
    public function historyAction()
    {
        // Force login if necessary
        $user = $this->getUser();
        if ($this->params()->fromQuery('require_login', 'no') !== 'no' && !$user) {
            return $this->forceLogin();
        }
        $userId = is_object($user) ? $user->id : null;

        $searchHistoryHelper = $this->serviceLocator->get('VuFind\Search\History');

        if ($this->params()->fromQuery('purge')) {
            $searchHistoryHelper->purgeSearchHistory($userId);

            // We don't want to remember the last search after a purge:
            $this->getSearchMemory()->forgetSearch();
        }
        $lastSearches = $searchHistoryHelper->getSearchHistory($userId);
        return $this->createViewModel($lastSearches);
    }

    /**
     * Home action
     *
     * @return mixed
     */
    public function homeAction()
    {
        // see if they want the children's catalog
        if( $this->params()->fromQuery('childrenOnly') == 'true' ) {
            $expiration = time() + 1209600;
            setcookie("einChildrensCatalog", "true", $expiration, '/');
        }

        // reset to retaining filters
        $this->getILS()->setSessionVar("retainFilters", true);

        $view = $this->createViewModel(
            [
                'BookResults' => $this->getNewItemsByFormatAction(["Print Book", "Large Print"]),
                'DVDResults' => $this->getNewItemsByFormatAction(["DVD"]),
                'eBookResults' => $this->getNewItemsByFormatAction(["OverDrive Read", "Adobe EPUB ebook", "Kindle Book", "Adobe PDF eBook", "Ebook Download"]),
                'request' => $this->request
            ]
        );

        return $view;
    }

    /**
     * New item result list
     *
     * @return mixed
     */
    public function getNewItemsByFormatAction($format)
    {
        // Retrieve new item list:
        $range = 300;
        $dept = null;

        // Validate the range parameter -- it should not exceed the greatest
        // configured value:
        $maxAge = $this->newItems()->getMaxAge();
        if($range > $maxAge) {
            $range = $maxAge;
        }

        // use the formats they passed in
        $formatStr = "(";
        foreach ($format as $type) {
            $formatStr .= (($formatStr == "(") ? "" : " OR ") . 'format:"' . $type . '"';
        }
        $formatStr .= ")";
        $hiddenFilters = [$formatStr];

        // Depending on whether we're in ILS or Solr mode, we need to do some
        // different processing here to retrieve the correct items:
        if ($this->newItems()->getMethod() == 'ils') {
            // Use standard search action with override parameter to show results:
            $bibIDs = $this->newItems()->getBibIDsFromCatalog(
                $this->getILS(),
                $this->getResultsManager()->get('Solr')->getParams(),
                $range, $dept, $this->flashMessenger()
            );
            $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);
        } else {
            // Use a Solr filter to show results:
            $hiddenFilters[] = 'date_added:["' . strftime("%Y-%m-%dT00:00:00Z", time() - $range * 86400) . '" TO *]';
        }

        // check for children only tag
        if( ($this->params()->fromQuery('childrenOnly') == 'true') || (isset($_COOKIE["einChildrensCatalog"]) && ($_COOKIE["einChildrensCatalog"] == "true")) ) {
            $hiddenFilters[] = 'target_audience_full:"Children"';
        }

        // only keep ones with 5 or more holding locations
        $hiddenFilters[] = 'num_holding_locations:[5 TO *]';

        // If we found hidden filters above, apply them now:
        if (!empty($hiddenFilters)) {
            $this->getRequest()->getQuery()->set('hiddenFilters', $hiddenFilters);
        }

        // get extra bibs
        $this->getRequest()->getQuery()->set('overrideLimit', '40');

        // sort by newest first
        $this->getRequest()->getQuery()->set('sort', 'date_added desc');

        // limit to only needed fields
        $this->getRequest()->getQuery()->set('fl', $this->getConfig()->LimitedSearchFields->shortList);

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Customize the URL helper to make sure it builds proper new item URLs
        // (check it's set first -- RSS feed will return a response model rather
        // than a view model):
        if (isset($view->results)) {
            $url = $view->results->getUrlQuery();
            $url->setDefaultParameter('range', $range);
            $url->setDefaultParameter('department', $dept);
            $url->setSuppressQuery(true);

            // reset the sort type
            $this->getSearchMemory()->rememberLastSettings($view->params->getSearchClassId(), ["sort" => "relevance"]);
        }

        return $view;
    }

    /**
     * New item search form
     *
     * @return mixed
     */
    public function newitemAction()
    {
        // Search parameters set?  Process results.
        if ($this->params()->fromQuery('range') !== null) {
            return $this->forwardTo('Search', 'NewItemResults');
        }

        return $this->createViewModel(
            [
                'fundList' => $this->newItems()->getFundList(),
                'ranges' => $this->newItems()->getRanges()
            ]
        );
    }

    /**
     * New item result list
     *
     * @return mixed
     */
    public function newitemresultsAction()
    {
        // Retrieve new item list:
        $range = $this->params()->fromQuery('range');
        $dept = $this->params()->fromQuery('department');

        // Validate the range parameter -- it should not exceed the greatest
        // configured value:
        $maxAge = $this->newItems()->getMaxAge();
        if ($maxAge > 0 && $range > $maxAge) {
            $range = $maxAge;
        }

        // Are there "new item" filter queries specified in the config file?
        // If so, load them now; we may add more values. These will be applied
        // later after the whole list is collected.
        $hiddenFilters = $this->newItems()->getHiddenFilters();

        // Depending on whether we're in ILS or Solr mode, we need to do some
        // different processing here to retrieve the correct items:
        if ($this->newItems()->getMethod() == 'ils') {
            // Use standard search action with override parameter to show results:
            $bibIDs = $this->newItems()->getBibIDsFromCatalog(
                $this->getILS(),
                $this->getResultsManager()->get('Solr')->getParams(),
                $range, $dept, $this->flashMessenger()
            );
            $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);
        } else {
            // Use a Solr filter to show results:
            $hiddenFilters[] = $this->newItems()->getSolrFilter($range);
        }

        // If we found hidden filters above, apply them now:
        if (!empty($hiddenFilters)) {
            $this->getRequest()->getQuery()->set('hiddenFilters', $hiddenFilters);
        }

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Customize the URL helper to make sure it builds proper new item URLs
        // (check it's set first -- RSS feed will return a response model rather
        // than a view model):
        if (isset($view->results)) {
            $view->results->getUrlQuery()
                ->setDefaultParameter('range', $range)
                ->setDefaultParameter('department', $dept)
                ->setSuppressQuery(true);
        }

        // We don't want new items hidden filters to propagate to other searches:
        $view->ignoreHiddenFilterMemory = true;
        $view->ignoreHiddenFiltersInRequest = true;

        return $view;
    }

    /**
     * Course reserves
     *
     * @return mixed
     */
    public function reservesAction()
    {
        // Search parameters set?  Process results.
        if ($this->params()->fromQuery('inst') !== null
            || $this->params()->fromQuery('course') !== null
            || $this->params()->fromQuery('dept') !== null
        ) {
            return $this->forwardTo('Search', 'ReservesResults');
        }

        // No params?  Show appropriate form (varies depending on whether we're
        // using driver-based or Solr-based reserves searching).
        if ($this->reserves()->useIndex()) {
            return $this->forwardTo('Search', 'ReservesSearch');
        }

        // If we got this far, we're using driver-based searching and need to
        // send options to the view:
        $catalog = $this->getILS();
        return $this->createViewModel(
            [
                'deptList' => $catalog->getDepartments(),
                'instList' => $catalog->getInstructors(),
                'courseList' =>  $catalog->getCourses()
            ]
        );
    }

    /**
     * Show facet list for Solr-driven reserves.
     *
     * @return mixed
     */
    public function reservesfacetlistAction()
    {
        $this->searchClassId = 'SolrReserves';
        return $this->facetListAction();
    }

    /**
     * Show search form for Solr-driven reserves.
     *
     * @return mixed
     */
    public function reservessearchAction()
    {
        $request = new \Zend\Stdlib\Parameters(
            $this->getRequest()->getQuery()->toArray()
            + $this->getRequest()->getPost()->toArray()
        );
        $view = $this->createViewModel();
        $runner = $this->serviceLocator->get('VuFind\Search\SearchRunner');
        $view->results = $runner->run(
            $request, 'SolrReserves', $this->getSearchSetupCallback()
        );
        $view->params = $view->results->getParams();
        return $view;
    }

    /**
     * Show results of reserves search.
     *
     * @return mixed
     */
    public function reservesresultsAction()
    {
        // Retrieve course reserves item list:
        $course = $this->params()->fromQuery('course');
        $inst = $this->params()->fromQuery('inst');
        $dept = $this->params()->fromQuery('dept');
        $result = $this->reserves()->findReserves($course, $inst, $dept);

        // Build a list of unique IDs
        $callback = function ($i) {
            return $i['BIB_ID'];
        };
        $bibIDs = array_unique(array_map($callback, $result));

        // Truncate the list if it is too long:
        $limit = $this->getResultsManager()->get('Solr')->getParams()
            ->getQueryIDLimit();
        if (count($bibIDs) > $limit) {
            $bibIDs = array_slice($bibIDs, 0, $limit);
            $this->flashMessenger()->addMessage('too_many_reserves', 'info');
        }

        // Use standard search action with override parameter to show results:
        $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Set up RSS feed title just in case:
        $this->getViewRenderer()->plugin('resultfeed')
            ->setOverrideTitle('Reserves Search Results');

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Pass some key values to the view, if found:
        if (isset($result[0]['instructor']) && !empty($result[0]['instructor'])) {
            $view->instructor = $result[0]['instructor'];
        }
        if (isset($result[0]['course']) && !empty($result[0]['course'])) {
            $view->course = $result[0]['course'];
        }

        // Customize the URL helper to make sure it builds proper reserves URLs
        // (but only do this if we have access to a results object, which we
        // won't in RSS mode):
        if (isset($view->results)) {
            $view->results->getUrlQuery()
                ->setDefaultParameter('course', $course)
                ->setDefaultParameter('inst', $inst)
                ->setDefaultParameter('dept', $dept)
                ->setSuppressQuery(true);
        }
        return $view;
    }

    /**
     * Results action.
     *
     * @return mixed
     */
    public function resultsAction()
    {
        // Special case -- redirect tag searches.
        $tag = $this->params()->fromQuery('tag');
        $query = $this->getRequest()->getQuery();
        if (!empty($tag)) {
            $query->set('lookfor', $tag);
            $query->set('type', 'tag');
        }
        if ($this->params()->fromQuery('type') == 'tag') {
            // Because we're coming in from a search, we want to do a fuzzy
            // tag search, not an exact search like we would when linking to a
            // specific tag name.
            $query = $this->getRequest()->getQuery()->set('fuzzy', 'true');
            return $this->forwardTo('Tag', 'Home');
        }

        // set the retain filters flag
        if( isset($this->getRequest()->getQuery()->retainFilters) ) {
            $this->getILS()->setSessionVar("retainFilters", ($this->getRequest()->getQuery()->retainFilters == "true") ? true : false);
        }

        // suppress the search prompt
        if( $this->params()->fromQuery('lookfor') == "Search For..." ) {
            $this->getRequest()->getQuery()->set('lookfor', "");
        }

        // make everything case insensitive
        if( $thisLF = $this->params()->fromQuery('lookfor') ) {
            $bits = explode(" ", $thisLF);
            foreach( $bits as $index => $thisBit ) {
                if( !in_array($thisBit, ["AND", "OR", "NOT"]) ) {
                    $bits[$index] = strtolower($thisBit);
                }
            }
            $queryArgs = $query->toArray();
            $queryArgs["lookfor"] = implode(" ", $bits);
            $query->fromArray($queryArgs);
        }
        $lfIndex = 0;
        while( $thisLF = $this->params()->fromQuery('lookfor' . $lfIndex) ) {
            $queryArgs = $query->toArray();
            foreach( $thisLF as $stIndex => $thisSearchTerm ) {
                $bits = explode(" ", $thisSearchTerm);
                foreach( $bits as $index => $thisBit ) {
                    if( !in_array($thisBit, ["AND", "OR", "NOT"]) ) {
                        $bits[$index] = strtolower($thisBit);
                    }
                }
                $queryArgs["lookfor" . $lfIndex][$stIndex] = implode(" ", $bits);
            }
            $query->fromArray($queryArgs);
            $lfIndex++;
        }

        // limit to only needed fields
        if( $this->getRequest()->getQuery("fl") === null ) {
            $this->getRequest()->getQuery()->set('fl', $this->getConfig()->LimitedSearchFields->shortList);
        }

        $this->getRequest()->getQuery()->set('hl.snippets', '10');

        // Default case -- standard behavior.
        $view = parent::resultsAction();

        // redirect to the record if it's an ISBN search with only one result
        if( $this->getRequest()->getQuery("type") == "ISN" && $view->results->getResultTotal() == 1) {
            $details = $this->getRecordRouter()->getTabRouteDetails($view->results->getResults()[0]->getUniqueID());
            $target = $this->url()->fromRoute($details['route'], $details['params']);
            return $this->redirect()->toUrl($target);
        }
        $view->searchType = ($this->getRequest()->getQuery("type") != null) ? $this->getRequest()->getQuery("type") : "Advanced";
        $view->formatCategories = $this->getConfig()->FormatCategories;
        return $view;
    }

    /**
     * Handle OpenSearch.
     *
     * @return \Zend\Http\Response
     */
    public function opensearchAction()
    {
        switch ($this->params()->fromQuery('method')) {
        case 'describe':
            $config = $this->getConfig();
            $xml = $this->getViewRenderer()->render(
                'search/opensearch-describe.phtml', ['site' => $config->Site]
            );
            break;
        default:
            $xml = $this->getViewRenderer()->render('search/opensearch-error.phtml');
            break;
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'text/xml');
        $response->setContent($xml);
        return $response;
    }

    /**
     * Provide OpenSearch suggestions as specified at
     * http://www.opensearch.org/Specifications/OpenSearch/Extensions/Suggestions/1.0
     *
     * @return \Zend\Http\Response
     */
    public function suggestAction()
    {
        // Always use 'AllFields' as our autosuggest type:
        $query = $this->getRequest()->getQuery();
        $query->set('type', 'AllFields');

        // Get suggestions and make sure they are an array (we don't want to JSON
        // encode them into an object):
        $suggester = $this->serviceLocator->get('VuFind\Autocomplete\Suggester');
        $suggestions = $suggester->getSuggestions($query, 'type', 'lookfor');

        // Send the JSON response:
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'application/javascript');
        $response->setContent(
            json_encode([$query->get('lookfor', ''), $suggestions])
        );
        return $response;
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
}
