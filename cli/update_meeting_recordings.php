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
 * CLI script to manually update the meeting recordings.
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'meeting_id' => false,
    ),
    array(
        'h' => 'help'
    )
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "CLI script to manually update the recordings for all the missing recordings events or for a particular meeting_id .

            Options:
            -h, --help          Print out this help
            -m, --meeting_id     Zoom meeting ID
            
            Example:
            \$sudo -u www-data /usr/bin/php mod/zoom/cli/update_meeting_recordings.php --meeting_id=1234
    ";

    cli_error($help);
}

$trace = new text_progress_trace();
$meetings = [];

if (!empty($options['meeting_id'])) {
    $sql = "SELECT * FROM mdl_zoom as mz
                     JOIN mdl_event e on e.instance = mz.id
            WHERE e.modulename = 'zoom'
              AND mz.deleted_at IS NULL
              AND mz.meeting_id = ?
              AND e.endtime < UNIX_TIMESTAMP(NOW())";

    try {
        $meetings = $DB->get_records_sql($sql, array($options['meeting_id']));
    } catch (Exception $e) {
        $trace->output('Exception: ' . $e->getMessage(), 1);
    }

} else {
    $sql = "SELECT * FROM mdl_zoom as mz
              JOIN mdl_event e on e.instance = mz.id
            WHERE e.modulename = 'zoom'
              AND e.recording_created = 0
              AND mz.deleted_at IS NULL
              AND e.endtime < UNIX_TIMESTAMP(NOW())
            GROUP BY mz.meeting_id";
    try {
        $meetings = $DB->get_records_sql($sql);
    } catch (Exception $e) {
        $trace->output('Exception: ' . $e);
    }
}

if (empty($meetings)) {
    cli_error('No meetings found to update.');
}

$service = new \mod_zoom_webservice();

foreach ($meetings as $meeting) {
    $meeting_id  = $meeting->meeting_id;

    $trace->output(sprintf('Processing details of meeting_id: %d', $meeting_id));

    try {
        $completed_meetings = $service->get_past_meeting_instances($meeting_id, $meeting->webinar);
    } catch (Exception $e) {
        mtrace('Error while fetching past meetings for meeting id: '. $meeting_id);
        $trace->output('Exception: ' . $e);
        continue;
    }


    foreach ($completed_meetings->meetings as $event) {
        //Check if the recordings exists already
        if ($DB->get_record('zoom_recordings',
            array('meeting_id' => $meeting_id,
                'uuid' => $event->uuid))
        ) {
            continue;
        }

        try {
            $service->update_recording_settings($meeting_id, ['viewer_download' => false]);

            $recordings = $service->get_meeting_recording(formatUUID($event->uuid));

            if (!empty($recordings) && !empty($recordings->recording_files{0})) {
                //Get only the first recording file
                $rec = $recordings->recording_files{0};
                $record = new\stdClass();
                $record->meeting_id = $recordings->id;
                $record->uuid = $recordings->uuid;
                $record->play_url = $rec->play_url;
                $record->download_url = $rec->download_url;
                $record->start_time = $rec->recording_start;
                $record->end_time = $rec->recording_end;
                $record->status = $rec->status;
                $zoom_recordings = $DB->insert_record('zoom_recordings', $record);
                if (is_int($zoom_recordings)) {
                    $DB->update_record('event', (object)['id' => $meeting->id, 'recording_created' => 1]);
                    mtrace('Recordings updated for meeting id: '. $recordings->id. ' and uuid: '. $recordings->uuid);
                } else {
                    mtrace('Recordings could not be inserted for meeting id: '. $recordings->id. ' and uuid: '. $recordings->uuid);
                }
            } else {
                mtrace('No recordings found for the meeting_id: '. $meeting_id);
            }
        } catch (\moodle_exception $error) {
            mtrace('Recordings could not be updated: '. $error);
        }
    }
}


/**
 * @param $uuid
 * @return string
 */
function formatUUID($uuid)
{
    if (strpos($uuid, '/') !== false 
        || strpos($uuid, '//') !== false
    ) {
        return '"' . $uuid . '"';
    }

    return $uuid;
}