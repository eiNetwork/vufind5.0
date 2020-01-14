<?php
/**
 * "EIN Get Checkouts" AJAX handler
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
use VuFind\Session\Settings as SessionSettings;
use Zend\Config\Config;
use Zend\Mvc\Controller\Plugin\Params;
use Zend\View\Renderer\RendererInterface;

/**
 * "EIN Get Checkouts" AJAX handler
 *
 * This is responsible for loading the patron's checked out items.
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
class EINGetCheckouts extends AbstractBase
{
    /**
     * Loader
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
     * View renderer
     *
     * @var RendererInterface
     */
    protected $renderer;

    /**
     * Constructor
     *
     * @param SessionSettings        $ss        Session settings
     * @param RendererInterface      $renderer  View renderer
     * @param Loader                 $loader    Record loader
     * @param User|bool              $user      Logged in user (or false)
     */
    public function __construct(SessionSettings $ss, RendererInterface $renderer, Loader $loader, $user)
    {
        $this->sessionSettings = $ss;
    
        $this->renderer = $renderer;
        $this->loader = $loader;
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

        $inputs = $params->fromPost('checkout', $params->fromQuery('checkout', []));
        $checkouts = [];

        try {
            foreach( $inputs as $thisInput ) {
                $thisCheckout = json_decode($thisInput["checkout"], true);
                $driver = $this->loader->load( $thisCheckout["fullID"], DEFAULT_SEARCH_BACKEND, true );

                $checkouts[$thisInput["key"]] = $this->renderer->record($driver)->getCheckoutEntry($thisCheckout, $this->user, "checkout_" . $thisInput["checkoutType"]);
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

        // Done
        return $this->formatResponse(["status" => 'OK', "checkouts" => $checkouts]);
    }
}
