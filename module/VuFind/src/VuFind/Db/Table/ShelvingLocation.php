<?php
/**
 * Table Definition for shelving_location
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
 * @package  Db_Table
 * @author   Brad Patton <pattonb@einetwork.net>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace VuFind\Db\Table;

use VuFind\Db\Row\RowGateway;
use Zend\Db\Adapter\Adapter;

/**
 * Table Definition for shelving_location
 *
 * @category VuFind2
 * @package  Db_Table
 * @author   Brad Patton <pattonb@einetwork.net>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class ShelvingLocation extends Gateway
{
    /**
     * Constructor
     *
     * @param Adapter       $adapter Database adapter
     * @param PluginManager $tm      Table manager
     * @param array         $cfg     Zend Framework configuration
     * @param RowGateway    $rowObj  Row prototype object (null for default)
     * @param string        $table   Name of database table to interface with
     */
    public function __construct(Adapter $adapter, PluginManager $tm, $cfg,
        RowGateway $rowObj = null, $table = 'shelving_location'
    ) {
        parent::__construct($adapter, $tm, $cfg, $rowObj, $table);
    }

    /**
     * Retrieve a shelving location object from the database based on code
     *
     * @param string $code Code to use for retrieval.
     *
     * @return ShelvingLocationRow
     */
    public function getByCode($code)
    {
        $callback = function ($select) use($code) {
            $select->join(
                ['l' => 'location'],
                'shelving_location.locationId = l.locationId',
                ['branchCode' => 'code']
            );
            $select->where('shelving_location.code = "' . $code . '"');
        };
        $row = $this->select($callback);
        return $row->current();
    }

    /**
     * Retrieve a shelving location object from the database based on sierra name
     *
     * @param string $sierraName Name to use for retrieval.
     *
     * @return \Zend\Db\ResultSet\AbstractResultSet
     */
    public function getBySierraName($sierraName)
    {
        $callback = function ($select) use($sierraName) {
            $select->join(
                ['l' => 'location'],
                'shelving_location.locationId = l.locationId',
                ['branchCode' => 'code']
            );
            $select->where('sierraName = "' . str_replace("’", "'", str_replace("–", "-", $sierraName)) . '"');
        };
        return $this->select($callback);
    }
}
