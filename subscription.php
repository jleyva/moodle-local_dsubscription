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
 * Enable or disable discussion subscription in a forum.
 *
 * @package    local
 * @subpackage dsubscription
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');

$id = required_param('id', PARAM_INT);
$enabled = required_param('enabled', PARAM_INT);
$confirm  = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('', $id, 0, false, MUST_EXIST);
$forum = $DB->get_record('forum', array('id' => $cm->instance), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$PAGE->set_url('/local/dsubscription/subscription.php', array('id' => $id,'enabled' => $enabled));

require_login($course, false, $cm); // needed to setup proper $COURSE

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/forum:managesubscriptions', $context);

$strenableordisable = get_string('enableordisable', 'local_dsubscription');
$return = new moodle_url('/mod/forum/view.php', array('id' => $id));

$PAGE->navbar->add(get_string('pluginname', 'local_dsubscription'));
$PAGE->navbar->add($strenableordisable);
$PAGE->set_title($strenableordisable);
$PAGE->set_heading(format_string($course->fullname));
echo $OUTPUT->header();


if ($confirm and confirm_sesskey()) {
    
    if ( !$forum = $DB->get_record('local_dsubscription', array('forum' => $forum->id))) {
        $forum = new stdClass;
        $forum->forum = $forum->id;
        $forum->enabled = 0;
        $forum->id = $DB->insert_record('local_dsubscription', $forum);
    }
    
    if( $enabled ) {
        $forum->enabled = 1;
        $msg = get_string('enableddone', 'local_dsubscription');
    } else {
        $forum->enabled = 0;
        $msg = get_string('disableddone', 'local_dsubscription');
    }
    
    $DB->update_record('local_dsubscription', $forum);
    
    echo $OUTPUT->box($msg);
    echo $OUTPUT->continue_button($return);
    echo $OUTPUT->footer();
    die;
}

if( $enabled ) {
    $msg = get_string('enableconfirm', 'local_dsubscription');
} else {
    $msg = get_string('disableconfirm', 'local_dsubscription');
}

echo $OUTPUT->confirm($msg, new moodle_url('subscription.php', array('id' => $id,'enabled' => $enabled, 'confirm' => 1, 'sesskey' => sesskey())), $return);
echo $OUTPUT->footer();
