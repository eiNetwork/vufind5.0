<?php
/**
 * VuFind Action Helper - Holds Support Methods
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
 * @package  Controller_Plugins
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace VuFind\Controller\Plugin;

/**
 * Zend action helper to perform holds-related actions
 *
 * @category VuFind
 * @package  Controller_Plugins
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class Holds extends AbstractRequestBase
{
    /**
     * Update ILS details with cancellation-specific information, if appropriate.
     *
     * @param \VuFind\ILS\Connection $catalog      ILS connection object
     * @param array                  $ilsDetails   Hold details from ILS driver's
     * getMyHolds() method
     * @param array                  $cancelStatus Cancel settings from ILS driver's
     * checkFunction() method
     *
     * @return array $ilsDetails with cancellation info added
     */
    public function addCancelDetails($catalog, $ilsDetails, $cancelStatus)
    {
        // Generate Form Details for cancelling Holds if Cancelling Holds
        // is enabled
        if ($cancelStatus) {
            if ($cancelStatus['function'] == "getCancelHoldLink") {
                // Build OPAC URL
                $ilsDetails['cancel_link']
                    = $catalog->getCancelHoldLink($ilsDetails);
            } elseif (isset($ilsDetails['cancel_details'])) {
                // The ILS driver provided cancel details up front. If the
                // details are an empty string (flagging lack of support), we
                // should unset it to prevent confusion; otherwise, we'll leave it
                // as-is.
                if ('' === $ilsDetails['cancel_details']) {
                    unset($ilsDetails['cancel_details']);
                } else {
                    $this->rememberValidId($ilsDetails['cancel_details']);
                }
            } else {
                // Default case: ILS supports cancel but we need to look up
                // details:
                $cancelDetails = $catalog->getCancelHoldDetails($ilsDetails);
                if ($cancelDetails !== '') {
                    $ilsDetails['cancel_details'] = $cancelDetails;
                    $this->rememberValidId($ilsDetails['cancel_details']);
                }
            }
        } else {
            // Cancelling holds disabled? Make sure no details get passed back:
            unset($ilsDetails['cancel_link']);
            unset($ilsDetails['cancel_details']);
        }

        return $ilsDetails;
    }

    /**
     * Process cancellation requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the cancellation, an
     * associative array keyed by item ID (empty if no cancellations performed)
     */
    public function cancelHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to cancel based on which button was pressed:
        $all = $params->fromPost('cancelAll');
        $selected = $params->fromPost('cancelSelected');
        if (!empty($all)) {
            $details = $params->fromPost('cancelAllIDS');
        } elseif (!empty($selected)) {
            $details = $params->fromPost('cancelSelectedIDS');
        } else {
            // No button pushed -- no action needed
            return [];
        }

        if (!empty($details)) {
            // Confirm?
            if ($params->fromPost('confirm') === "0") {
                if ($params->fromPost('cancelAll') !== null) {
                    return $this->getController()->confirm(
                        'hold_cancel_all',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        'confirm_hold_cancel_all_text',
                        [
                            'cancelAll' => 1,
                            'cancelAllIDS' => $params->fromPost('cancelAllIDS')
                        ]
                    );
                } else {
                    $cancelIDs = $params->fromPost('cancelSelectedIDS');
                    $replacement = ((count($cancelIDs) > 1) ? (count($cancelIDs) . " requests") : "request") . "?<br>";
                    foreach($params->fromPost('holdTitles') as $title) {
                        $replacement .= "<br><span class=\"bold\">Title: </span>" . urldecode($title);
                    }
                    $msg = [['msg' => 'confirm_hold_cancel_selected_text',
                             'html' => true,
                             'tokens' => ['%%holdData%%' => $replacement]]];
                    return $this->getController()->confirm(
                        'hold_cancel_selected',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $msg,
                        [
                            'cancelSelected' => 1,
                            'cancelSelectedIDS' =>
                                $params->fromPost('cancelSelectedIDS')
                        ]
                    );
                }
            }

            foreach ($details as $info) {
                // If the user input contains a value not found in the session
                // whitelist, something has been tampered with -- abort the process.
                if (!in_array($info, $this->getSession()->validIds)) {
                    $flashMsg->addMessage('error_inconsistent_parameters', 'error');
                    return [];
                }
            }

            // Add Patron Data to Submitted Data
            $cancelResults = $catalog->cancelHolds(
                ['details' => $details, 'patron' => $patron]
            );
            if ($cancelResults == false) {
                $flashMsg->addMessage('hold_cancel_fail', 'error');
            } else {
                $success = true;
                foreach( $cancelResults['items'] as $thisCancel ) {
                    $success &= $thisCancel['success'];
                }
                if ($success) {
                    $msg = $this->getController()
                        ->translate(($cancelResults['count'] == 1) ? 'hold_cancel_success_single' : 'hold_cancel_success_multiple');
                    $flashMsg->addMessage(['html' => true, 'msg' => $msg], 'info');
                } else {
                    $msg = $this->getController()
                        ->translate(($cancelResults['count'] == 1) ? 'hold_cancel_fail_single' : 'hold_cancel_fail_multiple');
                    $flashMsg->addMessage(['html' => true, 'msg' => $msg], 'error');
                }
                return $cancelResults;
            }
        } else {
            $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }

    /**
     * Process update requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the update, an
     * associative array keyed by item ID (empty if no updates performed)
     */
    public function updateHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to update based on which button was pressed:
        $details = $params->fromPost('updateIDs');

        if (!empty($details)) {
            if( $params->fromPost('changePickup') ) {
                // Add Patron Data to Submitted Data
                $updateResults = $catalog->updateHolds(
                    ['details' => $details, 'patron' => $patron, 'newLocation' => $params->fromPost('gatheredDetails')["pickUpLocation"]]
                );
                if ($updateResults == false) {
                    $flashMsg->addMessage('hold_all_overdrive_fail', 'error');
                } else {
                    if ($updateResults['success']) {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_success_single' : 'hold_update_success_multiple');
                        $flashMsg->addMessage($msg, 'info');
                    } else {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_fail_single' : 'hold_update_fail_multiple');
                        $flashMsg->addMessage($msg, 'error');
                    }
                    return $updateResults;
                }
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }
}
