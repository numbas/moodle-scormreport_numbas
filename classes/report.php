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
 * @package   scormreport_numbas
 * @subpackage numbas
 * @author    Christian Lawson-Perfect
 * @copyright 2020-2021 Newcastle University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace scormreport_numbas;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/interaction.php');
require_once(__DIR__ . '/question.php');
require_once(__DIR__ . '/part.php');

/** A SCORM report for Numbas exams.
 */
class report extends \mod_scorm\report {
    /**
     * Displays the full report.
     * @param \stdClass $scorm Full SCORM object.
     * @param \stdClass $cm full Course_module object.
     * @param \stdClass $course Full course object.
     * @param string $download Type of download being requested.
     */
    public function display($scorm, $cm, $course, $download) {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $this->scorm = $scorm;
        $this->cm = $cm;
        $this->course = $course;

        $action = optional_param('action', '', PARAM_ALPHA);
        switch ($action) {
            case 'viewattempt':
                $this->view_attempt();
                break;
            default:
                $this->show_table();
        }
    }

    /**
     * Rewrite the SCORM multiple choice format to something readable.
     * @param string $answer
     * @return string
     */
    private function fix_choice_answer($answer) {
        $bits = explode('[,]', $answer);
        foreach ($bits as $i => $b) {
            $bits[$i] = preg_replace('/(\d+)\[\.\](\d+)/', '($1,$2)', $b);
        }
        return implode(', ', $bits);
    }


