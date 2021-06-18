<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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
 * Load zoom meeting and assign grade to the user join the meeting.
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(dirname(__FILE__).'/locallib.php');

// Course_module ID.
$id = required_param('id', PARAM_INT);
if ($id) {
    $cm         = get_coursemodule_from_id('zoom', $id, 0, false, MUST_EXIST);
    $course     = get_course($cm->course);
    $zoom  = $DB->get_record('zoom', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    print_error('You must specify a course_module ID');
}
$userishost = (zoom_get_user_id(false) == $zoom->host_id);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/zoom:view', $context);

if ($userishost) {
    $nexturl = new moodle_url($zoom->start_url);
} else {
    // Check whether user had a grade. If no, then assign full credits to him or her.
    $gradelist = grade_get_grades($course->id, 'mod', 'zoom', $cm->instance, $USER->id);

    // Assign full credits for user who has no grade yet, if this meeting is gradable (i.e. the grade type is not "None").
    if (!empty($gradelist->items) && empty($gradelist->items[0]->grades[$USER->id]->grade)) {
        $grademax = $gradelist->items[0]->grademax;
        $grades = array('rawgrade' => $grademax,
                        'userid' => $USER->id,
                        'usermodified' => $USER->id,
                        'dategraded' => '',
                        'feedbackformat' => '',
                        'feedback' => '');

        zoom_grade_item_update($zoom, $grades);
    }
    $queryToFindJoinUrl = "SELECT join_url FROM `mdl_zoom_meeting_registrant` 
    WHERE email = (SELECT email FROM `mdl_user` WHERE id = $USER->id)
    AND meeting_id = $zoom->meeting_id";
    $userJoinUrl = $DB->get_record_sql($queryToFindJoinUrl);

    if(!empty($userJoinUrl)) {
        var_dump("entered not empty if");
        $joinUrl = urldecode($userJoinUrl->join_url);
        $nexturl = new moodle_url($joinUrl);
    } else {
        $queryToGetMeetingAndStudentDetails = "SELECT u.id, c.id AS 'program_id', mz.meeting_id, u.firstname, u.lastname, u.email
        FROM mdl_user u
                 JOIN mdl_user_enrolments ue ON ue.userid = u.id
                 JOIN mdl_enrol e ON e.id = ue.enrolid
                 JOIN mdl_role_assignments ra ON ra.userid = u.id
                 JOIN mdl_context ct ON ct.id = ra.contextid AND ct.contextlevel = 50
                 JOIN mdl_course c ON c.id = ct.instanceid 
                 JOIN mdl_role r ON r.id = ra.roleid AND r.shortname = 'student'
                 JOIN mdl_zoom mz ON mz.program_id = c.id AND mz.registration_type = 2
                AND mz.meeting_id NOT IN (SELECT meeting_id FROM mdl_zoom_meeting_registrant)
        WHERE e.status = 0 AND u.suspended = 0 AND u.deleted = 0
        AND (ue.timeend = 0 OR ue.timeend > UNIX_TIMESTAMP(NOW())) AND ue.status = 0";
        $meetingEnrolledUser = $DB->get_records_sql($queryToGetMeetingAndStudentDetails);
    }
    
}

// Record user's clicking join.
\mod_zoom\event\join_meeting_button_clicked::create(array('context' => $context, 'objectid' => $zoom->id, 'other' =>
        array('cmid' => $id, 'meetingid' => (int) $zoom->meeting_id, 'userishost' => $userishost)))->trigger();
redirect($nexturl);
