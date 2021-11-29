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

class part {
    /** @var string The name of the part. */
    public $id = '';

    /** @var string The type of the part. */ 
    public $type = '';

    /** @var string The student's answer. */
    public $student_answer = '';

    /** @var string The expected answer. */
    public $correct_answer = '';

    /** @var string The student's score. */
    public $score = '';

    /** @var string The number of marks available. */
    public $marks = '';

    function __construct() {
        $this->gaps = array();
        $this->steps = array();
    }
}

