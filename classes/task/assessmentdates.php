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
* A scheduled task for scripted database integrations.
*
* @package    local_assessmentdates - template
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_assessmentdates\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
global $CFG;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
* A scheduled task for scripted external database integrations.
*
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
class assessmentdates extends \core\task\scheduled_task {
    
    /**
    * Get a descriptive name for this task (shown to admins).
    *
    * @return string
    */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentdates');
    }
    
    /**
    * Run sync.
    */
    public function execute() {
        // Access global variables.
        global $CFG, $DB;
        
        // Set default submission and feedback times per policy.
        $submissiontime = date('H:i:s', strtotime('3pm'));
        $feedbacktime = date('H:i:s', strtotime('9am'));
        
        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();
        
        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tableassm = get_string('assessmentstable', 'local_assessmentdates');
        $tablegrades = get_string('stuassesstable', 'local_assessmentdates');
        
        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tableassm) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $tableassm . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$tablegrades) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $tablegrades . '<br>';
        }
        echo 'Starting connection...<br>';
        
        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
                echo 'Error while communicating with external database <br>';
                return 1;
            }
            
            // Create arrays to work on rather than constant reading/writing from Db.
            // Get duedate and gradingduedate from assign/quiz tables where assignment has link code.
            /********************************************************
            * ARRAY (LINK CODE-> StdClass Object)                  *
            *     idnumber                                         *
            *     id                                               *
            *     name                                             *
            *     duedate (UNIX timestamp)                         *
            *     gradingduedate (UNIX timestamp)                  *
            ********************************************************/
            // Get assignments.
            $sqldates = $DB->get_records_sql(
                'SELECT a.id as id,m.id as cm, m.idnumber as linkcode,a.name,a.duedate,a.gradingduedate
                FROM {course_modules} m
                JOIN {assign} a ON m.instance = a.id
                JOIN {modules} mo ON m.module = mo.id
                WHERE m.idnumber IS NOT null
                AND m.idnumber != ""
                AND m.idnumber NOT LIKE "%18/19%"
                AND m.idnumber NOT LIKE "%17/18%"
                AND mo.name = "assign"'
            );
            // Get quizes.
            $sqlquizdates = $DB->get_records_sql(
                'SELECT q.id as id,m.id as cm, m.idnumber as linkcode, q.name, q.timeclose as duedate, null as gradingduedate
                FROM {course_modules} m
                JOIN {quiz} q ON m.instance = q.id
                JOIN {modules} mo ON m.module = mo.id
                WHERE m.idnumber IS NOT null
                AND m.idnumber != ""
                AND m.idnumber NOT LIKE "%18/19%"
                AND m.idnumber NOT LIKE "%17/18%"
                AND mo.name = "quiz"'
            );
            // Create reference array of assignment id and link code from mdl.
            $assignmdl = array();
            foreach ($sqldates as $sd) {
                $assignmdl[$sd->linkcode]['id'] = $sd->id;
                $assignmdl[$sd->linkcode]['cm'] = $sd->cm;
                $assignmdl[$sd->linkcode]['lc'] = $sd->linkcode;
                $assignmdl[$sd->linkcode]['name'] = $sd->name;
                $assignmdl[$sd->linkcode]['duedate'] = $sd->duedate;
            }
            // Add quiz dates to assignments array.
            foreach ($sqlquizdates as $sd) {
                $assignmdl[$sd->linkcode]['id'] = $sd->id;
                $assignmdl[$sd->linkcode]['cm'] = $sd->cm;
                $assignmdl[$sd->linkcode]['lc'] = $sd->linkcode;
                $assignmdl[$sd->linkcode]['name'] = $sd->name;
                $assignmdl[$sd->linkcode]['duedate'] = $sd->duedate;
            }
            
            // Ensure array is empty.
            $assessments = array();
            // Read assessment data from external table into array.
            /********************************************************
            * ARRAY                                                *
            *     id                                               *
            *     mav_idnumber                                     *
            *     assessment_number                                *
            *     assessment_name                                  *
            *     assessment_type                                  *
            *     assessment_weight                                *
            *     assessment_idcode - THIS IS THE MAIN LINK ID     *
            *     assessment_markscheme_name                       *
            *     assessment_markscheme_code                       *
            *     assessment_duedate                               *
            *     assessment_feedbackdate                          *
            ********************************************************/
            // Fetch from external database.
            $sql = $externaldb->db_get_sql($tableassm, array(), array(), true);
            // Read database results into usable array.
            if ($rs = $extdb->Execute($sql)) {
                if (!$rs->EOF) {
                    while ($fields = $rs->FetchRow()) {
                        $fields = array_change_key_case($fields, CASE_LOWER);
                        $fields = $externaldb->db_decode($fields);
                        $assessments[] = $fields;
                    }
                }
                $rs->Close();
            } else {
                // Report error if required.
                $extdb->Close();
                echo 'Error reading data from the external assessments table<br>';
                return 4;
            }
            
            // Set due dates and feedback/grade by dates.
            // Echo statements output to cron or when task run immediately for debugging.
            foreach ($assessments as $a) {
                // Error trap - ensure we have an assessment link id.
                if ($key = array_key_exists($a['assessment_idcode'], $assignmdl)) {
                    // Set main key fields (makes code more readable only).
                    $idcode = $assignmdl[$a['assessment_idcode']]['id'];
                    $linkcode = $assignmdl[$a['assessment_idcode']]['lc'];
                    
                    echo '<br><br>'.$linkcode.':'.$idcode.' - Assessment dates<br>';
                    if (strpos($linkcode, '18/19')  !== false) {
                        echo '18/19 HANDIN 6pm<br>';
                        $submissiontime = date('H:i:s', strtotime('6pm'));
                    }
                    
                    
                    // Convert Moodle due date UNIX time stamp to Y-m-d H:i:s format.
                    $due = date('Y-m-d H:i:s', $assignmdl[$a['assessment_idcode']]['duedate']);
                    $duedate = date('Y-m-d', $assignmdl[$a['assessment_idcode']]['duedate']);
                    $mdlduetime = date('H:i:s', $assignmdl[$a['assessment_idcode']]['duedate']);
                    $duetime = $submissiontime;
                    echo 'Mdl-due date/time '.$due.' - Mdl Due Date '.$duedate.' : Mdl Due Time  '.$mdlduetime.'<br>';
                    echo 'Ext-due date '.$a['assessment_duedate'].' Ext due time '.$a['assessment_duetime'].'<br>';
                    
                    // Set duedate in external Db.
                    if (!empty($a['assessment_duedate'])) { // If external Db already has a due date.
                        // And external duedate is different, set duedate value as Moodle value.
                        if ($a['assessment_duedate'] != $duedate || $a['assessment_duetime'] != $duetime) {
                            $sql = "UPDATE " . $tableassm . "
                            SET assessment_duedate = '" . $duedate . "',
                            assessment_duetime = '" . $duetime . "', assessment_changebymoodle = 1
                            WHERE assessment_idcode = '" . $linkcode . "';";
                            echo $sql;
                            $extdb->Execute($sql);
                            echo $idcode . " Due Date updated on external Db - " . $duedate . "<br><br>";
                        }
                    } else { // If external Db doesn't have a due date set.
                        if (isset($assignmdl[$a['assessment_idcode']]['duedate'])) { // But MDL does, set duedate value as Moodle value.
                            $sql = "UPDATE " . $tableassm . " SET assessment_duedate = '" . $duedate . "',
                            assessment_duetime = '" . $duetime . "', assessment_changebymoodle = 1
                            WHERE assessment_idcode = '" . $linkcode . "';";
                            echo $sql;
                            $extdb->Execute($sql);
                            echo $idcode . ' Due Date xported.<br>';
                        }
                    }
                    
                    // Get gradeby date from external Db and apply to Mdl if different.
                    if (isset($sqldates[$idcode]) ) {
                        // Get times from Moodle and external database.
                        $gradingduedate = date('Y-m-d', $sqldates[$idcode]->gradingduedate);
                        $gradingduetime = $feedbacktime;
                        echo 'Mdl-Feedback due date/time '.date('Y-m-d H:i:s', $sqldates[$idcode]->gradingduedate)
                        .' - Mdl Feedback Due Date '.$gradingduedate.' : Mdl Feedback Due Time  '.$gradingduetime.'<br>';
                        echo 'Ext-Feedback due date '.$a['assessment_feedbackdate'].' Ext Feedback due time '
                        .$a['assessment_feedbacktime'].'<br>';
                        // If Moodle feedback due date and time dont match external.
                        if ($gradingduedate != $a['assessment_feedbackdate'] || $gradingduetime != $a['assessment_feedbacktime']) {
                            // Create array of time settings, with Assignment id.
                            $assignmentdates = array();
                            $assignmentdates['id'] = $sqldates[$idcode]->id;
                            // Convert external database times to Unix timestamp.
                            $assignmentdates['gradingduedate'] = strtotime($a['assessment_feedbackdate'].' '.$gradingduetime);
                            $assignmentdates['cutoffdate'] = strtotime($a['assessment_feedbackdate'].' '.$gradingduetime);
                            // Set times/dates.
                            $DB->update_record('assign', $assignmentdates, false);
                            echo $idcode . ' Feedback due date and CutOff date set.<br>';
                        }
                    }
                    // reset submission time to 3pm
                    $submissiontime = date('H:i:s', strtotime('3pm'));
                }
            }
            
            // Reset change flags.
            $sql = "UPDATE " . $tablegrades . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
            $extdb->Execute($sql);
            $sql = "UPDATE " . $tableassm . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
            $extdb->Execute($sql);
            
            // Free memory.
            $extdb->Close();
        }
    }