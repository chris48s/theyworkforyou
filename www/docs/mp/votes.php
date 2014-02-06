<?php

/*
 * MP Votes Page
 *
 * Displays information about an MP's (or person's) votes. Unlike the MP page,
 * only accepts a PID for clarity. You should only arrive here from a PID URL.
 *
 */

include_once "../../includes/easyparliament/init.php";
include_once INCLUDESPATH . "easyparliament/member.php";
include_once INCLUDESPATH . "postcode.inc";
include_once INCLUDESPATH . 'technorati.php';
include_once '../api/api_getGeometry.php';
include_once '../api/api_getConstituencies.php';

twfy_debug_timestamp("after includes");

$errors = array();

$pid = get_http_var('pid');

/////////////////////////////////////////////////////////
// DETERMINE TYPE OF REPRESENTITIVE
if (get_http_var('peer')) $this_page = 'peer';
elseif (get_http_var('royal')) $this_page = 'royal';
elseif (get_http_var('mla')) $this_page = 'mla';
elseif (get_http_var('msp')) $this_page = 'msp';
else $this_page = 'mp';

/////////////////////////////////////////////////////////
// CANONICAL PERSON ID
if (is_numeric($pid))
{

    // Normal, plain, displaying an MP by person ID.
    $MEMBER = new MEMBER(array('person_id' => $pid));

    // If the member ID doesn't exist then the object won't have it set.
    if ($MEMBER->member_id)
    {
        // Ensure that we're actually at the current, correct and canonical URL for the person. If not, redirect.
        if (str_replace('/mp/', '/' . $this_page . '/', get_http_var('url') . '/votes') !== urldecode($MEMBER->url(FALSE, 'votes')))
        {
            member_redirect($MEMBER);
        }
    }
    else
    {
        $errors['pc'] = 'Sorry, that ID number wasn\'t recognised.';
    }
}

/////////////////////////////////////////////////////////
// UNABLE TO IDENTIFY MP
else
{
    // No postcode, member_id or person_id to use.
    twfy_debug ('MP', "We don't have any way of telling what MP to display");
}

/////////////////////////////////////////////////////////
// DISPLAY REPRESENTATIVE VOTES

twfy_debug_timestamp("before load_extra_info");
$MEMBER->load_extra_info(true);
twfy_debug_timestamp("after load_extra_info");

$member_name = ucfirst($MEMBER->full_name());

$title = 'Voting Record - ' . $member_name;
$desc = "View $member_name's Parliamentary voting record";

if ($MEMBER->house(HOUSE_TYPE_COMMONS)) {
    if (!$MEMBER->current_member(1)) {
        $title .= ', former';
    }
    $title .= ' MP';
    if ($MEMBER->constituency()) $title .= ', ' . $MEMBER->constituency();
}
if ($MEMBER->house(HOUSE_TYPE_NI)) {
    if ($MEMBER->house(HOUSE_TYPE_COMMONS) || $MEMBER->house(HOUSE_TYPE_LORDS)) {
        $desc = str_replace('Parliament', 'Parliament and the Northern Ireland Assembly', $desc);
    } else {
        $desc = str_replace('Parliament', 'the Northern Ireland Assembly', $desc);
    }
    if (!$MEMBER->current_member(HOUSE_TYPE_NI)) {
        $title .= ', former';
    }
    $title .= ' MLA';
    if ($MEMBER->constituency()) $title .= ', ' . $MEMBER->constituency();
}
if ($MEMBER->house(HOUSE_TYPE_SCOTLAND)) {
    if ($MEMBER->house(HOUSE_TYPE_COMMONS) || $MEMBER->house(HOUSE_TYPE_LORDS)) {
        $desc = str_replace('Parliament', 'the UK and Scottish Parliaments', $desc);
    } else {
        $desc = str_replace('Parliament', 'the Scottish Parliament', $desc);
    }
    $desc = str_replace(', and get email alerts on their activity', '', $desc);
    if (!$MEMBER->current_member(HOUSE_TYPE_SCOTLAND)) {
        $title .= ', former';
    }
    $title .= ' MSP, '.$MEMBER->constituency();
}
$DATA->set_page_metadata($this_page, 'title', $title);
$DATA->set_page_metadata($this_page, 'meta_description', $desc);
$DATA->set_page_metadata($this_page, 'heading', '');

// So we can put a link in the <head> in $NEWPAGE->page_start();
$feedurl = $DATA->page_metadata('mp_rss', 'url') . $MEMBER->person_id() . '.rdf';
if (file_exists(BASEDIR . '/' . $feedurl))
    $DATA->set_page_metadata($this_page, 'rss', $feedurl);

twfy_debug_timestamp("before page_start");
$NEWPAGE->page_start();
twfy_debug_timestamp("after page_start");

twfy_debug_timestamp("before display of MP");

$MEMBER->display('votes');

twfy_debug_timestamp("after display of MP");

// SIDEBAR.
$sidebars = array(
    array (
        'type'        => 'include',
        'content'    => 'minisurvey'
    )
);

