<?php
/**
 * Flash message view helper
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
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
namespace VuFind\View\Helper\Truefit;

/**
 * Flash message view helper
 *
 * @category VuFind2
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class Flashmessages extends \VuFind\View\Helper\Bootstrap3\Flashmessages
{
    /**
     * Generate flash message <div>'s with appropriate classes based on message type.
     *
     * @return string $html
     */
    public function __invoke($showAnnouncements = true)
    {
        $html = '';
        $namespaces = ['error', 'info', 'success'];
        if( $showAnnouncements ) {
            array_splice($namespaces, 0, 0, 'announcement');
        }
        foreach ($namespaces as $ns) {
            $this->fm->setNamespace($ns);
            if( $ns == 'announcement' ) {
                $messages = $this->getView()->ils()->getDriver()->getAnnouncements($ns);
            } else {
                $messages = array_merge($this->fm->getMessages(), $this->fm->getCurrentMessages());
            }
            foreach (array_unique($messages, SORT_REGULAR) as $msg) {
                // Advanced form:
                if (!is_array($msg)) {
                    $msg = ['html' => true, 'msg' => $msg];
                }

                // Use a different translate helper depending on whether
                // or not we're in HTML mode.
                if (!isset($msg['translate']) || $msg['translate']) {
                    $helper = (isset($msg['html']) && $msg['html'])
                        ? 'translate' : 'transEsc';
                } else {
                    $helper = (isset($msg['html']) && $msg['html'])
                        ? false : 'escapeHtml';
                }
                $helper = $helper
                    ? $this->getView()->plugin($helper) : false;
                $tokens = isset($msg['tokens']) ? $msg['tokens'] : [];
                $default = isset($msg['default']) ? $msg['default'] : null;

                // append the message to the html
                $html .= '<div class="' . ((strpos($msg['msg'], '<nodesktop></nodesktop>') !== false) ? "EIN-hide " : "") . ((strpos($msg['msg'], 'fa-exclamation-triangle') == false) ? ((strpos($msg['msg'], 'fa-exclamation') == false) ? $this->getClassForNamespace($ns) : 'alert alert-info') : 'alert alert-danger') . ' alert-dismissible">';
                $closeCode = ($ns == 'announcement') ? (' onclick="$(\'#bonusFrame' . $msg['announceHash'] . '\').attr(\'src\', \'/MyResearch/DismissAnnouncement?hash=' . $msg['announceHash'] . '\')"') : '';
                if( !isset($msg['hideClose']) || !($msg['hideClose']) ) {
                    $html .= '<button type="button" class="close" data-dismiss="alert"' . $closeCode . '><i aria-hidden="true" class="fa fa-close"></i><span class="sr-only">Close</span></button>';
                }
                $html .= '<p class="message">' . ($helper ? $helper($msg['msg'], $tokens, $default) : $msg['msg']);
                $html .= '</p></div>';
                if( $ns == 'announcement' ) {
                    $html .= '<iframe id="bonusFrame' . $msg['announceHash'] .'" style="display:none"></iframe>';
                }
            }
            $this->fm->clearMessages();
            $this->fm->clearCurrentMessages();
        }
        return $html;
    }
}