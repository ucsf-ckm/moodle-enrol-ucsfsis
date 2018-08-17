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
 * UCSF Student Information System enrolment plugin.
 *
 * @package    enrol_ucsfsis
 * @copyright  2016 The Regents of the University of California
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');

class enrol_ucsfsis_plugin extends enrol_plugin {
    /** @var object SIS client object */
    protected $_sisclient = null;

    /**
     * Returns localised name of enrol instance.
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        $enrol = $this->get_name();
        $iname = get_string('pluginname_short', 'enrol_'.$enrol);

        if (!empty($instance)) {
            // Append assigned role
            if (!empty($instance->roleid) and $role = $DB->get_record('role', array('id'=>$instance->roleid))) {
                $iname .= ' (' . role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING)) . ')';
            }
            // Append details of selected course (disable for now until we implement this in edit_form.php
            // if (!empty($instance->customtext1)) {
            //     $iname .= '<br /><small>'.$instance->customtext1.'</small><br />';
            // } else {
            //     $http = $this->get_http_client();
            //     if ($http->is_logged_in()) {
            //         $course = $http->get_course($instance->customint1);
            //         if (!empty($course)) {
            //             $deptid = trim($course->departmentForBelongTo);
            //             $courseNumber = trim($course->courseNumber);
            //             $courseName = trim($course->name);
            //             $term = trim($course->term);
            //             $text1 = "<strong>{$deptid}{$courseNumber} {$courseName}</strong>, {$term}";

            //             // Save customtext1
            //             $inst = new stdClass;
            //             $inst->id = $instance->id;
            //             $inst->customtext1 = $text1;
            //             $DB->update_record('enrol', $inst);

            //             $iname .= '<br /><small>'.$text1.'</small><br />';
            //         }
            //     }
            // }
        }
        return $iname;
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {

        if (!$this->can_add_new_instances($courseid)) {
            return NULL;
        }

        return new moodle_url('/enrol/ucsfsis/edit.php', array('courseid'=>$courseid));
    }

    /**
     * Given a courseid this function returns true if the user is able to enrol.
     *
     * @param int $courseid
     * @return bool
     */
    protected function can_add_new_instances($courseid) {
        global $DB;

        $coursecontext = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $coursecontext) or !has_capability('enrol/ucsfsis:config', $coursecontext)) {
            return false;
        }

        if ($DB->record_exists('enrol', array('courseid'=>$courseid, 'enrol'=>'ucsfsis'))) {
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/ucsfsis:config', $context);
    }

    /**
     * @inheritdoc
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/ucsfsis:config', $context);
    }

    /**
     * Returns enrolment instance manage link.
     *
     * By defaults looks for manage.php file and tests for manage capability.
     *
     * @param navigation_node $instancesnode
     * @param stdClass $instance
     * @return moodle_url;
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'ucsfsis') {
             throw new coding_exception('Invalid enrol instance type!');
        }

        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/ucsfsis:config', $context)) {
            $managelink = new moodle_url('/enrol/ucsfsis/edit.php', array('courseid'=>$instance->courseid));
            // We want to show the enrol plugin name here instead the instance's name; therefore the 'null' argument.
            $instancesnode->add($this->get_instance_name(null), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances.
     *
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'ucsfsis') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = array();

        if (has_capability('enrol/ucsfsis:config', $context)) {
            $editlink = new moodle_url("/enrol/ucsfsis/edit.php", array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                                                                    array('class' => 'iconsmall')));
        }

        return $icons;
    }

    /**
     * Execute synchronisation.
     * @param progress_trace
     * @param int $courseid one course, empty mean all
     * @return int exit code, 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function sync(progress_trace $trace, $courseid = null) {
        global $CFG, $DB;
        // TODO: Is there a way to break this cron into sections to run?
        if (!enrol_is_enabled('ucsfsis')) {
            $trace->output('UCSF SIS enrolment sync plugin is disabled, unassigning all plugin roles and stopping.');
            role_unassign_all(array('component'=>'enrol_ucsfsis'));
            return 2;
        }

        // Unfortunately this may take a long time, this script can be interrupted without problems.
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        $trace->output('Starting user enrolment synchronisation...');

        $allroles = get_all_roles();
        $http = $this->get_http_client();
        $unenrolaction = $this->get_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT *
                FROM {enrol} e
                WHERE e.enrol = 'ucsfsis' $onecourse";

        $params = array();
        $params['courseid'] = $courseid;
        // $params['suspended'] = ENROL_USER_SUSPENDED;
        $instances = $DB->get_recordset_sql($sql, $params);

        foreach ($instances as $instance) {
            $siscourseid = trim($instance->customint1);
            $context = context_course::instance($instance->courseid);

            $trace->output("Synchronizing course {$instance->courseid}...");

            $courseEnrolments = false;
            if ($http->is_logged_in()) {
                $courseEnrolments = $http->get_course_enrollment($siscourseid);
            }

            if ($courseEnrolments === false) {
                $trace->output("Unable to fetch data from SIS for course id: $siscourseid.", 1);
                continue;
            }

            if (empty($courseEnrolments)) {
                $trace->output("Skipping: No enrolment data from SIS for course id: $siscourseid.", 1);
                continue;
            }

            // TODO: Consider doing this in bulk instead of iterating each student.
            // NOTES: OK, so enrol_user() will put the user on mdl_user_enrolments table as well as mdl_role_assignments
            //        But update_user_enrol() will just update mdl_user_enrolments but not mdl_role_assignments
            $enrolleduserids = array();

            // foreach ($student_enrol_statuses as $ucid => $status) {
            foreach ($courseEnrolments as $ucid => $userEnrol) {
                $status = $userEnrol->status;
                $urec = $DB->get_record('user', array( 'idnumber' => $ucid ));
                if (!empty($urec)) {
                    $userid = $urec->id;
                    // looks like enrol_user does it all
                    $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $userid));
                    if (!empty($ue)) {
                        if ($status !== (int)$ue->status) {
                            $this->enrol_user($instance, $userid, $instance->roleid, 0, 0, $status);
                            $trace->output("changing enrollment status to '{$status}' from '{$ue->status}': userid $userid ==> courseid ".$instance->courseid, 1);
                        }//  else {
                        //     $trace->output("already enrolled do nothing: status = '{$ue->status}', userid $userid ==> courseid ".$instance->courseid, 1);
                        // }
                    } else {
                        $this->enrol_user($instance, $userid, $instance->roleid, 0, 0, $status);
                        $trace->output("enrolling with $status status: userid $userid ==> courseid ".$instance->courseid, 1);
                    }
                    $enrolleduserids[] = $userid;
                } else {
                    $trace->output("skipping: Cannot find UCID, ".$ucid.", that matches a Moodle user.");
                }
            }

            // Unenrol as neccessary.
            if (!empty($enrolleduserids)) {
                $sql = "SELECT ue.* FROM {user_enrolments} ue
                             WHERE ue.enrolid = $instance->id
                               AND ue.userid NOT IN ( ".implode(",", $enrolleduserids)." )";

                $rs = $DB->get_recordset_sql($sql);
                foreach($rs as $ue) {
                    if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                        // Remove enrolment together with group membership, grades, preferences, etc.
                        $this->unenrol_user($instance, $ue->userid);
                        $trace->output("unenrolling: $ue->userid ==> ".$instance->courseid, 1);
                    } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND or $unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                        // Suspend enrolments.
                        if ($ue->status != ENROL_USER_SUSPENDED) {
                            $this->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                            $trace->output("suspending: userid ".$ue->userid." ==> courseid ".$instance->courseid, 1);
                        }
                        if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                            $context = context_course::instance($instance->courseid);
                            role_unassign_all(array('userid'=>$ue->userid, 'contextid'=>$context->id, 'component'=>'enrol_ucsfsis', 'itemid'=>$instance->id));
                            $trace->output("unsassigning all roles: userid ".$ue->userid." ==> courseid ".$instance->courseid, 1);
                        }
                    }
                }
                $rs->close();
            }
        }

        // Now assign all necessary roles to enrolled users - skip suspended instances and users.
        // TODO: BUG: does not unassign the old role... :(
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT e.roleid, ue.userid, c.id AS contextid, e.id AS itemid, e.courseid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'ucsfsis' AND e.status = :statusenabled $onecourse)
              JOIN {role} r ON (r.id = e.roleid)
              JOIN {context} c ON (c.instanceid = e.courseid AND c.contextlevel = :coursecontext)
              JOIN {user} u ON (u.id = ue.userid AND u.deleted = 0)
         LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = ue.userid AND ra.itemid = e.id AND ra.component = 'enrol_ucsfsis' AND e.roleid = ra.roleid)
             WHERE ue.status = :useractive AND ra.id IS NULL";
        $params = array();
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['useractive'] = ENROL_USER_ACTIVE;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_ucsfsis', $ra->itemid);
            $trace->output("assigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname, 1);
        }
        $rs->close();


        // Remove unwanted roles - sync role can not be changed, we only remove role when unenrolled.
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid, e.courseid
              FROM {role_assignments} ra
              JOIN {context} c ON (c.id = ra.contextid AND c.contextlevel = :coursecontext)
              JOIN {enrol} e ON (e.id = ra.itemid AND e.enrol = 'ucsfsis' $onecourse)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :useractive)
             WHERE ra.component = 'enrol_ucsfsis' AND (ue.id IS NULL OR e.status <> :statusenabled OR e.roleid <> ra.roleid)";
        $params = array();
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['useractive'] = ENROL_USER_ACTIVE;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_ucsfsis', $ra->itemid);
            $trace->output("unassigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname, 1);
        }
        $rs->close();

        $trace->output("Completed synchronizing course {$instance->courseid}.");

        return 0;
    }

    /**
     * Update instance status
     *
     * Override when plugin needs to do some action when enabled or disabled.
     *
     * @param stdClass $instance
     * @param int $newstatus ENROL_INSTANCE_ENABLED, ENROL_INSTANCE_DISABLED
     * @return void
     */
    public function update_status($instance, $newstatus) {
        global $CFG;

        parent::update_status($instance, $newstatus);

        $trace = new null_progress_trace();
        $this->sync($trace);
        $trace->finished();
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol_user($instance, $ue) && has_capability('enrol/ucsfsis:unenrol', $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        // There is only 1 SIS enrol instance per course.
        if ($instances = $DB->get_records('enrol', array('courseid'=>$data->courseid, 'enrol'=>'ucsfsis'), 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            $instanceid = $this->add_instance($course, (array)$data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);

        $trace = new null_progress_trace();
        $this->sync($trace, $course->id);
        $trace->finished();
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        global $DB;

        if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_UNENROL) {
            // Enrolments were already synchronised in restore_instance(), we do not want any suspended leftovers.

        } else if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_KEEP) {
            if (!$DB->record_exists('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid))) {
                $this->enrol_user($instance, $userid, null, 0, 0, $data->status);
            }

        } else {
            if (!$DB->record_exists('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid))) {
                $this->enrol_user($instance, $userid, null, 0, 0, ENROL_USER_SUSPENDED);
            }
        }
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid) {
        global $DB;

        if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_UNENROL or $this->get_config('unenrolaction') == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            // Skip any roles restore, they should be already synced automatically.
            return;
        }

        // Just restore every role.
        if ($DB->record_exists('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid))) {
            role_assign($roleid, $userid, $contextid, 'enrol_'.$instance->enrol, $instance->id);
        }
    }


    public function get_http_client() {
        if (empty($this->_sisclient)) {
            $this->_sisclient = new ucsfsis_oauth_client(
                $this->get_config('clientid'),
                $this->get_config('secret'),
                $this->get_config('resourceid'),
                $this->get_config('resourcepassword'),
                $this->get_config('host_url'),
                true
            );
        }
        return $this->_sisclient;
    }

    /**
     * Put last 6 terms of subjects data in cache data here. Will be called by cron.
     */
    public function prefetch_subjects_data_to_cache() {
        $prefetch_term_num = 5;
        $oauth = $this->get_http_client();

        if ($oauth->is_logged_in()) {
            // Terms data
            $terms = $oauth->get_active_terms();
            if (!empty($terms)) {
                foreach ($terms as $key => $term) {
                    if ($key > $prefetch_term_num) {
                        break;
                    }
                    $result = $oauth->get_subjects_in_term($term->id);
                }
            }
        }
    }

    /**
     * Put last 6 terms of courses data in cache data here. Will be called by cron.
     */
    public function prefetch_courses_data_to_cache() {
        $prefetch_term_num = 5;
        $oauth = $this->get_http_client();

        if ($oauth->is_logged_in()) {
            // Terms data
            $terms = $oauth->get_active_terms();
            if (!empty($terms)) {
                foreach ($terms as $key => $term) {
                    if ($key > $prefetch_term_num) {
                        break;
                    }
                    $result = $oauth->get_courses_in_term($term->id);
                }
            }
        }
    }

    /**
     * Test plugin settings, print info to output.
     */
    public function test_settings() {
        global $CFG, $OUTPUT;
        // NOTE: this is not localised intentionally, admins are supposed to understand English at least a bit...

        raise_memory_limit(MEMORY_HUGE);

        $this->load_config();

        $hosturl = $this->get_config('host_url');
        if (empty($hosturl)) {
            echo $OUTPUT->notification('Host URL not specified, use default.','notifysuccess');
        }

        $resourceid = $this->get_config('resourceid');
        $resourcepw = $this->get_config('resourcepassword');

        if (empty($resourceid)) {
            echo $OUTPUT->notification('Resource ID not specified.', 'notifyproblem');
        }

        if (empty($resourcepw)) {
            echo $OUTPUT->notification('Resource password not specified.', 'notifyproblem');
        }

        if (empty($resourceid) and empty($resourcepw)) {
            return;
        }

        $clientid = $this->get_config('clientid');
        $secret = $this->get_config('secret');

        if (empty($clientid)) {
            echo $OUTPUT->notification('Client ID not specified.', 'notifyproblem');
        }

        if (empty($secret)) {
            echo $OUTPUT->notification('Client secret not specified.', 'notifyproblem');
        }

        if (empty($clientid) and empty($secret)) {
            return;
        }

        // Set up for debugging
        $olddebug = $CFG->debug;
        $olddisplay = ini_get('display_errors');
        ini_set('display_errors', '1');
        $CFG->debug = DEBUG_DEVELOPER;
        error_reporting($CFG->debug);

        // Testing
        $oauth = new ucsfsis_oauth_client($clientid, $secret, $resourceid, $resourcepw, $hosturl, true);

        $loggedin = $oauth->is_logged_in();
        if ($loggedin) {
            // Log out and try log in again.
            $oauth->log_out();
            $loggedin = $oauth->is_logged_in();
        }

        if (!$loggedin) {
            echo $OUTPUT->notification('Failed to log in with the specified settings', 'notifyproblem');
            return;
        }

        $atoken = $oauth->get_accesstoken();
        if (empty($atoken)) {
            echo $OUTPUT->notification('Failed to obtain an access token.', 'notifyproblem');
            return;
        }

        $rtoken = $oauth->get_refreshtoken();
        if (empty($rtoken)) {
            echo $OUTPUT->notification('Failed to obtain a refresh token.', 'notifyproblem');
            return;
        }

        echo $OUTPUT->notification('Logged in successfully to obtain an access token.', 'notifysuccess');

        // Get some data...
        echo "<pre>";
        if (empty($hosturl)) {
            $hosturl = ucsfsis_oauth_client::DEFAULT_HOST;
        }

        $result = $oauth->get_all_data($hosturl.'/general/sis/1.0/schools');
        echo "School Data: <br />";
        var_dump($result);

        // $result = $oauth->get_all_data($hosturl.'/general/sis/1.0/terms');
        // $result = $oauth->get_all_data($hosturl.'/general/sis/1.0/terms?fields=id,name,fileDateForEnrollment&sort=-termStartDate');
        $terms = $oauth->get_active_terms();
        echo "Active Term Data: <br />";
        var_dump($terms);

        $result = $oauth->get_all_data($hosturl.'/general/sis/1.0/departments');
        echo "Department Data: <br />";
        var_dump($result);

        // Actual requests used in code (and cache them).
        if (!empty($terms)) {
            foreach ($terms as $key => $term) {
                if ($key > 4) {
                    break;
                }
                $result = $oauth->get_subjects_in_term($term->id);
                echo "Subject Data in Term " . $term->name . " (" . count($result) ."): <br />";
                var_dump($result);
            }
        }

        if (!empty($terms)) {
            foreach ($terms as $key => $term) {
                if ($key > 4) {
                    break;
                }
                $result = $oauth->get_courses_in_term($term->id);
                echo "Course Data in Term " . $term->name . " (" . count($result) ."): <br />";
                var_dump($result);
            }
        }

        echo "</pre>";

        $CFG->debug = $olddebug;
        ini_set('display_errors', $olddisplay);
        error_reporting($CFG->debug);
    }

    private function test_apiurl($url, $api) {
        global $CFG, $OUTPUT;
        echo $OUTPUT->notification("Testing '$url'", 'notifyproblem');
        // check for consistency for arrays
        $obj = $api->getobj($url);

        if (is_array($obj)) {
            $total1 = count($obj);
            $obj = $api->getobj($url);
            $total2 = count($obj);
            if ($total1 != $total2) {
                echo $OUTPUT->notification("ERROR: $url, 1st call total: $total1; 2nd call total: $total2", 'notifyproblem');
            }
        }

        echo $OUTPUT->notification("$url:<pre>".print_r($obj,true).'</pre>', 'notifyproblem');
        return $obj;
    }

    private function test_apiurlinvalid($url, $api) {
        global $CFG, $OUTPUT;
        echo $OUTPUT->notification("Testing '$url'", 'notifyproblem');
        $obj = $api->get($url);
        echo $OUTPUT->notification("$url:<pre>".print_r($obj,true).'</pre>', 'notifyproblem');
        return $obj;
    }

    private function test_get_objectsshouldreturnssomething($url, $api) {
        global $OUTPUT;

        echo $OUTPUT->notification("Testing get_objects($url)");
        $objs = $api->get_objects($url);
        if (empty($objs)) {
            echo $OUTPUT->notification("ERROR: get_objects($url) returns empty.", 'notifyproblem');
        } else {
            echo $OUTPUT->notification("get_objects($url) returns: <pre>".print_r($objs, true).'</pre>');
        }

        return $objs;
    }

    private function test_usinglimitandoffsetreturnsamelist($url, $api) {
        global $CFG, $OUTPUT;

        $array_to_assoc = function($arr) {
            $output = array();
            foreach ($arr as $key=>$value) {
                $output[$value['id']] = $value['id'];
            }
            return $output;
        };

        $array_diff_recursive = function($arr1, $arr2) use (&$array_diff_recursive) {
            $outputDiff = [];
            foreach ($arr1 as $key => $value)
            {
                if (array_key_exists($key, $arr2))
                {
                    if (is_array($value))
                    {
                        $recursiveDiff = $array_diff_recursive($value, $arr2[$key]);
                        if (count($recursiveDiff))
                        {
                            $outputDiff[$key] = $recursiveDiff;
                        }
                    }
                    else if (!in_array($value, $arr2))
                    {
                        $outputDiff[$key] = $value;
                    }
                }
                else if (!in_array($value, $arr2))
                {
                    $outputDiff[$key] = $value;
                }
            }
            return $outputDiff;
        };

        $ret1 = $api->get($url);
        $ret2 = $api->get($url.'?limit=100');

        if (strcmp($ret1, $ret2) !== 0) {
            echo $OUTPUT->notification("$url failed '?limit=100' tests", 'notifyproblem');

            $arr1 = $api->parse_result($ret1,true);
            $arr2 = $api->parse_result($ret2,true);
            echo $OUTPUT->notification("Total number of records returned:<pre>".count($arr1['data'])." vs. ".count($arr2['data'])."</pre>");
            // echo $OUTPUT->notification("$url:<pre>".print_r($arr1, true)."</pre>");
            // echo $OUTPUT->notification("$url?limit=100:<pre>".print_r($arr2, true)."</pre>");

            // $diff = $array_diff_recursive($arr1['data'],$arr2['data']);

            $arr1 = $array_to_assoc($arr1['data']);
            $arr2 = $array_to_assoc($arr2['data']);
            $diff = $array_diff_recursive($arr1,$arr2);
            echo $OUTPUT->notification("Not in first list:<pre>".print_r(implode($diff,',') ,true)."</pre>");
            $diff = $array_diff_recursive($arr2,$arr1);
            echo $OUTPUT->notification("Not in second list:<pre>".print_r(implode($diff,',') ,true)."</pre>");

            // echo $OUTPUT->notification("$url:<pre>".$ret1."</pre>");
            // echo $OUTPUT->notification("$url?limit=100:<pre>".$ret2."</pre>");
        }

        $ret3 = $api->get($url.'?limit=100&offset=1');

        if (strcmp($ret1, $ret3) !== 0) {
            echo $OUTPUT->notification("$url failed '?limit=100&offset=1' tests", 'notifyproblem');

            $arr1 = $api->parse_result($ret1,true);
            $arr3 = $api->parse_result($ret3,true);
            echo $OUTPUT->notification("Total number of records returned:<pre>".count($arr1['data'])." vs. ".count($arr3['data'])."</pre>");
            // $diff = $array_diff_recursive($arr1['data'], $arr3['data']);

            $arr1 = $array_to_assoc($arr1['data']);
            $arr3 = $array_to_assoc($arr3['data']);
            $diff = $array_diff_recursive($arr1,$arr3);
            echo $OUTPUT->notification("Not in first list:<pre>".print_r(implode($diff,','),true)."</pre>");
            $diff = $array_diff_recursive($arr3,$arr1);
            echo $OUTPUT->notification("Not in second list:<pre>".print_r(implode($diff,','),true)."</pre>");

            // echo $OUTPUT->notification("$url:<pre>".$ret1."</pre>");
            // echo $OUTPUT->notification("$url?limit=100:<pre>".$ret3."</pre>");
        }
    }

}

/**
 * OAuth 2.0 client for UCSF SIS Enrolment Services
 *
 * @package   enrol_ucsfsis
 */
class ucsfsis_oauth_client extends oauth2_client {
    /** @const API URL */
    const DEFAULT_HOST = 'https://unified-api.ucsf.edu';
    const API_URL = '/general/sis/1.0';
    const TOKEN_URL = '/oauth/1.0/access_token';
    const AUTH_URL = '/oauth/1.0/authorize';

    /** @var string resource username */
    private $base_url = self::DEFAULT_HOST;
    /** @var string resource username */
    private $username = '';
    /** @var string resource password */
    private $password = '';
    /** @var string refresh token */
    protected $refreshtoken = '';
    /** @var bool Caches http request contents that do not change often like schools, terms, departments, subjects...etc */
    public  $longer_cache = false;    // Cache for 24 hours.

    /**
     * Returns the auth url for OAuth 2.0 request
     * @return string the auth url
     */
    protected function auth_url() {
        return $this->base_url . self::AUTH_URL;
    }

    /**
     * Returns the token url for OAuth 2.0 request
     * @return string the auth url
     */
    protected function token_url() {
        return $this->base_url . self::TOKEN_URL;
    }

    /**
     * Returns the url for resource API request
     * @return string the resource API url
     */
    public function api_url() {
        return $this->base_url . self::API_URL;
    }

    /**
     * Constructor.
     *
     * @param string $clientid
     * @param string $clientsecret
     * @param moodle_url $returnurl
     * @param string $scope
     */
    public function __construct($clientid, $clientsecret, $username, $password, $host = null, $enablecache = true) {

        // Don't care what the returnurl is right now until we start implementing callbacks
        $returnurl = new moodle_url(null);
        $scope = '';

        parent::__construct($clientid, $clientsecret, $returnurl, $scope);

        $this->refreshtoken = $this->get_stored_refresh_token();
        $this->username = $username;
        $this->password = $password;

        // We need these in the header all time.
        $this->setHeader('client_id: '.$clientid);
        $this->setHeader('client_secret: '.$clientsecret);

        if (!empty($host)) {
            $this->base_url = $host;
        }

        if ($enablecache) {
            $this->cache = new sis_client_cache('enrol_ucsfsis');
            $this->longer_cache = new sis_client_cache('enrol_ucsfsis/daily', 24 * 60 * 60);
        }

    }

    /**
     * Override this to login automatically.  Once SIS/Mutesoft has a callback implemented,
     * we can remove this override.
     *
     * @return boolean true if logged in
     */
    public function is_logged_in() {
        // Has the token expired?
        $accesstoken = $this->get_accesstoken();
        if (isset($accesstoken->expires) && time() >= $accesstoken->expires) {

            // Try to obtain a new access token with a refresh token.
            if (!empty($this->refreshtoken)) {
                if ($this->refresh_token($this->refreshtoken)) {
                    return true;
                }
            }
            // Clear accesstoken since it already expired.
            $this->log_out();
        }

        // We have a token so we are logged in.
        if (!empty($this->get_accesstoken())) {
            return true;
        }

        // If we've been passed then authorization code generated by the
        // authorization server try and upgrade the token to an access token.
        $code = optional_param('oauth2code', null, PARAM_RAW);
        if ($code && $this->upgrade_token($code)) {
            return true;
        }

        // Try log in using username and password to obtain access token
        if (!empty($this->username) && !empty($this->password)) {
            if ($this->log_in($this->username, $this->password)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make a HTTP request, adding the access token we have
     *
     * @param string $url The URL to request
     * @param array $options
     * @param mixed $acceptheader mimetype (as string) or false to skip sending an accept header.
     * @return bool
     */
    protected function request($url, $options = array(), $acceptheader = 'application/json') {

        // We need these in the header all time.
        $this->setHeader('client_id: '.$this->get_clientid());
        $this->setHeader('client_secret: '.$this->get_clientsecret());

        $response = parent::request($url, $options, $acceptheader);

        return $response;
    }

    /**
     * Store access token between requests.
     *
     * @param stdClass|null $token token object to store or null to clear
     */
    protected function store_token($token) {
        global $CFG, $SESSION;

        require_once($CFG->libdir.'/moodlelib.php');

        // $this->accesstoken is private, need to call parent to set it.
        parent::store_token($token);

        if ($token !== null) {
            if (isset($token->token)) {
                set_config('accesstoken', $token->token, 'enrol_ucsfsis');
                set_config('accesstokenexpiretime', $token->expires, 'enrol_ucsfsis');
            }
            // Remove it from $SESSION, which was set by parent
            $name = $this->get_tokenname();
            unset($SESSION->{$name});
        } else {
            set_config('accesstoken', null, 'enrol_ucsfsis');
            set_config('accesstokenexpiretime', null, 'enrol_ucsfsis');
        }
    }

    /**
     * Store access token between requests.
     *
     * @param stdClass|null $token token object to store or null to clear
     */
    protected function store_refresh_token($token) {
        global $CFG;

        require_once($CFG->libdir.'/moodlelib.php');

        $this->refreshtoken = $token;

        if (!empty($token)) {
            set_config('refreshtoken', $token, 'enrol_ucsfsis');
        } else {
            set_config('refreshtoken', null, 'enrol_ucsfsis');
        }
    }

    /**
     * Retrieve a token stored.
     *
     * @return stdClass|null token object
     */
    protected function get_stored_token() {
        global $CFG;

        require_once($CFG->libdir.'/moodlelib.php');

        $accesstoken = new stdClass;
        $accesstoken->token = get_config('enrol_ucsfsis', 'accesstoken');
        $accesstoken->expires = get_config('enrol_ucsfsis', 'accesstokenexpiretime');

        if (!empty($accesstoken->token)) {
            return $accesstoken;
        }

        return null;
    }

    /**
     * Retrieve a refresh token stored.
     *
     * @return string|null token string
     */
    protected function get_stored_refresh_token() {
        global $CFG;

        require_once($CFG->libdir.'/moodlelib.php');

        $refreshtoken = get_config('enrol_ucsfsis', 'refreshtoken');

        if (!empty($refreshtoken)) {
            return $refreshtoken;
        }

        return null;
    }

    /**
     * Get refresh token.
     *
     * This is just a getter to read the private property.
     *
     * @return string
     */
    public function get_refreshtoken() {
        return $this->refreshtoken;
    }

    /**
     * Override this to use HTTP GET instead of POST.
     * unified-api.ucsf.edu only works with GET for now.
     * Not everything works with POST yet.
     *
     * @return bool true if GET should be used
     */
    protected function use_http_get() {
        return true;
    }

    /**
     * Refresh the access token from a refresh token
     *
     * @param string $token the token used to refresh the access token
     * @return boolean true if token is refreshed successfully
     */
    protected function refresh_token($code) {

        $params = array('client_id' => $this->get_clientid(),
                        'client_secret' => $this->get_clientsecret(),
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $code,
        );

        // Requests can either use http GET or POST.
        if ($this->use_http_get()) {
            $response = $this->get($this->token_url(), $params);
        } else {
            $response = $this->post($this->token_url(), $params);
        }

        if (!$this->info['http_code'] === 200) {
            throw new moodle_exception('Could not refresh access token.');
        }

        $r = json_decode($response);

        if (!isset($r->access_token)) {
            return false;
        }

        // Store the token an expiry time.
        $accesstoken = new stdClass;
        $accesstoken->token = $r->access_token;
        $accesstoken->expires = (time() + ($r->expires_in - 10)); // Expires 10 seconds before actual expiry.
        $this->store_token($accesstoken);

        // Store the refresh token.
        if (isset($r->refresh_token)) {
            $this->store_refresh_token($r->refresh_token);
        }

        // Clear cache every time we get a new token
        if (isset($this->cache)) {
            $this->cache->refresh();
        }
        if (isset($this->longer_cache)) {
            $this->longer_cache->refresh();
        }

        return true;
    }

    /**
     * Upgrade a authorization token from oauth 2.0 to an access token
     *
     * @param  string $code the code returned from the oauth authenticaiton
     * @return boolean true if token is upgraded succesfully
     */
    public function log_in($username, $password) {

        $params = array('client_id' => $this->get_clientid(),
                        'client_secret' => $this->get_clientsecret(),
                        'grant_type' => 'password',
                        'username' => $username,
                        'password' => $password);

        // Requests can either use http GET or POST.
        // unified-api only works with GET for now.
        if ($this->use_http_get()) {
            $response = $this->get($this->token_url(), $params);
        } else {
            $response = $this->post($this->token_url(), $params);
        }

        if (!$this->info['http_code'] === 200) {
            throw new moodle_exception('Could not upgrade oauth token');
        }

        $r = json_decode($response);

        if (!isset($r->access_token)) {
            return false;
        }

        // Store the token an expiry time.
        $accesstoken = new stdClass;
        $accesstoken->token = $r->access_token;
        $accesstoken->expires = (time() + ($r->expires_in - 10)); // Expires 10 seconds before actual expiry.
        $this->store_token($accesstoken);

        // Store the refresh token.
        if (isset($r->refresh_token)) {
            $this->store_refresh_token($r->refresh_token);
        }

        // Clear cache every time we log in and get a new token
        if (isset($this->cache)) {
            $this->cache->refresh();
        }

        return true;
    }

    /**
     * Retrieve the data object from the return result from the URI.
     * Anything other than data will return false.
     *
     * @param  string URI to the resources
     * @return array  an array objects in data retrieved from the URI, or false when there's an error.
     */
    protected function get_data($uri) {
        $result = $this->get($uri);

        if (empty($result)) {
            return false;
        }

        $result = json_decode($result);

        if (isset($result->data)) {
            return $result->data;
        }

        return false;
    }

    /**
     * Make multiple calls to the URI until a complete set of  data are retrieved from the URI.
     * Return false when there's an error.
     *
     * @param  string URI to the resources
     * @return array  an array objects in data retrieved from the URI, or false when there's an error.
     */
    public function get_all_data($uri) {
        $limit    = 100;
        $offset   = 0;
        $data     = null;
        $expected_list_size = null;
        $ret_data = false;


        $query_prefix = strstr($uri,'?') ? '&' : '?';

        do {
            $modified_uri = $uri . $query_prefix . "limit=$limit&offset=$offset";

            $result = $this->get($modified_uri);
            $response = $result;   // save response for debugging

            if (empty($result)) {
                error_log("API call '$modified_uri' returned empty.");
                return false;
            }

            $result = json_decode($result);
            if (isset($result->error)) {
                preg_match('/(Offset \[\d+\] is larger than list size: )([0-9]+)/', $result->error, $errors);
                if (!empty($errors) && isset($errors[2])) {
                    // end of list has reached.
                    $data = null;
                    $expected_list_size = $errors[2];
                } else {
                    // return false on any other error
                    error_log("API call '$modified_uri' returned error: {$result->error}");
                    return false;
                }
            } else if (isset($result->data)) {
                $data = $result->data;

                if (!empty($data)) {
                    if (empty($ret_data)) {
                        $ret_data = array();
                    }
                    $ret_data = array_merge($ret_data, $data);
                    $offset += $limit;
                }
            } else {
                // something went wrong, no data, no error.
                error_log("API call '$modified_uri' returned unexpected response: {$response}");
                return false;
            }

        } while (!empty($data));

        // double check list size (if available).
        if (!empty($expected_list_size)) {
            if ($expected_list_size == count($ret_data)) {
                return $ret_data;
            } else {
                error_log("API call '$modified_uri' did not return same number of items as it claims which is $expected_list_size, actual is ".count($ret_data).".");
                return false;
            }
        }

        return $ret_data;
    }

    /**
     * Get active school terms data in reverse chronological order
     * Cache for 24 hours, don't expect this to change very often
     *
     * @return array Array of term objects.
     */
    public function get_active_terms() {
        // Save short term cache
        $cache = $this->cache;
        if (isset($this->cache)) {
            $this->cache = $this->longer_cache;
        }

        // $uri = $this->api_url() . '/terms?fields=id,name,fileDateForEnrollment&sort=-termStartDate';
        $uri = $this->api_url() . '/terms?sort=-termStartDate';
        $terms = $this->get_all_data($uri);

        // restore short term cache
        if (isset($cache)) {
            $this->cache = $cache;
        }

        // Remove terms that have fileDateForEnrollment = NULL.
        if (!empty($terms)) {
            foreach ($terms as $term) {
                if (!empty($term->fileDateForEnrollment)) {
                    $ret[] = $term;
                }
            }
            return  $ret;
        }
        return false;
    }

    /**
     * Get all available subjects in a term ordered by name
     * Cache for 24 hours, don't expect this to change very often
     *
     * @param  string Term ID
     * @return array  Array of subject objects.
     */
    public function get_subjects_in_term($term_id) {
        if (isset($this->cache)) {
            // Save short term cache
            $cache = $this->cache;
            $this->cache = $this->longer_cache;
        }

        $termid = trim($term_id);
        $uri = $this->api_url() . "/terms/$termid/subjects?sort=name";
        $ret = $this->get_all_data($uri);

        // restore short term cache
        if (isset($cache)) {
            $this->cache = $cache;
        }

        return  $ret;
    }

    /**
     * Get information on a single course by course id
     *
     * @param  string Course ID
     * @return object Course object.
     */
    public function get_course($course_id) {
        $courseid = trim($course_id);
        $uri = $this->api_url() . "/courses/$courseid";
        $ret = $this->get_data($uri);

        return  $ret;
    }

    /**
     * Get all available courses in a term ordered by courseNumber
     * Cache for 24 hours, don't expect this to change very often
     *
     * @param  string Term ID
     * @return array  Array of course objects.
     */
    public function get_courses_in_term($term_id) {
        // Save short term cache
        $cache = $this->cache;
        if (isset($this->cache)) {
            $this->cache = $this->longer_cache;
        }

        $termid = trim($term_id);
        $uri = $this->api_url() . "/terms/$termid/courses?sort=courseNumber";
        $ret = $this->get_all_data($uri);

        // restore short term cache
        if (isset($cache)) {
            $this->cache = $cache;
        }

        return  $ret;
    }

    /**
     * Get enrolment list from a course id
     *
     * @param  int   Course ID
     * @return array An array of enrollment object or false if error.
     */
    public function get_course_enrollment($course_id) {

        // Never cache the enrollment data
        $cache = $this->cache;
        $this->cache = null;


        $courseid = trim($course_id);
        $uri = $this->api_url() . "/courseEnrollments?courseId=$courseid";
        $enrollment = $this->get_all_data($uri);

        // restore the cache object.
        $this->cache = $cache;

        if (empty($enrollment)) {
            return $enrollment;
        }

        // Flatten enrollment objects (Simplify SIS return data to only what we need.)
        $enrol_list = array();

        foreach ($enrollment as $e) {
            if (!empty($e->student) && !empty($e->student->empno)) {
                $obj       = new stdClass();
                $obj->ucid = $e->student->empno;

                switch ($e->status) {
                case "A":
                    $obj->status = ENROL_USER_ACTIVE;
                    $enrol_list[trim($e->student->empno)] = $obj;
                    break;
                case "I":
                    if (!isset($enrol_list[trim($e->student->empno)])) {
                        $obj->status = ENROL_USER_SUSPENDED;
                        $enrol_list[trim($e->student->empno)] = $obj;
                    }
                    break;
                case "S":
                case "F":
                default:
                    // do nothing
                }
            }
        }

        if (!empty($enrol_list)) {
            return $enrol_list;
        } else {
            return false;
        }
    }
}


/**
 * This class is inherited from curl_cache class for caching, use case:
 *
 * @package    enrol_ucsfsis
 * @copyright  2016 The Regents of the University of California
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sis_client_cache extends curl_cache {
    /**
     * Constructor
     *
     * @global stdClass $CFG
     * @param string $module which module is using curl_cache
     * @param int $ttl time to live default to 20 mins (1200 sec)
     */
    public function __construct($module = 'enrol_ucsfsis', $ttl = 1200) {
        parent::__construct($module);
        $this->ttl = $ttl;
    }

    /**
     * Get cached value
     *
     * @global stdClass $CFG
     * @param mixed $param
     * @return bool|string
     */
    public function get($param) {
        global $CFG;
        $this->cleanup($this->ttl);

        // Sort param so that filename can be consistent.
        ksort($param);

        $filename = 'u'.'_'.md5(serialize($param));
        if(file_exists($this->dir.$filename)) {
            $lasttime = filemtime($this->dir.$filename);
            if (time()-$lasttime > $this->ttl) {
                return false;
            } else {
                $fp = fopen($this->dir.$filename, 'r');
                $size = filesize($this->dir.$filename);
                $content = fread($fp, $size);
                $result = unserialize($content);
                return $result;
            }
        }
        return false;
    }

    /**
     * Set cache value
     *
     * @global object $CFG
     * @param mixed $param
     * @param mixed $val
     */
    public function set($param, $val) {
        global $CFG;

        // Cache only valid data
        if (!empty($val)) {
            $obj = json_decode($val);
            if (!empty($obj) && isset($obj->data) && !empty($obj->data)) {
                // Sort param so that filename can be consistent.
                ksort($param);

                $filename = 'u'.'_'.md5(serialize($param));
                $fp = fopen($this->dir.$filename, 'w');
                fwrite($fp, serialize($val));
                fclose($fp);
                @chmod($this->dir.$filename, $CFG->filepermissions);
            }
        }
    }

    /**
     * delete current user's cache file
     *
     * @global object $CFG
     */
    public function refresh() {
        global $CFG;
        if ($dir = opendir($this->dir)) {
            while (false !== ($file = readdir($dir))) {
                if (!is_dir($file) && $file != '.' && $file != '..') {
                    if (strpos($file, 'u'.'_') !== false) {
                        @unlink($this->dir.$file);
                    }
                }
            }
        }
    }
}
