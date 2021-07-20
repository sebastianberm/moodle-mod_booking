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
namespace mod_booking\task;
use mod_booking\booking;
use mod_booking\booking_elective;
use mod_booking\booking_option;

require_once($CFG->dirroot . '/mod/booking/lib.php');

defined('MOODLE_INTERNAL') || die();

class enrol_bookedusers_tocourse extends \core\task\scheduled_task {

    /**
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    /**
     * Enrol users if course has started and this function has not yet been executed.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {
        global $DB;
        // Get all booking options with associated Moodle courses that have enrolmentstatus 0 and coursestartdate in the past.
        $select = "enrolmentstatus < 1 AND coursestarttime < :now";
        $now = time();
        $boids = $DB->get_records_select_menu('booking_options', $select, ['now' => $now], '', 'id, bookingid');
        foreach ($boids as $optionid => $bookingid) {
            if ($bookingid) {
                $cm = get_coursemodule_from_instance('booking', $bookingid);
            } else {
                mtrace("WARNING: Failed to get booking instance from option id: $optionid");
            }
            $boption = new booking_option($cm->id, $optionid);

            // TODO: Make sure we don't enrol users how have not yet finished previous course.

            $booking = $boption->booking;
            $iselective = $booking->settings->iselective;
            $enforceorder = $booking->settings->enforceorder;


            // Get all booked users of the relevant booking options.
            $bookedusers = $boption->get_all_users_booked();
            // Enrol all users to the course.
            foreach ($bookedusers as $bookeduser) {

                // Todo: If enforceorder is active for this instance, check completion status of previous booked options.

                if ($booking->is_elective()
                    && $enforceorder == 1) {
                    if (!booking_elective::check_if_allowed_to_inscribe($boption, $bookeduser->id)) {
                        continue;
                    }
                }

                $boption->enrol_user($bookeduser->userid);
                // mtrace("The user with the id {$bookeduser->id} has been enrolled to the course {$boption->option->courseid}.");
            }
        }

        // If it's an elective, we can't set enrolmentstatus to 1, because we need to run check again and again.
        if (!empty($boids) && !$booking->is_elective()) {
            list($insql, $params) = $DB->get_in_or_equal(array_keys($boids));
            $DB->set_field_select('booking_options', 'enrolmentstatus', '1', 'id ' . $insql, $params);
        }
    }
}