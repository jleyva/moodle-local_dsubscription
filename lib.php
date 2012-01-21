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
 * Plugin lib.
 * This script includes also some hacks for adding new options manipulating the DOM
 *
 * @package    local
 * @subpackage dsubscription
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Add new nodes to the settings blocks using javascript (Yui3 Node)
 *
 * @global moodle_user $USER
 * @global moodle_page $PAGE
 * @param navigation_node $nav Current navigation object
 */
function dsubscription_extends_navigation ($nav) {
    global $USER, $PAGE;
    
    // Check if we need to manipulate the DOM for adding links for managers and users
    if ($PAGE->context->contextlevel == CONTEXT_MODULE){
        if ($cm = get_coursemodule_from_id(false, $PAGE->context->instanceid, 0, false) and $cm->modname == 'forum') {            
            // Hack for adding custom javascript
            $params = array('id' => $PAGE->context->instanceid);
            
            // Check if we are in a discussion page
            // This is better than using ME or FULLME
            if ($PAGE->url instanceof moodle_url and strpos($PAGE->url->out_omit_querystring(), 'mod/forum/discuss.php') !== false) {
                $params['d'] = optional_param('d', 0, PARAM_INT);
            }
            
            $PAGE->requires->js(new moodle_url('/local/dsubscription/layout.php', $params));
        }
    }
}

/**
 * Cron function for manage discuss subscriptions
 * This is a very modified copy of the current forum cron system
 * @return void
 */