if ( $MEMBER->house(HOUSE_TYPE_COMMONS) && $MEMBER->current_member_anywhere() ) {
    $member = array (
        'member_id'         => $MEMBER->member_id(),
        'person_id'     => $MEMBER->person_id(),
        'constituency'      => $MEMBER->constituency(),
        'party'         => $MEMBER->party_text(),
        'other_parties'     => $MEMBER->other_parties,
        'other_constituencies'  => $MEMBER->other_constituencies,
        'houses'        => $MEMBER->houses(),
        'entered_house'     => $MEMBER->entered_house(),
        'left_house'        => $MEMBER->left_house(),
        'current_member'    => $MEMBER->current_member(),
        'full_name'     => $MEMBER->full_name(),
        'the_users_mp'      => $MEMBER->the_users_mp(),
        'current_member_anywhere'   => $MEMBER->current_member_anywhere(),
        'house_disp'        => $MEMBER->house_disp,
    );
    $topics_html = person_committees_and_topics_for_sidebar($member, $MEMBER->extra_info);

    if ( $topics_html ) {
        $sidebars[] = array ( 'type' => 'html', 'content' => $topics_html);
    }

}

// We have to generate this HTML to pass to stripe_end().
$linkshtml = generate_member_links($MEMBER);
$sidebars[] = array ( 'type' => 'html', 'content' => $linkshtml);

/*
if ($rssurl = $DATA->page_metadata($this_page, 'rss')) {
    $sidebars[] = array (
        'type'         => 'html',
        'content'    => $NEWPAGE->member_rss_block(array('appearances' => WEBPATH . $rssurl))
    );
}
*/


if ($MEMBER->house(HOUSE_TYPE_COMMONS)) {
    global $memcache;
    if (!$memcache) {
        $memcache = new Memcache;
        $memcache->connect('localhost', 11211);
    }
    $nearby = $memcache->get(OPTION_TWFY_DB_NAME . ':nearby_const:' . $MEMBER->person_id());
    if ($nearby === false) {
        $lat = null; $lon = null;
        $nearby = '';
        $geometry = _api_getGeometry_name($MEMBER->constituency());
        if (isset($geometry['centre_lat'])) {
            $lat = $geometry['centre_lat'];
            $lon = $geometry['centre_lon'];
        }
        if ($lat && $lon) {
            $nearby_consts = 0; #_api_getConstituencies_latitude($lat, $lon, 300); XXX Currently disabled
            if ($nearby_consts) {
                $conlist = '<ul><!-- '.$lat.','.$lon.' -->';
                for ($k=1; $k<=min(5, count($nearby_consts)-1); $k++) {
                    $name = $nearby_consts[$k]['name'];
                    $dist = $nearby_consts[$k]['distance'];
                    $conlist .= '<li><a href="' . WEBPATH . 'mp/?c=' . urlencode($name) . '">';
                    $conlist .= $nearby_consts[$k]['name'] . '</a>';
                    $dist_miles = round($dist / 1.609344, 0);
                    $conlist .= ' <small title="Centre to centre">(' . $dist_miles. ' miles)</small>';
                    $conlist .= '</li>';
                }
                $conlist .= '</ul>';
                $nearby = $conlist;
            }
        }
        $memcache->set(OPTION_TWFY_DB_NAME . ':nearby_const:' . $MEMBER->person_id(), $nearby, 0, 3600);
    }
    if ($nearby) {
        $sidebars[] = array(
            'type' => 'html',
            'content' => '<div class="block"><h4>Nearby constituencies</h4><div class="blockbody">' . $nearby . ' </div></div>'
        );
    }
}

if (array_key_exists('office', $MEMBER->extra_info())) {
    $office = $MEMBER->extra_info();
    $office = $office['office'];
    $mins = '';
    foreach ($office as $row) {
        if ($row['to_date'] != '9999-12-31') {
            $mins .= '<li>' . prettify_office($row['position'], $row['dept']);
                   $mins .= ' (';
            if (!($row['source'] == 'chgpages/selctee' && $row['from_date'] == '2004-05-28')
                && !($row['source'] == 'chgpages/privsec' && $row['from_date'] == '2004-05-13')) {
                if ($row['source'] == 'chgpages/privsec' && $row['from_date'] == '2005-11-10')
                    $mins .= 'before ';
                $mins .= format_date($row['from_date'],SHORTDATEFORMAT) . ' ';
            }
            $mins .= 'to ';
            if ($row['source'] == 'chgpages/privsec' && $row['to_date'] == '2005-11-10')
                $mins .= 'before ';
            if ($row['source'] == 'chgpages/privsec' && $row['to_date'] == '2009-01-16')
                $mins .= '<a href="/help/#pps_unknown">unknown</a>';
            else
                $mins .= format_date($row['to_date'], SHORTDATEFORMAT);
            $mins .= ')</li>';
        }
    }
    if ($mins) {
        $sidebars[] = array('type'=>'html',
        'content' => '<div class="block"><h4>Other offices held in the past</h4><div class="blockbody"><ul>'.$mins.'</ul></div></div>');
    }
}

