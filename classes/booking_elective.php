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
namespace mod_booking;
use mod_booking\booking_utils;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/calendar/lib.php');

/**
 * Deal with elective
 * @package mod_booking
 * @copyright 2021 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_elective {

    /** @var booking object  */
    public $booking = null;

    /**
     * Deals with all elective stuff
     *
     */
    public function __construct() {

    }

    /**
     * Called from lib.php to add autocomplete array to DB.
     * Deals with mustcombine and can't combine
     * 0 is can't combine, 1 is must combine,
     * @param $optionid
     * @param $otheroptions
     * @param $mustcombine
     * @throws \dml_exception
     */
    public static function addcombinations($optionid, $otheroptions, $mustcombine) {

        global $DB;
        // First we need to see if there are already entries in DB.
        // We fetch cancombine and cannotcombine at the same time.
        $existingrecords = $DB->get_records('booking_combinations', array('optionid' => $optionid, 'cancombine' => $mustcombine));

        // Run through the array of selected options and save them to db.
        foreach ($otheroptions as $otheroptionid) {

            // Check if the record exists already.
            if ($id = self::optionidexists($existingrecords, $otheroptionid, $mustcombine)) {
                    // Mark record as existing
                    $existingrecords[$id]->exists = true;
                continue;
            }
            // If we haven't found the record, we insert an entry.
            $newbookingentry = new stdClass();
            $newbookingentry->optionid = $optionid;
            $newbookingentry->otheroptionid = $otheroptionid;
            $newbookingentry->othercourseid = null;
            $newbookingentry->cancombine = $mustcombine;

            $DB->insert_record('booking_combinations', $newbookingentry);
        }

        // Finally, we run through the existing records and see which were not in the array.
        // We have to delete these.

        foreach ($existingrecords as $item) {
            if (!property_exists($item, 'exists')) {
                $DB->delete_records('booking_combinations', array('id' => $item->id));
            }
        }
    }

    /**
     * @param $optionid
     * @param $mustcombine
     * @return array
     * @throws \dml_exception
     */
    public static function get_combine_array($optionid, $mustcombine) {
        global $DB;
        return $DB->get_fieldset_select('booking_combinations', 'otheroptionid', "optionid = {$optionid} AND cancombine = {$mustcombine}");
    }

    /**
     * @param $booking
     * @return bool
     */
    public static function is_elective($booking) {
        if ($booking->settings->iselective == 1) {
            return true;
        }
        return false;
    }

    public static function show_credits_message($booking) {
        global $USER;

        $warning = '';

        if (!empty($booking->settings->banusernames)) {
            $disabledusernames = explode(',', $booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $warning = html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
                }
            }
        }

        if (!$booking->settings->maxcredits) {
            return $warning; // No credits maximum set.
        }

        $outdata = new stdClass();
        $outdata->creditsleft = booking_elective::return_credits_left($booking);
        $outdata->maxcredits = $booking->settings->maxcredits;

        $warning .= \html_writer::tag('div', get_string('creditsmessage', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));
        return $warning;
    }

    /**
     * Helper function to return the sum of credits of already booked electives
     * @param stdClass $booking
     * @return int the sum of credits booked
     */
    public static function return_credits_booked($booking) {
        global $DB, $USER;

        $sql = "SELECT bo.id, bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE ba.userid = $USER->id
        AND bo.bookingid = $booking->id"
        ;

        $data = $DB->get_records_sql($sql);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        return $credits;
    }

    /**
     * Helper function to return the number of credits left after booking.
     * @param stdClass $booking
     * @return int the number of credits left
     */
    public static function return_credits_left($booking) {

        global $DB, $USER;

        $sql = "SELECT bo.id, bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE ba.userid = $USER->id
        AND bo.bookingid = $booking->id"
        ;

        $data = $DB->get_records_sql($sql);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        $credits += self::return_credits_selected($booking);

        $credits = +$booking->settings->maxcredits - $credits;

        return $credits;
    }

    /**
     * Helper function to count the sum of all currently selected electives.
     * @param stdClass $booking the current bookinginstance
     * @return numeric the sum of credits of all currently selected electives
     */
    public static function return_credits_selected($booking) {
        global $DB;
        $electivesarray = self::get_electivesarray_from_user_prefs($booking->cm->id);

        $credits = 0;
        foreach ($electivesarray as $selected) {
            if (!empty($selected)) {
                if (!$record = $DB->get_record('booking_options', ['id' => (int) $selected], 'credits')) {
                    return false;
                } else {
                    $credits += $record->credits;
                }
            }
        }
        return $credits;
    }

    /**
     * helperfunction to check entries from booking_combine table for match
     * @param $array
     * @param $optionid
     * @param $mustcombine
     * @return false
     */
    private static function optionidexists($array, $optionid, $mustcombine) {
        if ($optionid && $optionid !== 0) {
            foreach ($array as $item) {
                if ($item->optionid == $optionid
                        && $item->cancombine == $mustcombine ) {
                    return $item->id;
                }
            }
        }
        return false;
    }

    /**
     * Helper function to get an array of all selected elective options of the current instance.
     * @param number $cmid the instance id of the booking instance (course module id)
     * @return array an array of the currently selected electives on the current booking instance.
     */
    public static function get_electivesarray_from_user_prefs ($cmid) {
        $electivespref = get_user_preferences('selected_electives', '');

        if ($electivespref && $electivespref != '') {
            $dataobject = json_decode($electivespref);
            if ($dataobject && isset($dataobject->$cmid)) {
                return (array)$dataobject->$cmid;
            }

        }
        return [];
    }

    /**
     * Helper function to update the selected electives user preferences which will update the
     * electives array in user preferences. Needs the updated object as parameter.
     * @param stdClass the updated object (with instance id and updated electives array in "selected")
     */
    public static function set_electivesarray_to_user_prefs(stdClass $updatedobject) {
        //set_user_preference('selected_electives', ''); // Debugging.
        $jsonstring = get_user_preferences('selected_electives', '');
        $electivespref = json_decode($jsonstring);
        $instanceid = $updatedobject->instanceid;
        if (!isset($electivespref->$instanceid)
        || !in_array($updatedobject->optionid, $electivespref->$instanceid)) {
            if (!$electivespref) {
                $electivespref = new stdClass();
            }
            $electivespref->$instanceid[] = $updatedobject->optionid;
        } else {
            // Delete the value.
            $arrayofids = (array)$electivespref->$instanceid;
            $key = array_search($updatedobject->optionid, $arrayofids);
            array_splice($arrayofids, $key, 1);
            $electivespref->$instanceid = $arrayofids;
        }
        $jsonstring = json_encode($electivespref);
        // Now recreate the string and save to user prefs.
        set_user_preference('selected_electives', $jsonstring);
    }


    /**
     * Function to set back the selected items in the user prefs.
     * @param $cmid
     * @throws \coding_exception
     */
    public static function reset_electivesarray_in_user_prefs($cmid) {
        $electivespref = get_user_preferences('selected_electives', '');

        if ($electivespref && $electivespref != '') {
            $dataobject = json_decode($electivespref);
            if ($dataobject && isset($dataobject->$cmid)) {
                $dataobject->$cmid = [];
                $jsonstring = json_encode($dataobject);
                // Now recreate the string and save to user prefs.
                set_user_preference('selected_electives', $jsonstring);
            }
        }
    }
}
