<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * SCORM report class for Numbas SCORM packages
 * @package   scormreport
 * @subpackage numbas
 * @author    Christian Lawson-Perfect
 * @copyright 2020-2021 Newcastle University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace scormreport_numbas;

defined('MOODLE_INTERNAL') || die();

class interaction {
    /** @var int The index of the interaction in the SCORM data model. */
    public $N;

    /** @var array An associative array storing each of the data model elements associated with this interaction. */
    public $elements = array();

    function __construct($N) {
        $this->$N = $N;
        $this->elements = array();
    }
}


