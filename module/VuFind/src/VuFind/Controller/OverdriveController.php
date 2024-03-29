<?php
/**
 * Overdrive Controller
 *
 * PHP version 7
 *
 * @category VuFind
 * @package  Controller
 * @author   Brent Palmer <brent-palmer@ipcl.org>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */

namespace VuFind\Controller;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Log\LoggerAwareInterface;


/**
 * Overdrive Controller supports actions for Overdrive Integration
 *
 * @category VuFind
 * @package  Controller
 * @author   Brent Palmer <brent-palmer@ipcl.org>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:controllers Wiki
 */
class OverdriveController extends AbstractBase implements LoggerAwareInterface
{
    use \VuFind\Log\LoggerAwareTrait {
        logError as error;
    }

    /**
     * Overdrive Connector
     *
     * @var \VuFind\DigitalContent\OverdriveConnector $connector Overdrive Connector
     */
    protected $connector;

    /**
     * Constructor
     *
     * @param ServiceLocatorInterface $sm Service locator
     */
    public function __construct(ServiceLocatorInterface $sm)
    {
        $this->setLogger($sm->get('VuFind\Logger'));

        $this->connector = $sm->get('VuFind\DigitalContent\OverdriveConnector');
        parent::__construct($sm);
        $this->debug("ODRC constructed");
    }


    /**
     * My Content Action
     * Prepares the view for the Overdrive MyContent template.
     *
     * @return array|bool|\Zend\View\Model\ViewModel
     */
    public function mycontentAction()
    {
        $this->debug("ODC mycontent action");

        //TODO get hold and checkoutlimit using the Patron Info API

        //force login
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }
        $holds = array();
        $checkouts = array();
        $checkoutsUnavailable = false;
        $holdsUnavailable = false;

        //check on this patrons's access to Overdrive
        $odAccessResult = $this->connector->getAccess();

        if (!$odAccessResult->status) {

            $this->flashMessenger()->addErrorMessage(
                $this->translate(
                    $odAccessResult->code,
                    ["%%message%%" => $odAccessResult->msg]
                )
            );
            $checkoutsUnavailable = true;
            $holdsUnavailable = true;
        }


        if ($odAccessResult->status) {
            //get the current Overdrive checkouts
            //for this user and add to our array of IDS
            $checkoutResults = $this->connector->getCheckouts(true);
            if (!$checkoutResults->status) {
                $this->flashMessenger()->addMessage(
                    $checkoutResults->code, 'error'
                );
                $checkoutsUnavailable = true;
            } else {
                foreach ($checkoutResults->data as $checkout) {
                    $mycheckout = [];
                    $mycheckout['checkout'] = $checkout;
                    $mycheckout['record']
                        = $this->serviceLocator->get('VuFind\Record\Loader')
                        ->load(strtolower($checkout->reserveId));
                    $checkouts[] = $mycheckout;
                }
            }
            //get the current Overdrive holds for this user and add to
            // our array of IDS
            $holdsResults = $this->connector->getHolds(true);
            if (!$holdsResults->status) {
                if ($checkoutResults->status) {
                    $this->flashMessenger()->addMessage(
                        $holdsResults->code, 'error'
                    );
                }
                $holdsUnavailable = true;
            } else {
                foreach ($holdsResults->data as $hold) {
                    $myhold = [];
                    $myhold['hold'] = $hold;
                    $myhold['record']
                        = $this->serviceLocator->get('VuFind\Record\Loader')
                        ->load(strtolower($hold->reserveId));
                    $holds[] = $myhold;
                }
            }
        }
        //todo: get reading history

        $view = $this->createViewModel(
            compact(
                'checkoutsUnavailable', 'holdsUnavailable',
                'checkouts', 'holds'
            )
        );