/*    $body = technorati_pretty();
if ($body) {
    $sidebars[] = array (
        'type' => 'html',
        'content' => '<div class="block"><h4>People talking about this MP</h4><div class="blockbody">' . $body . '</div></div>'
);
}
*/
$sidebars[] = array('type'=>'html',
    'content' => '<div class="block"><h4>Note for journalists</h4>
<div class="blockbody"><p>Please feel free to use the data
on this page, but if you do you must cite TheyWorkForYou.com in the
body of your articles as the source of any analysis or
data you get off this site. If you ignore this, we might have to start
keeping these sorts of records on you...</p></div></div>'
);
$NEWPAGE->stripe_end($sidebars, '', false);

$NEWPAGE->page_end();

function member_redirect(&$MEMBER, $code = 301) {
    // We come here after creating a MEMBER object by various methods.
    // Now we redirect to the canonical MP page, with a person_id.
    if ($MEMBER->person_id()) {
        $url = $MEMBER->url('votes');
        $params = array();
        foreach ($_GET as $key => $value) {
            if (substr($key, 0, 4) == 'utm_' || $key == 'gclid')
                $params[] = "$key=$value";
        }
        if (count($params))
            $url .= '?' . join('&', $params);
        header('Location: ' . $url, true, $code );
        exit;
    }
}

function generate_member_links ($member) {
    // Receives its data from $MEMBER->display_links;
    // This returns HTML, rather than outputting it.
    // Why? Because we need this to be in the sidebar, and
    // we can't call the MEMBER object from the sidebar includes
    // to get the links. So we call this function from the mp
    // page and pass the HTML through to stripe_end(). Better than nothing.

    $links = $member->extra_info();

    // Bah, can't use $this->block_start() for this, as we're returning HTML...
    $html = '<div class="block">
            <h4>More useful links for this person</h4>
            <div class="blockbody">
            <ul>';

    if (isset($links['maiden_speech'])) {
        $maiden_speech = fix_gid_from_db($links['maiden_speech']);
        $html .= '<li><a href="' . WEBPATH . 'debate/?id=' . $maiden_speech . '">Maiden speech</a> (automated, may be wrong)</li>';
    }

    // BIOGRAPHY.
    global $THEUSER;
    if (isset($links['mp_website'])) {
        $html .= '<li><a href="' . $links['mp_website'] . '">'. $member->full_name().'\'s personal website</a>';
        if ($THEUSER->is_able_to('viewadminsection')) {
            $html .= ' [<a href="/admin/websites.php?editperson=' .$member->person_id() . '">Edit</a>]';
        }
        $html .= '</li>';
    } elseif ($THEUSER->is_able_to('viewadminsection')) {
         $html .= '<li>[<a href="/admin/websites.php?editperson=' . $member->person_id() . '">Add personal website</a>]</li>';
    }

    if (isset($links['twitter_username'])) {
        $html .= '<li><a href="http://twitter.com/' . $links['twitter_username'] . '">'. $member->full_name().'&rsquo;s Twitter feed</a></li>';
    }

    if (isset($links['sp_url'])) {
        $html .= '<li><a href="' . $links['sp_url'] . '">'. $member->full_name().'\'s page on the Scottish Parliament website</a></li>';
    }

    if (isset($links['guardian_biography'])) {
        $html .= '    <li><a href="' . $links['guardian_biography'] . '">Guardian profile</a></li>';
    }
    if (isset($links['wikipedia_url'])) {
        $html .= '    <li><a href="' . $links['wikipedia_url'] . '">Wikipedia page</a></li>';
    }

    if (isset($links['bbc_profile_url'])) {
        $html .= '      <li><a href="' . $links['bbc_profile_url'] . '">BBC News profile</a></li>';
    }

    if (isset($links['diocese_url'])) {
        $html .= '    <li><a href="' . $links['diocese_url'] . '">Diocese website</a></li>';
    }

    if ($member->house(HOUSE_TYPE_COMMONS)) {
        $html .= '<li><a href="http://www.edms.org.uk/mps/' . $member->person_id() . '/">Early Day Motions signed by this MP</a> <small>(From edms.org.uk)</small></li>';
    }

    if (isset($links['journa_list_link'])) {
        $html .= '      <li><a href="' . $links['journa_list_link'] . '">Newspaper articles written by this MP</a> <small>(From Journalisted)</small></li>';
    }

    if (isset($links['guardian_election_results'])) {
        $html .= '      <li><a href="' . $links['guardian_election_results'] . '">Election results for ' . $member->constituency() . '</a> <small>(From The Guardian)</small></li>';
    }

    /*
    # BBC Catalogue is offline
    $bbc_name = urlencode($member->first_name()) . "%20" . urlencode($member->last_name());
    if ($member->member_id() == -1)
        $bbc_name = 'Queen Elizabeth';
    $html .= '      <li><a href="http://catalogue.bbc.co.uk/catalogue/infax/search/' . $bbc_name . '">TV/radio appearances</a> <small>(From BBC Programme Catalogue)</small></li>';
    */

    $html .= "      </ul>
                </div>
            </div> <!-- end block -->
";
    return $html;
}