    private function view_attempt() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $scormversion = strtolower(clean_param($this->scorm->version, PARAM_SAFEDIR));
        require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scormversion.'lib.php');

        $userid = required_param('user', PARAM_INT);
        $attempt = required_param('attempt', PARAM_INT);

        $scoes = $DB->get_records('scorm_scoes', array('scorm' => $this->scorm->id), 'sortorder, id');
        foreach ($scoes as $sco) {
            if ($sco->launch != '') {
                if ($trackdata = scorm_get_tracks($sco->id, $userid, $attempt)) {
                    if ($trackdata->status == '') {
                        $trackdata->status = 'notattempted';
                    }
                } else {
                    $trackdata = new stdClass();
                    $trackdata->status = 'notattempted';
                    $trackdata->total_time = '';
                }
                $interactions = array();
                $interactionregex = '/^cmi.interactions.(\d+).(id|description|learner_response|correct_responses.0.pattern|weighting|result|type)/';
                $suspenddata = array();
                foreach ($trackdata as $element => $value) {
                    if (preg_match($interactionregex, $element, $matches)) {
                        $n = $matches[1];
                        if (!array_key_exists($n, $interactions)) {
                            $interactions[$n] = new interaction($n);
                        }
                        $interaction = $interactions[$n];
                        $ielement = $matches[2];
                        $interaction->elements[$ielement] = $value;
                    }
                    if ($element == 'cmi.suspend_data') {
                        $suspenddata = json_decode($value, true);
                    }
                }
                $questions = array();
                ksort($interactions, SORT_NUMERIC);
                foreach ($interactions as $n => $interaction) {
                    if (!array_key_exists('id', $interaction->elements)) {
                        continue;
                    }
                    if (preg_match('/^q(\d+)p(\d+)(?:g(\d+)|s(\d+))?$/', $interaction->elements['id'], $pathm)) {
                        $qn = $pathm[1];
                        if (!array_key_exists($qn, $questions)) {
                            $questions[$qn] = new question();
                        }
                        $question = $questions[$qn];
                        $pn = $pathm[2];
                        if (!array_key_exists($pn, $question->parts)) {
                            $question->parts[$pn] = new part();
                        }
                        $part = $question->parts[$pn];
                        if (array_key_exists(3, $pathm) && $pathm[3] !== '') {
                            $gn = $pathm[3];
                            if (!array_key_exists($gn, $part->gaps)) {
                                $part->gaps[$gn] = new part();
                            }
                            $part = $part->gaps[$gn];
                        } else if (array_key_exists(4, $pathm) && $pathm[4] !== '') {
                            $sn = $pathm[3];
                            if (!array_key_exists($sn, $part->steps)) {
                                $part->steps[$sn] = new part();
                            }
                            $part = $part->steps[$n];
                        }
                        $elementmap = array(
                            'N' => 'N',
                            'id' => 'id',
                            'description' => 'type',
                            'learner_response' => 'student_answer',
                            'correct_responses.0.pattern' => 'correct_answer',
                            'result' => 'score',
                            'weighting' => 'marks'
                        );
                        foreach ($elementmap as $from => $to) {
                            if (array_key_exists($from, $interaction->elements)) {
                                $part->$to = $interaction->elements[$from];
                            }
                        }
                        switch ($part->type) {
                            case 'information':
                            case 'gapfill':
                                $part->studentanswer = '';
                                $part->correctanswer = '';
                                break;
                            case 'numberentry':
                                if (preg_match('/^(-?\d+(?:\.\d+)?)\[:\](-?\d+(?:\.\d+)?)$/', $part->correctanswer, $m)) {
                                    if ($m[1] == $m[2]) {
                                        $part->correctanswer = $m[1];
                                    } else {
                                        $part->correctanswer = "${m[1]} to ${m[2]}";
                                    }
                                }
                                break;
                            case '1_n_2':
                            case 'm_n_2':
                            case 'm_n_x':
                                $part->studentanswer = $this->fix_choice_answer($part->studentanswer);
                                $part->correctanswer = $this->fix_choice_answer($part->correctanswer);
                                break;
                        }
                        switch ($interaction->elements['type']) {
                            case 'fill-in':
                                $part->correctanswer = preg_replace('/^\{case_matters=(true|false)\}/', '', $part->correctanswer, 1);
                                if (preg_match('/^-?\d+(\.\d+)\[:\]-?\d+(\.\d+)$/', $part->correctanswer)) {
                                    $bits = explode('[:]', $part->correctanswer);
                                    if ($bits[0] == $bits[1]) {
                                        $part->correctanswer = $bits[0];
                                    } else {
                                        $part->correctanswer = str_replace('[:]', get_string('to', 'scormreport_numbas'), $part->correctanswer);
                                    }
                                }
                                break;
                        }
                    }
                }
                $parttypenames = array(
                    'information' => get_string('informationonly', 'scormreport_numbas'),
                    'extension' => get_string('extension', 'scormreport_numbas'),
                    '1_n_2' => get_string('chooseonefromalist', 'scormreport_numbas'),
                    'm_n_2' => get_string('chooseseveralfromalist', 'scormreport_numbas'),
                    'm_n_x' => get_string('matchchoiceswithanswers', 'scormreport_numbas'),
                    'numberentry' => get_string('numberentry', 'scormreport_numbas'),
                    'matrix' => get_string('matrixentry', 'scormreport_numbas'),
                    'patternmatch' => get_string('matchtextpattern', 'scormreport_numbas'),
                    'jme' => get_string('mathematicalexpression', 'scormreport_numbas'),
                    'gapfill' => get_string('gapfill', 'scormreport_numbas')
                );
                ksort($questions, SORT_NUMERIC);
                foreach ($questions as $qn => $question) {
                    $rows = array();
                    $qs = $suspenddata['questions'][$qn];
                    $qname = $qs['name'];

                    $header = \html_writer::start_tag('h3');
                    $header .= get_string('questionx', 'scormreport_numbas', $qn + 1);
                    $header .= ' - ' . $qname;
                    $header .= \html_writer::end_tag('h3');
                    echo $header;

                    $table = new \flexible_table('mod-scorm-report');
                    $columns = array('part', 'type', 'student_answer', 'correct_answer', 'score', 'marks');
                    $headers = array(
                        get_string('part', 'scormreport_numbas'),
                        get_string('type', 'scormreport_numbas'),
                        get_string('studentsanswer', 'scormreport_numbas'),
                        get_string('correctanswer', 'scormreport_numbas'),
                        get_string('score', 'scormreport_numbas'),
                        get_string('marks', 'scormreport_numbas')
                    );
                    $table->define_baseurl($PAGE->url);
                    $table->define_columns($columns);
                    $table->define_headers($headers);
                    $table->set_attribute('id', 'attempt');
                    $table->setup();
                    ksort($question->parts, SORT_NUMERIC);
                    foreach ($question->parts as $pn => $part) {
                        if (!array_key_exists($pn, $qs['parts'])) {
                            continue;
                        }
                        $ps = $suspenddata['questions'][$qn]['parts'][$pn];
                        $rows[] = array(
                            'suspend' => $ps,
                            'part' => $part
                        );
                        ksort($part->gaps, SORT_NUMERIC);
                        foreach ($part->gaps as $gn => $gap) {
                            if (array_key_exists('gaps', $ps) && array_key_exists($gn, $ps['gaps'])) {
                                $gs = $ps['gaps'][$gn];
                            } else {
                                $gs = array('name' => $ps['name'] . get_string('gapnumber', 'scormreport_numbas', $gn));
                            }
                            $rows[] = array(
                                'suspend' => $gs,
                                'part' => $gap,
                                'indent' => 1
                            );
                        }
                        ksort($part->steps, SORT_NUMERIC);
                        foreach ($part->steps as $sn => $step) {
                            if (!array_key_exists($sn, $ps['steps'])) {
                                continue;
                            }
                            $ss = $ps['steps'][$sn];
                            $rows[] = array(
                                'suspend' => $ss,
                                'part' => $step,
                                'indent' => 1
                            );
                        }
                    }
                    foreach ($rows as $row) {
                        $ps = $row['suspend'];
                        $part = $row['part'];
                        $name = $ps ? $ps['name'] : '';
                        if (substr($name, 0, strlen($qname)) == $qname) {
                            $name = substr($name, strlen($qname));
                        }
                        if (array_key_exists('indent', $row)) {
                            $name = '&nbsp;&nbsp;' . $name;
                        }
                        $type = $part->type;
                        if (array_key_exists($type, $parttypenames)) {
                            $type = $parttypenames[$type];
                        }
                        $table->add_data(array(
                            $name,
                            $type,
                            '<code>' . $part->studentanswer . '</code>',
                            '<code>' . $part->correctanswer . '</code>',
                            $part->score,
                            $part->marks
                        ));
                    }
                    $table->finish_output();
                }
            }
        }

    }

    /** Portions of this function copied from the scormreport_interactions module by Dan Marsden and Ankit Kumar Agarwal
     */
    private function show_table() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $contextmodule = \context_module::instance($this->cm->id);
        $attemptid = optional_param_array('attemptid', array(), PARAM_RAW);

        $currentgroup = groups_get_activity_group($this->cm, true);

        $pagesize = 20;

        $nostudents = false;

        if (!$students = get_users_by_capability($contextmodule, 'mod/scorm:savetrack', 'u.id', '', '', '', '', '', false)) {
            echo $OUTPUT->notification(get_string('nostudentsyet'));
            $nostudents = true;
            $allowedlist = '';
        } else {
            $allowedlist = array_keys($students);
        }
        unset($students);

        if ( !$nostudents ) {
            $coursecontext = \context_course::instance($this->course->id);

            $columns = array();
            $headers = array();
            $columns[] = 'fullname';
            $headers[] = get_string('name');

            $extrafields = \core_user\fields::for_identity($coursecontext, false)->get_required_fields();
            foreach ($extrafields as $field) {
                $columns[] = $field;
                $headers[] = \core_user\fields::get_display_name($field);
            }
            $columns[] = 'attempt';
            $headers[] = get_string('attempt', 'scorm');
            $columns[] = 'start';
            $headers[] = get_string('started', 'scorm');
            $columns[] = 'finish';
            $headers[] = get_string('last', 'scorm');
            $columns[] = 'score';
            $headers[] = get_string('score', 'scorm');

            $params = array();
            list($usql, $params) = $DB->get_in_or_equal($allowedlist, SQL_PARAMS_NAMED);

            $select = 'SELECT DISTINCT '.$DB->sql_concat('u.id', '\'#\'', 'COALESCE(st.attempt, 0)').' AS uniqueid, ';
            $select .= 'st.scormid AS scormid, st.attempt AS attempt ' .
                    \core_user\fields::for_userpic()->including('idnumber')->get_sql('u', false, '', 'userid')->selects . ' ' .
                    \core_user\fields::for_identity($coursecontext, false)->excluding('email', 'idnumber')->get_sql('u', false, '')->selects;

            $from = 'FROM {user} u ';
            $from .= 'LEFT JOIN {scorm_scoes_track} st ON st.userid = u.id AND st.scormid = '.$this->scorm->id;

            $where = ' WHERE u.id ' .$usql. ' AND st.userid IS NOT NULL';

            $countsql = 'SELECT COUNT(DISTINCT('.$DB->sql_concat('u.id', '\'#\'', 'COALESCE(st.attempt, 0)').')) AS nbresults, ';
            $countsql .= 'COUNT(DISTINCT('.$DB->sql_concat('u.id', '\'#\'', 'st.attempt').')) AS nbattempts, ';
            $countsql .= 'COUNT(DISTINCT(u.id)) AS nbusers ';
            $countsql .= $from.$where;

            $table = new \flexible_table('mod-scorm-report');

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->define_baseurl($PAGE->url);

            $table->sortable(true);

            $table->column_suppress('fullname');
            foreach ($extrafields as $field) {
                $table->column_suppress($field);
            }

            $table->no_sorting('start');
            $table->no_sorting('finish');
            $table->no_sorting('score');

            $table->column_class('fullname', 'bold');
            $table->column_class('score', 'bold');

            $table->set_attribute('cellspacing', '0');
            $table->set_attribute('id', 'attempts');
            $table->set_attribute('class', 'generaltable generalbox');

            $table->setup();

            $sort = $table->get_sql_sort();
            if (empty($sort)) {
                $sort = ' ORDER BY uniqueid';
            } else {
                $sort = ' ORDER BY '.$sort;
            }

            list($twhere, $tparams) = $table->get_sql_where();
            if ($twhere) {
                $where .= ' AND '.$twhere;
                $params = array_merge($params, $tparams);
            }

            if (!empty($countsql)) {
                $count = $DB->get_record_sql($countsql, $params);
                $totalinitials = $count->nbresults;
                if ($twhere) {
                    $countsql .= ' AND '.$twhere;
                }
                $count = $DB->get_record_sql($countsql, $params);
                $total  = $count->nbresults;
            }

            $table->pagesize($pagesize, $total);

            echo \html_writer::start_div('scormattemptcounts');
            if ( $count->nbresults == $count->nbattempts ) {
                echo get_string('reportcountattempts', 'scorm', $count);
            } else if ( $count->nbattempts > 0 ) {
                echo get_string('reportcountallattempts', 'scorm', $count);
            } else {
                echo $count->nbusers.' '.get_string('users');
            }
            echo \html_writer::end_div();

            $attempts = $DB->get_records_sql($select.$from.$where.$sort, $params,
            $table->get_page_start(), $table->get_page_size());
            echo \html_writer::start_div('', array('id' => 'scormtablecontainer'));
            $table->initialbars($totalinitials > 20);
            if ($attempts) {
                foreach ($attempts as $scouser) {
                    $row = array();
                    if (!empty($scouser->attempt)) {
                        $timetracks = scorm_get_sco_runtime($this->scorm->id, false, $scouser->userid, $scouser->attempt);
                    } else {
                        $timetracks = '';
                    }
                    $url = new \moodle_url('/user/view.php', array('id' => $scouser->userid, 'course' => $this->course->id));
                    $row[] = \html_writer::link($url, fullname($scouser));
                    foreach ($extrafields as $field) {
                        $row[] = s($scouser->{$field});
                    }
                    if (empty($timetracks->start)) {
                        $row[] = '-';
                        $row[] = '-';
                        $row[] = '-';
                        $row[] = '-';
                    } else {
                        $url = new \moodle_url($PAGE->url,
                            array(
                                'action' => 'viewattempt',
                                'user' => $scouser->userid,
                                'attempt' => $scouser->attempt
                            )
                        );
                        $row[] = \html_writer::link($url, $scouser->attempt);
                        $row[] = userdate($timetracks->start);
                        $row[] = userdate($timetracks->finish);
                        $row[] = scorm_grade_user_attempt($this->scorm, $scouser->userid, $scouser->attempt);
                    }

                    $table->add_data($row);
                }
            }
            $table->finish_output();
            echo \html_writer::end_div();
        } else {
            echo $OUTPUT->notification(get_string('noactivity', 'scorm'));
        }
    }
}
