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
defined('MOODLE_INTERNAL') || die();
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
            $newbookingentry = new \stdClass();
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
        if ($booking->settings->eventtype === 'elective') {
            return true;
        }
        return false;
    }

    /**
     *
     */
    public static function show_credits_message($booking) {
        global $USER;

        $warning = '';

        if (!empty($booking->settings->banusernames)) {
            $disabledusernames = explode(',', $this->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $warning = html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
                }
            }
        }

        if (!$booking->settings->maxperuser) {
            return $warning; // No per-user limits.
        }

        $outdata = new \stdClass();
        $outdata->maxcredits = $booking->settings->maxperuser;
        $outdata->credits = booking_elective::return_credits($booking, $USER);
        //$outdata->eventtype = $booking->settings->eventtype;
        $warning .= \html_writer::tag('div', get_string('creditsleft', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));
        return $warning;
    }


    /**
     * @param $optionid
     * @return array
     * @throws \dml_exception
     */
    public static function return_credits($booking, $USER) {

        global $DB;

        $sql = "SELECT bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE userid = 3";

        $data = $DB->get_records_sql($sql);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        $credits = +$booking->settings->maxperuser - $credits;

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
}
