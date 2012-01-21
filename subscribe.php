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
 * Enable or disable discussion subscription in a forum for a particular user.
 *
 * @package    local
 * @subpackage dsubscription
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');

$d       = required_param('d', PARAM_INT);                // Discussion ID
$enabled = required_param('enabled', PARAM_INT);

$discussion = $DB->get_record('forum_discussions', array('id' => $d), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
$forum = $DB->get_record('forum', array('id' => $discussion->forum), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

$PAGE->set_url('/local/dsubscription/subscribe.php', array('d' => $d));

require_course_login($course, false, $cm); // needed to setup proper $COURSE

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/forum:viewdiscussion', $context);

require_sesskey();

if (! $status = $DB->get_record('local_dsubscription', array('forum' => $forum->id, 'enabled' => 1))) {
    print_error('disableddone', 'local_dsubscription');
}

$strenableordisable = get_string('enableordisable', 'local_dsubscription');
$return = new moodle_url('/mod/forum/discuss.php', array('d' => $d));

$PAGE->set_title($strenableordisable);
$PAGE->set_heading(format_string($course->fullname));

if (!$subscription = $DB->get_record('local_dsubscription_subs', array('userid' => $USER->id, 'discuss' => $d))) {
    $subscription = new stdClass;
    $subscription->userid = $USER->id;
    $subscription->discuss = $d;
    $subscription->enabled = 0;
    $subscription->id = $DB->insert_record('local_dsubscription_subs', $subscription);
}
    
if( $enabled ) {
    $subscription->enabled = 1;
    $msg = get_string('subscribed', 'local_dsubscription');
} else {
    $subscription->enabled = 0;
    $msg = get_string('unsubscribed', 'local_dsubscription');
}

$DB->update_record('local_dsubscription_subs', $subscription);
redirect($return, $msg, 2);
