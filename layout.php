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
 * Enable or disable anonymous posting in a forum.
 *
 * @package    local
 * @subpackage dsubscription
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');

$id = required_param('id', PARAM_INT);
$d  = optional_param('d', 0, PARAM_INT);

$cm = get_coursemodule_from_id('', $id, 0, false, MUST_EXIST);
$forum = $DB->get_record('forum', array('id' => $cm->instance), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);
$context = get_context_instance(CONTEXT_MODULE, $cm->id);

$PAGE->set_url('/local/dsubscription/layout.php', array('id'=>$id));

require_login($course, false, $cm); // needed to setup proper $COURSE

$changeuserurl = $CFG->wwwroot.'/local/dsubscription/changeuser.php?id='.$id;

$strdsubscription = get_string('dsubscription', 'local_dsubscription');
$strenable = get_string('enable', 'local_dsubscription');
$strdisable = get_string('disable', 'local_dsubscription');
$strenabled = get_string('enabled', 'local_dsubscription');
$strdisabled = get_string('disabled', 'local_dsubscription');

$status = $DB->get_record('local_dsubscription', array('forum' => $forum->id, 'enabled' => 1));

$jsedit = "";
if (has_capability('mod/forum:managesubscriptions', $context)) {

    $params = array("id" => $id);
        
    if ($status) {
        $params['enabled'] = 0;
        $mode = $strenabled;
        $action = $strdisable;
    }
    else{
        $params['enabled'] = 1;
        $mode = $strdisabled;
        $action = $strenable;        
    }
    
    $actionurl = new moodle_url('/local/dsubscription/subscription.php', $params);
    
    $newnode = "<li class=\"type_unknown collapsed contains_branch\"><p class=\"tree_item branch\"><span tabindex=\"0\">$strdsubscription</span></p>";
    $newnode .= "<ul id=\"yui_3_4_1_1_1326125892104_50\">";
    $newnode .= "<li class=\"type_setting collapsed item_with_icon\"><p class=\"tree_item leaf activesetting\"><span tabindex=\"0\"><img alt=\"moodle\" class=\"smallicon navicon\" title=\"moodle\" src=\"".$CFG->wwwroot."/theme/image.php?theme=standard&amp;image=i%2Fnavigationitem&amp;rev=180\">$mode</span></p></li>";
    $newnode .= "<li class=\"type_setting collapsed item_with_icon\"><p class=\"tree_item leaf\"><a title=\"Forced subscription\" href=\"$actionurl\"><img alt=\"moodle\" class=\"smallicon navicon\" title=\"moodle\" src=\"".$CFG->wwwroot."/theme/image.php?theme=standard&amp;image=i%2Fnavigationitem&amp;rev=180\">$action</a></p></li>";
    $newnode .= "</ul></li>";
    
    $jsedit = "
    var stop = false;
    var settingsnav = Y.one('#settingsnav');
    if (settingsnav) {
        var settings = settingsnav.one('.block_tree').all('ul');
        settings.each(function (setting) {
            var lists = setting.all('li');
            lists.each(function (list) {        
                if (!stop && list.getContent().indexOf('subscribers.php?id=".$cm->instance."') ) {
                    setting.append('".$newnode."');
                    stop = true;
                    return;
                }
            });
            if(stop){
                return;
            }
        });
    }
";
}

$jsuser = "";
if (has_capability('mod/forum:viewdiscussion', $context) and $d) {
    
    $strsubs = get_string('subscribediscuss', 'local_dsubscription');
    $strunsubs = get_string('unsubscribediscuss', 'local_dsubscription');
    
    $params = array('d' => $d, 'sesskey' => sesskey());
    
    if ($subscribed = $DB->get_record('local_dsubscription_subs', array ('userid' => $USER->id, 'discuss' => $d, 'enabled' => 1))) {
        $straction = $strunsubs;
        $params['enabled'] = 0;
    } else {
        $straction = $strsubs;
        $params['enabled'] = 1;
    }
    
    $url = new moodle_url('/local/dsubscription/subscribe.php', $params);
    
    $newnode = "<li class=\"type_setting collapsed item_with_icon\"><p class=\"tree_item leaf\"><a title=\"$straction\" href=\"$url\"><img alt=\"moodle\" class=\"smallicon navicon\" title=\"moodle\" src=\"{$CFG->wwwroot}/theme/image.php?theme=standard&amp;image=i%2Fnavigationitem&amp;rev=180\">$straction</a>    </p>    </li>";
    
    $jsuser .= "
    var stop = false;
    var settingsnav = Y.one('#settingsnav');
    if (settingsnav) {
        var settings = settingsnav.one('.block_tree').all('ul');
        
        // First we check if the forum settings blocks is present
        settings.each(function (setting) {
            var lists = setting.all('li');
            lists.each(function (list) {        
                if (!stop && list.getContent().indexOf('subscribers.php?id=".$cm->instance."') ) {
                    setting.append('".$newnode."');
                    stop = true;
                    return;
                }
            });
            if(stop){
                return;
            }
        });
        
        // The forum settings block was not present
        if (!stop) {
            settings.each(function (setting) {
                var lists = setting.all('li');
                lists.each(function (list) {                            
                    setting.append('".$newnode."');
                    stop = true;
                    return                    
                });
                if(stop){
                    return;
                }
            });
        }
        
    }
    ";
    
}

$js = "

YUI().use('node', function (Y) {

".$jsuser."
    
".$jsedit."
    
});

";


$lifetime  = 600;                                   // Seconds to cache this stylesheet


header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
header('Expires: ' . gmdate("D, d M Y H:i:s", time() - $lifetime) . ' GMT');
header('Cache-control: max_age = '. $lifetime);
header('Pragma: ');
header('Content-type: text/javascript; charset=utf-8');  // Correct MIME type

echo $js;
die;
