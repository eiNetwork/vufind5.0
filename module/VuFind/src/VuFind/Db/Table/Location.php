<?php
/**
 * Table Definition for location
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
 * Table Definition for location
 *
 * @category VuFind2
 * @package  Db_Table
 * @author   Brad Patton <pattonb@einetwork.net>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class Location extends Gateway
{
    // add some constants to keep our code readable
    const VHPB_VALID = 1;
    const VHPB_TEMPORARY_CLOSURE = 2;
    const VHPB_PERMANENT_CLOSURE = 3;

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
        RowGateway $rowObj = null, $table = 'location'
    ) {
        parent::__construct($adapter, $tm, $cfg, $rowObj, $table);
    }

    /**
     * Retrieve a location object from the database based on locationId
     *
     * @param string $id locationId to use for retrieval.
     *
     * @return LocationRow
     */
    public function getByLocationId($id)
    {
        $callback = function ($select) use($id) {
            $select->where('locationId = "' . $id . '"');
        };
        $row = $this->select($callback);
        return $row->current();
    }

    /**
     * Retrieve a location object from the database based on code
     *
     * @param string $code Code to use for retrieval.
     *
     * @return LocationRow
     */
    public function getByCode($code)
    {
        $callback = function ($select) use($code) {
            $select->where('code = "' . $code . '"');
        };
        $row = $this->select($callback);
        return $row->current();
    }

    /**
     * Retrieve a location object from the database based on name
     *
     * @param string $name Name to use for retrieval.
     *
     * @return LocationRow
     */
    public function getByName($name)
    {
        $callback = function ($select) use($name) {
            $select->where('displayName = "' . $name . '"');
        };
        $row = $this->select($callback);
        return $row->current();
    }

    /**
     * Get location rows that can be used as pickups
     *
     * @return mixed
     */
    public function getPickupLocations()
    {
        $callback = function ($select) {
            $select->where('validHoldPickupBranch=' . self::VHPB_VALID)
                ->order('displayName');
        };
        return $this->select($callback);
    }

    /**
     * Get location row corresponding to current location
     *
     * @return mixed
     */
    public function getCurrentLocation($myIP)
    {
        $callback = function ($select) use($myIP) {
            $select->join(
                ['ip' => 'ip_lookup'], 'ip.locationid = location.locationId',
                []
            );
            $threeOctet = substr($myIP, 0, strrpos($myIP, "."));
            $twoOctet = substr($threeOctet, 0, strrpos($threeOctet, "."));
            $select->where('ip="' . $threeOctet . '.0/24" or ip="' . $twoOctet . '.0.0/16"' );
        };
        $location = $this->select($callback);
        return $location->current() ? $location->current()->toArray() : false;
    }
}