function local_dsubscription_cron() {
    global $DB, $CFG;
    
    // We nee some auxiliary functions
    require_once($CFG->dirroot.'/mod/forum/lib.php');
    
    $site = get_site();

    // all users that are subscribed to any post that needs sending
    $users = array();

    // status arrays
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions     = array();
    $forums          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();


    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    if ($posts = dsubscription_get_unmailed_posts($starttime, $endtime, $timenow)) {

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {
            
            // Mark as mailed
            $oldpost = new stdClass;
            $oldpost->post = $pid;
            $oldpost->mailed = 1;
            $DB->insert_record('local_dsubscription_post', $post);

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('forum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $forumid = $discussions[$discussionid]->forum;
            if (!isset($forums[$forumid])) {
                if ($forum = $DB->get_record('forum', array('id' => $forumid))) {
                    $forums[$forumid] = $forum;
                } else {
                    mtrace('Could not find forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $forums[$forumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$forumid])) {
                if ($cm = get_coursemodule_from_instance('forum', $forumid, $courseid)) {
                    $coursemodules[$forumid] = $cm;
                } else {
                    mtrace('Could not find course module for forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each discuss
            if (!isset($subscribedusers[$discussionid])) {
                $postusers = dsubscription_get_subscribed_users($discussionid, $forums[$forumid], $courses[$courseid], null, 0);
                
                foreach ($postusers as $postuser) {
                    $subscribedusers[$discussionid][$postuser->id] = $postuser->id;
                    $users[$postuser->id] = $postuser;
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {

            @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

            // set this so that the capabilities are cached, and environment matches receiving user
            cron_setup_user($userto);

            mtrace('Processing user '.$userto->id);

            // init caches
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // reset the caches
            foreach ($coursemodules as $forumid=>$unused) {
                $coursemodules[$forumid]->cache       = new stdClass();
                $coursemodules[$forumid]->cache->caps = array();
                unset($coursemodules[$forumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, forum, course
                $discussion = $discussions[$post->discussion];
                $forum      = $forums[$discussion->forum];
                $course     = $courses[$forum->course];
                $cm         =& $coursemodules[$forum->id];

                // Do some checks  to see if we can bail out now
                // Only active enrolled users are in the list of subscribers
                if (!isset($subscribedusers[$discussion->id][$userto->id])) {
                    continue; // user does not subscribe to this forum
                }

                // Don't send email if the forum is Q&A and the user has not posted
                if ($forum->type == 'qanda' && !forum_get_user_posted_time($discussion->id, $userto->id)) {
                    mtrace('Did not email '.$userto->id.' because user has not posted in discussion');
                    continue;
                }

                // Get info about the sending user
                if (array_key_exists($post->userid, $users)) { // we might know him/her already
                    $userfrom = $users[$post->userid];
                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    unset($userfrom->description); // not necessary
                    $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                } else {
                    mtrace('Could not find user '.$post->userid);
                    continue;
                }

                //if we want to check that userto and userfrom are not the same person this is probably the spot to do it

                // setup global $COURSE properly - needed for roles and languages
                cron_setup_user($userto, $course);

                // Fill caches
                if (!isset($userto->viewfullnames[$forum->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->canpost[$discussion->id] = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$forum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        $users[$userfrom->id]->groups = array();
                    }
                    $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                }

                // Make sure groups allow this user to see this email
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
                    if (!groups_group_exists($discussion->groupid)) { // Can't find group
                        continue;                           // Be safe and don't send it to anyone
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
                        continue;
                    }
                }

                // Make sure we're allowed to see it...
                if (!forum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
                    mtrace('user '.$userto->id. ' can not see '.$post->id);
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                if ($userto->maildigest > 0) {
                    // This user wants the mails to be in digest form
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('local_dsubscription_q', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content

                $cleanforumname = str_replace('"', "'", strip_tags(format_string($forum->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleanforumname.'" <moodleforum'.$forum->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id,
                           'Message-ID: <moodlepost'.$post->id.'@'.$hostname.'>',
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );

                if ($post->parent) {  // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: <moodlepost'.$post->parent.'@'.$hostname.'>';
                    $userfrom->customheaders[] = 'References: <moodlepost'.$post->parent.'@'.$hostname.'>';
                }

                $shortname = format_string($course->shortname, true, array('context' => get_context_instance(CONTEXT_COURSE, $course->id)));

                $postsubject = "$shortname: ".format_string($post->subject,true);
                $posttext = forum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto);
                $posthtml = forum_make_mail_html($course, $cm, $forum, $discussion, $post, $userfrom, $userto);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new stdClass();
                $eventdata->component        = 'mod_forum';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $userfrom;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->notification = 1;

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user = fullname($userfrom);
                $smallmessagestrings->forumname = "$shortname: ".format_string($forum->name,true).": ".$discussion->name;
                $smallmessagestrings->message = $post->message;
                //make sure strings are in message recipients language
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'forum', $smallmessagestrings, $userto->lang);

                $eventdata->contexturl = "{$CFG->wwwroot}/mod/forum/discuss.php?d={$discussion->id}#p{$post->id}";
                $eventdata->contexturlname = $discussion->name;

                $mailresult = message_send($eventdata);
                if (!$mailresult){
                    mtrace("Error: mod/forum/lib.php forum_cron(): Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    add_to_log($course->id, 'forum', 'mail error', "discuss.php?d=$discussion->id#p$post->id",
                               substr(format_string($post->subject,true),0,30), $cm->id, $userto->id);
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;

                // Mark post as read if forum_usermarksread is set off
                    if (!$CFG->forum_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post '.$post->id. ': '.$post->subject);
            }

            // mark processed posts as read
            forum_tp_mark_posts_read($userto, $userto->markposts);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field("local_dsubscription_post", "mailed", "2", array("post" => "$post->id"));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = $CFG->timezone;

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    @set_time_limit(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->dsubsdigestmailtimelast)) {    // To catch the first time
        set_config('dsubsdigestmailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('local_dsubscription_q', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->dsubsdigestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending forum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('local_dsubscription_q', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($users[$digestpost->userid])) {
                    if ($user = $DB->get_record('user', array('id' => $digestpost->userid))) {
                        $users[$digestpost->userid] = $user;
                    } else {
                        continue;
                    }
                }
                $postuser = $users[$digestpost->userid];

                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('forum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('forum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $forumid = $discussions[$discussionid]->forum;
                if (!isset($forums[$forumid])) {
                    if ($forum = $DB->get_record('forum', array('id' => $forumid))) {
                        $forums[$forumid] = $forum;
                    } else {
                        continue;
                    }
                }

                $courseid = $forums[$forumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$forumid])) {
                    if ($cm = get_coursemodule_from_instance('forum', $forumid, $courseid)) {
                        $coursemodules[$forumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'forum', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('local_dsubscription_q', "userid = ? AND timemodified < ?", array($userid, $digesttime));
                $userto = $users[$userid];

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                // init caches
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                $postsubject = get_string('digestmailsubject', 'forum', format_string($site->shortname, true));

                $headerdata = new stdClass();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'forum', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'forum').'</a>';

                $posthtml = "<head>";
/*                foreach ($CFG->stylesheets as $stylesheet) {
                    //TODO: MDL-21120
                    $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
                }*/
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'forum', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    @set_time_limit(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $forum      = $forums[$discussion->forum];
                    $course     = $courses[$forum->course];
                    $cm         = $coursemodules[$forum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$forum->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->canpost[$discussion->id] = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strforums      = get_string('forums', 'forum');
                    $canunsubscribe = ! forum_is_forcesubscribed($forum);
                    $canreply       = $userto->canpost[$discussion->id];
                    $shortname = format_string($course->shortname, true, array('context' => get_context_instance(CONTEXT_COURSE, $course->id)));

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$shortname -> $strforums -> ".format_string($forum->name,true);
                    if ($discussion->name != $forum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/index.php?id=$course->id\">$strforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/view.php?f=$forum->id\">".format_string($forum->name,true)."</a>";
                    if ($discussion->name == $forum->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/forum/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$forum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                $users[$userfrom->id]->groups = array();
                            }
                            $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                        }

                        $userfrom->customheaders = array ("Precedence: Bulk");

                        if ($userto->maildigest == 2) {
                            // Subjects only
                            $by = new stdClass();
                            $by->name = fullname($userfrom);
                            $by->date = userdate($post->modified);
                            $posttext .= "\n".format_string($post->subject,true).' '.get_string("bynameondate", "forum", $by);
                            $posttext .= "\n---------------------------------------------------------------------";

                            $by->name = "<a target=\"_blank\" href=\"$CFG->wwwroot/user/view.php?id=$userfrom->id&amp;course=$course->id\">$by->name</a>";
                            $posthtml .= '<div><a target="_blank" href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$discussion->id.'#p'.$post->id.'">'.format_string($post->subject,true).'</a> '.get_string("bynameondate", "forum", $by).'</div>';

                        } else {
                            // The full treatment
                            $posttext .= forum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= forum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->forum_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    if ($canunsubscribe) {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\"><a href=\"$CFG->wwwroot/mod/forum/subscribe.php?id=$forum->id\">".get_string("unsubscribe", "forum")."</a></font></div>";
                    } else {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\">".get_string("everyoneissubscribed", "forum")."</font></div>";
                    }
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if (empty($userto->mailformat) || $userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $attachment = $attachname='';
                $usetrueaddress = true;
                //directly email forum digests rather than sending them via messaging
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname, $usetrueaddress, $CFG->forum_replytouser);

                if (!$mailresult) {
                    mtrace("ERROR!");
                    echo "Error: mod/forum/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    add_to_log($course->id, 'forum', 'mail digest error', '', '', $cm->id, $userto->id);
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if forum_usermarksread is set off
                    forum_tp_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update $CFG->dsubsdigestmailtimelast
        set_config('dsubsdigestmailtimelast', $timenow);
    } else {
        mtrace("No diggest, timenow: $timenow digesttime: $digesttime");
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'forum', $usermailcount));
    }
    
    return true;
   
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function dsubscription_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array($starttime, $endtime);
    if (!empty($CFG->forum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.forum
                              FROM {forum_posts} p
                                   JOIN {forum_discussions} d ON d.id = p.discussion
                                   LEFT JOIN {local_dsubscription_post} sp ON sp.post <> p.id
                                   JOIN {local_dsubscription} s ON s.forum = d.forum
                                   JOIN (
                                        SELECT DISTINCT discuss FROM {local_dsubscription_subs} WHERE enabled = 1
                                   ) sd ON sd.discuss = d.id                                   
                             WHERE s.enabled = 1                                    
                                   AND p.created >= ?
                                   AND (p.created < ?)
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}


/**
 * Returns list of user objects that are subscribed to this forum
 *
 * @global object
 * @global object
 * @param integer $discussionid
 * @return array list of users.
 */
function dsubscription_get_subscribed_users($discussionid, $forum, $course, $context, $groupid) {
    global $CFG, $DB;
    
    $fields ="u.id,
              u.username,
              u.firstname,
              u.lastname,
              u.maildisplay,
              u.mailformat,
              u.maildigest,
              u.imagealt,
              u.email,
              u.emailstop,
              u.city,
              u.country,
              u.lastaccess,
              u.lastlogin,
              u.picture,
              u.timezone,
              u.theme,
              u.lang,
              u.trackforums,
              u.mnethostid";
              
    if (empty($context)) {
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id);
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }              
    
    // only active enrolled users or everybody on the frontpage
    list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
    $params['discussionid'] = $discussionid;
    $results = $DB->get_records_sql("SELECT $fields
                                       FROM {user} u
                                       JOIN ($esql) je ON je.id = u.id
                                       JOIN {local_dsubscription_subs} s ON s.userid = u.id
                                      WHERE s.discuss = :discussionid
                                            AND s.enabled = 1
                                   ORDER BY u.email ASC", $params);


    // Guest user should never be subscribed to a forum.
    unset($results[$CFG->siteguest]);

    return $results;    
}