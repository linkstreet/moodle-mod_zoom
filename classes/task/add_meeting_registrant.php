<?php

namespace mod_zoom\task;


/**
 * Schedule task to add meeting registrant
 * 
 * @package mod_zoom
 */

class add_meeting_registrant extends \core\task\scheduled_task {
    /**
     * Return name of the task
     * 
     * @return boolean
     */
    public function get_name() {
        return get_string("add_meeting_registrant", "zoom");
    }

    /**
     * Approve registrants to join the meeting
     * 
     */

    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot.'/mod/zoom/locallib.php');
        require_once($CFG->dirroot.'/lib/modinfolib.php');
        require_once($CFG->dirroot.'/mod/zoom/lib.php');
        require_once($CFG->dirroot.'/mod/zoom/classes/webservice.php');
        
        
        $service = new \mod_zoom_webservice();
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

        foreach($meetingEnrolledUser as $data) {
            try {

                $response = $service->add_meeting_registrants($data->meeting_id, $data->firstname, $data->lastname, $data->email);
                if(!empty($response)) {

                    $queryToInsertRegistrant = "INSERT INTO `mdl_zoom_meeting_registrant` (meeting_id, email, first_name, last_name, registrant_id, start_time, topic, status, created_at)
                                                VALUES ($data->meeting_id, '$data->email', '$data->firstname', '$data->lastname', '$response->registrant_id', '$response->start_time', '$response->topic', 'PENDING', now())";
                    $DB->execute($queryToInsertRegistrant);
                }
                    $getRegistrantDetails = "SELECT registrant_id AS 'id', email FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $data->meeting_id";
                    $registrantDetails = $DB->get_records_sql($getRegistrantDetails);

                    $requestPayload = [];
                    $requestPayload["action"] = "approve";
                    $requestPayload["registrants"] = [];
                    $registrants = [];
                    foreach($registrantDetails as $rData) {
                            $temp = [
                                "id" => "$rData->id",
                                "email" => "$rData->email"
                            ];
                            array_push($registrants, $temp);
                    }
                   
            }catch(\moodle_exception $error) {
                var_dump($error);
            }
        }
        try {
            $requestPayload["registrants"] = $registrants;
            $requestPayload = json_encode($requestPayload);
            
            $updateMeetingRegistrantStatus = $service->update_registrants_status($requestPayload, $data->meeting_id);
                        
            if ($updateMeetingRegistrantStatus == 204) {
                $queryToGetPendingStatusMeeting = "SELECT DISTINCT(meeting_id) FROM `mdl_zoom_meeting_registrant` WHERE status = 'PENDING'";
                $meetingIdList = $DB->get_records_sql($queryToGetPendingStatusMeeting);

                foreach($meetingIdList as $meeting) {
                    $meetingRegistrantList = $service->get_meeting_registrants($meeting->meeting_id);
                    foreach($meetingRegistrantList->registrants as $data) {
                        if ($data->status == "approved") {
                            $join_url = urlencode($data->join_url);
                            $updateStatusAndJoinUrl = "UPDATE `mdl_zoom_meeting_registrant`
                                SET join_url = '$join_url', status = '$data->status'  
                                WHERE email = '$data->email' AND meeting_id = $meeting->meeting_id";
                            $DB->execute($updateStatusAndJoinUrl);
                        }
                    }
                }
                
            }
        } catch (\moodle_exception $error) {
            var_dump($error);
        }

    }
}