        $view->setTemplate('myresearch/odmycontent');
        return $view;
    }

    /**
     * Get Status Action
     * Supports the ajax getStatus calls
     *
     * @return array|bool|\Zend\View\Model\ViewModel
     */
    public function getStatusAction()
    {
        $this->debug("ODC getStatus action");
        $ids = $this->params()->fromPost(
            'id', $this->params()->fromQuery('id', [])
        );
        $this->debug("ODRC availability for :" . print_r($ids, true));
        $result = $this->connector->getAvailabilityBulk($ids);
        $view = $this->createViewModel(compact('ids', 'result'));
        $view->setTemplate('RecordDriver/SolrOverdrive/status-full');
        $this->layout()->setTemplate('layout/lightbox');
        return $view;
    }

    /**
     * Hold Action
     *
     * Hold Action handles all of the actions involving
     * Overdrive content including checkout, hold, cancel hold etc.
     *
     * @return array|bool|\Zend\View\Model\ViewModel
     * @todo   Deal with situation that an unlogged in user requests
     *     an action but the action is no longer valid since they
     *     already have the content on hold/checked out or do not have acceess
     */
    public function holdAction()
    {
        $this->debug("ODC Hold action");

        if (!is_array($patron = $this->catalogLogin())) {
            $patron->skipFlashMessages = true;
            return $patron;
        }
        $this->debug("patron: " . print_r($patron, true));
        //TODO Check patron eligibility
        //$driver->checkPatronAccess();

        $od_id = $this->params()->fromQuery('od_id');
        $rec_id = $this->params()->fromQuery('rec_id');
        $action = $this->params()->fromQuery('action');
        $holdEmail = "";

        //place hold action comes in through the form
        if (null !== $this->params()->fromPost('doAction')) {
            $action = $this->params()->fromPost('doAction');
        }

        //place hold action comes in through the form
        if (null !== $this->params()->fromPost('getTitleFormat')) {
            $format = $this->params()->fromPost('getTitleFormat');
        }

        $format = $this->params()->fromQuery('getTitleFormat');

        $this->debug("ODRC od_id=$od_id rec_id=$rec_id action=$action");
        //load the Record Driver.  Should be a SolrOverdrive  driver.
        $driver = $this->serviceLocator->get('VuFind\Record\Loader')->load(
            $rec_id
        );

        $formats = $driver->getDigitalFormats();
        $title = $driver->getTitle();
        $cover = $driver->getThumbnail('small');
        $listAuthors = $driver->getPrimaryAuthors();
        if (!$action) {
            //double check the availability in case it
            //has changed since the page was loaded.
            $avail = $driver->getOverdriveAvailability();
            if ($avail->copiesAvailable > 0) {
                $action = "checkoutConfirm";
            } else {
                $action = "holdConfirm";

            }
        }

        if ($action == "checkoutConfirm") {
            $result = $this->connector->getResultObject();
            //check to make sure they don't already have this checked out
            //shouldn't need to refresh.
            if ($checkout = $this->connector->getCheckout($od_id, false)) {
                $result->status = false;
                $result->data->checkout = $checkout;
                $result->code = "OD_CODE_ALREADY_CHECKED_OUT";
            } elseif ($hold = $this->connector->getHold($od_id, false)) {
                if($hold->holdReadyForCheckout){
                    $this->debug("hold is avail for checkout: $od_id");
                    $result->status = true;
                }else {
                    $result->status = false;
                    $result->data->hold = $hold;
                    $result->code = "OD_CODE_ALREADY_ON_HOLD";
                }
            } else {
                $result->status = true;
            }
            $actionTitleCode = "od_checkout";
        } elseif ($action == "holdConfirm") {
            $result = $this->connector->getResultObject();
            //check to make sure they don't already have this checked out
            //shouldn't need to refresh.
            if ($checkout = $this->connector->getCheckout($od_id, false)) {
                $result->status = false;
                $result->data->checkout = $checkout;
                $result->code = "OD_CODE_ALREADY_CHECKED_OUT";
                $this->debug("title already checked out: $od_id");
            } elseif ($hold = $this->connector->getHold($od_id, false)) {
                $result->status = false;
                $result->data->hold = $hold;
                $result->code = "OD_CODE_ALREADY_ON_HOLD";
                $this->debug("title already on hold: $od_id");
            } else {
                $result->status = true;
            }
            $actionTitleCode = "od_hold";
        } elseif ($action == "cancelHoldConfirm") {
            $actionTitleCode = "od_cancel_hold";
        } elseif ($action == "suspHoldConfirm") {
            $actionTitleCode = "od_susp_hold";
        } elseif ($action == "editHoldConfirm") {
            $actionTitleCode = "od_susp_hold_edit";
        } elseif ($action == "editHoldEmailConfirm") {
            $actionTitleCode = "od_edit_hold_email";
            $hold = $this->connector->getHold($od_id, false);
            $holdEmail = $hold->emailAddress;

        } elseif ($action == "returnTitleConfirm") {
            $actionTitleCode = "od_early_return";
/*
        } elseif ($action == "getTitleConfirm") {
            //$checkout = $this->connector->getCheckout($od_id, false);
            //get only formats that are available...
            $formats = $driver->getAvailableDigitalFormats();
            $actionTitleCode = "od_get_title";
*/
        } elseif ($action == "doCheckout") {
            $actionTitleCode = "od_checkout";
            $result = $this->connector->doOverdriveCheckout($od_id);
            $this->getILS()->clearSessionVar("checkouts");

            // if this item was being held by this patron, we need to invalidate their holds
            $holds = $this->getILS()->getSessionVar("holds") ?? [];
            foreach( $holds as $thisHold ) {
                if( (strtolower($thisHold["reserveId"] ?? "unknown")) == strtolower($od_id) ) {
                    $this->getILS()->setSessionVar("staleHoldsHash", md5(json_encode($holds)));
                }
            }

        } elseif ($action == "placeHold") {
            $actionTitleCode = "od_hold";
            $email = $this->params()->fromQuery('email');
            $result = $this->connector->placeOverDriveHold($od_id, $email);

            if( $result->status ) {
                $this->getILS()->removeFromBookCart($rec_id);
                $result->code = "od_hold_place_success";
            }else{
                $result->code = "od_hold_place_failure";
            }
        } elseif ($action == "cancelHold") {
            $actionTitleCode = "od_cancel_hold";
            $result = $this->connector->cancelHold($od_id);
            if($result->status){
                $result->code = "od_hold_cancel_success";
            }else{
                $result->code = "od_hold_cancel_failure";
            }

        } elseif ($action == "returnTitle") {
            $actionTitleCode = "od_early_return";
            $result = $this->connector->returnResource($od_id);
            $this->getILS()->clearSessionVar("checkouts");
            if($result->status){
                $result->code = "od_return_success";
            }else{
                $result->code = "od_return_failure";
            }

        } elseif ($action == "getTitle") {
            $actionTitleCode = "od_get_title";
            //need to get server name etc.  maybe this: getServerUrl();
            $this->debug("Here:" . $this->getServerUrl('overdrive-hold'));
            $result = $this->connector->getDownloadLink(
                $od_id, $format, $this->getServerUrl('myresearch-checkedout')
            );
            if ($result->status) {
                if ( !$this->connector->getCheckout($od_id, false)->isFormatLockedIn ) {
                    $this->getILS()->clearSessionVar("checkouts");
                }

                //Redirect to resource
                $url = $result->data->downloadLink;
                $this->debug("redirecting to: $url");
                //$this->redirect()
                //$this->redirect()->toUrl($url);
                header("Location: $url");
                exit();
            }

        } elseif ($action == "getTitleRedirect") {
            $actionTitleCode = "od_get_title";
            $this->debug(
                "Get Title action. Getting downloadredirect"
            );
            $result = $this->connector->getDownloadRedirect($od_id);
            if ($result->status) {
                $this->debug("DL redir: ".$result->data->downloadRedirect);

                //Redirect to resource
                $url = $result->data->downloadRedirect;
                $this->debug("redirecting to: $url");
                //$this->redirect()
                //$this->redirect()->toUrl($url);
                header("Location: $url");
                exit();
            }else{
                $this->debug("result: ".print_r($result,true));
                $result->code = "od_gettitle_failure";
            }
        } else {
            $this->logWarning("overdrive action not defined: $action");
        }

        // add message to results
        $this->flashMessenger()->setNamespace($result->status ? 'info' : 'error')->addMessage($result->msg);

        $view = $this->createViewModel(
            compact(
                'od_id', 'rec_id', 'action',
                'result', 'formats', 'cover', 'title', 'actionTitleCode',
                'listAuthors'
            )
        );

        $view->setTemplate('blankModal');
        $view->reloadParent = true;
        return $view;
    }

    /**
     * Select format Action
     *
     * Select format Action shows a dialog with the available formats to allow the patron
     * to select which one they want to lock in.
     *
     * @return \Zend\View\Model\ViewModel
     */
    public function selectFormatAction()
    {
        $od_id = $this->params()->fromQuery('od_id');
        $rec_id = $this->params()->fromQuery('rec_id');

        $driver = $this->serviceLocator->get('VuFind\Record\Loader')->load(
            $rec_id
        );

        $view = $this->createViewModel();
        $view->rec_id = $rec_id;
        $view->od_id = $od_id;
        $view->driver = $driver;
        $view->setTemplate('record/overdriveDownload');
        return $view;
    }
}
