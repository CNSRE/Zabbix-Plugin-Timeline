<?php
/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/hosts.inc.php';
require_once dirname(__FILE__).'/include/events.inc.php';

$page['title'] = _('Events Timeline');
$page['file'] = 'timeline.php';
$page['hist_arg'] = array('groupid', 'hostid');
$page['scripts'] = array('class.calendar.js', 'gtlc.js');
$page['type'] = detect_page_type(PAGE_TYPE_HTML);

if (PAGE_TYPE_HTML == $page['type']) {
	define('ZBX_PAGE_DO_REFRESH', 1);
}

require_once dirname(__FILE__).'/include/page_header.php';

echo '<link rel="stylesheet" type="text/css" href="styles/timeline.css">';
echo '<script type="text/javascript" src="js/timeline.js"></script>';

$allowed_sources[] = EVENT_SOURCE_TRIGGERS;

//		VAR			TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'source'=>			array(T_ZBX_INT, O_OPT, P_SYS,	IN($allowed_sources), null),
	'groupid'=>			array(T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null),
	'hostid'=>			array(T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null),
	'triggerid'=>		array(T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null),
	'period'=>			array(T_ZBX_INT, O_OPT, null,	null,		null),
	'dec'=>				array(T_ZBX_INT, O_OPT, null,	null,		null),
	'inc'=>				array(T_ZBX_INT, O_OPT, null,	null,		null),
	'left'=>			array(T_ZBX_INT, O_OPT, null,	null,		null),
	'right'=>			array(T_ZBX_INT, O_OPT, null,	null,		null),
	'stime'=>			array(T_ZBX_STR, O_OPT, null,	null,		null),
	'load'=>			array(T_ZBX_STR, O_OPT, P_SYS,	NULL,		null),
	'fullscreen'=>		array(T_ZBX_INT, O_OPT, P_SYS,	IN('0,1'),	null),
	'filter_rst'=>		array(T_ZBX_INT, O_OPT, P_SYS,	IN(array(0,1)), null),
	'filter_set'=>		array(T_ZBX_STR, O_OPT, P_SYS,	null,		null),
	// ajax
	'favobj'=>			array(T_ZBX_STR, O_OPT, P_ACT,	null,		null),
	'favref'=>			array(T_ZBX_STR, O_OPT, P_ACT,	NOT_EMPTY,	'isset({favobj})&&"filter"=={favobj}'),
	'favstate'=>		array(T_ZBX_INT, O_OPT, P_ACT,	NOT_EMPTY,	'isset({favobj})&&"filter"=={favobj}'),
	'favid'=>			array(T_ZBX_INT, O_OPT, P_ACT,	null,		null)
);
check_fields($fields);

/*
 * Permissions
 */
if (get_request('groupid') && !API::HostGroup()->isReadable(array($_REQUEST['groupid']))) {
	access_deny();
}
if (get_request('hostid') && !API::Host()->isReadable(array($_REQUEST['hostid']))) {
	access_deny();
}
if (get_request('triggerid') && !API::Trigger()->isReadable(array($_REQUEST['triggerid']))) {
	access_deny();
}

/*
 * Ajax
 */
if (isset($_REQUEST['favobj'])) {
	if ('filter' == $_REQUEST['favobj']) {
		CProfile::update('web.events.filter.state', $_REQUEST['favstate'], PROFILE_TYPE_INT);
	}
	// saving fixed/dynamic setting to profile
	if ('timelinefixedperiod' == $_REQUEST['favobj']) {
		if (isset($_REQUEST['favid'])) {
			CProfile::update('web.events.timelinefixed', $_REQUEST['favid'], PROFILE_TYPE_INT);
		}
	}
}

if ($page['type'] == PAGE_TYPE_JS || $page['type'] == PAGE_TYPE_HTML_BLOCK) {
	require_once dirname(__FILE__).'/include/page_footer.php';
	exit();
}

/*
 * Filter
 */
if (isset($_REQUEST['filter_rst'])) {
	$_REQUEST['triggerid'] = 0;
}

$source = getRequest('source', CProfile::get('web.events.source', EVENT_SOURCE_TRIGGERS));

$_REQUEST['triggerid'] = ($source == EVENT_SOURCE_DISCOVERY)
	? 0
	: getRequest('triggerid', CProfile::get('web.events.filter.triggerid', 0));

// change triggerId filter if change hostId
if ($_REQUEST['triggerid'] > 0 && isset($_REQUEST['hostid'])) {
	$hostid = get_request('hostid');

	$oldTriggers = API::Trigger()->get(array(
		'output' => array('triggerid', 'description', 'expression'),
		'selectHosts' => array('hostid', 'host'),
		'selectItems' => array('itemid', 'hostid', 'key_', 'type', 'flags', 'status'),
		'selectFunctions' => API_OUTPUT_EXTEND,
		'triggerids' => $_REQUEST['triggerid']
	));

	foreach ($oldTriggers as $oldTrigger) {
		$_REQUEST['triggerid'] = 0;
		$oldTrigger['hosts'] = zbx_toHash($oldTrigger['hosts'], 'hostid');
		$oldTrigger['items'] = zbx_toHash($oldTrigger['items'], 'itemid');
		$oldTrigger['functions'] = zbx_toHash($oldTrigger['functions'], 'functionid');
		$oldExpression = triggerExpression($oldTrigger);

		if (isset($oldTrigger['hosts'][$hostid])) {
			break;
		}

		$newTriggers = API::Trigger()->get(array(
			'output' => array('triggerid', 'description', 'expression'),
			'selectHosts' => array('hostid', 'host'),
			'selectItems' => array('itemid', 'key_'),
			'selectFunctions' => API_OUTPUT_EXTEND,
			'filter' => array('description' => $oldTrigger['description']),
			'hostids' => $hostid
		));

		foreach ($newTriggers as $newTrigger) {
			if (count($oldTrigger['items']) != count($newTrigger['items'])) {
				continue;
			}

			$newTrigger['items'] = zbx_toHash($newTrigger['items'], 'itemid');
			$newTrigger['hosts'] = zbx_toHash($newTrigger['hosts'], 'hostid');
			$newTrigger['functions'] = zbx_toHash($newTrigger['functions'], 'functionid');

			$found = false;
			foreach ($newTrigger['functions'] as $fnum => $function) {
				foreach ($oldTrigger['functions'] as $ofnum => $oldFunction) {
					// compare functions
					if (($function['function'] != $oldFunction['function']) || ($function['parameter'] != $oldFunction['parameter'])) {
						continue;
					}
					// compare that functions uses same item keys
					if ($newTrigger['items'][$function['itemid']]['key_'] != $oldTrigger['items'][$oldFunction['itemid']]['key_']) {
						continue;
					}
					// rewrite itemid so we could compare expressions
					// of two triggers form different hosts
					$newTrigger['functions'][$fnum]['itemid'] = $oldFunction['itemid'];
					$found = true;

					unset($oldTrigger['functions'][$ofnum]);
					break;
				}
				if (!$found) {
					break;
				}
			}
			if (!$found) {
				continue;
			}

			// if we found same trigger we overwriting it's hosts and items for expression compare
			$newTrigger['hosts'] = $oldTrigger['hosts'];
			$newTrigger['items'] = $oldTrigger['items'];

			$newExpression = triggerExpression($newTrigger);

			if (strcmp($oldExpression, $newExpression) == 0) {
				$_REQUEST['triggerid'] = $newTrigger['triggerid'];
				$_REQUEST['filter_set'] = 1;
				break;
			}
		}
	}
}

if (isset($_REQUEST['filter_set']) || isset($_REQUEST['filter_rst'])) {
	CProfile::update('web.events.filter.triggerid', $_REQUEST['triggerid'], PROFILE_TYPE_ID);
}

CProfile::update('web.events.source', $source, PROFILE_TYPE_INT);

// page filter
if ($source == EVENT_SOURCE_TRIGGERS) {
	$pageFilter = new CPageFilter(array(
		'groups' => array(
			'monitored_hosts' => true,
			'with_monitored_triggers' => true
		),
		'hosts' => array(
			'monitored_hosts' => true,
			'with_monitored_triggers' => true
		),
		'triggers' => array(),
		'hostid' => get_request('hostid', null),
		'groupid' => get_request('groupid', null),
		'triggerid' => get_request('triggerid', null)
	));
	$_REQUEST['groupid'] = $pageFilter->groupid;
	$_REQUEST['hostid'] = $pageFilter->hostid;
	if ($pageFilter->triggerid > 0) {
		$_REQUEST['triggerid'] = $pageFilter->triggerid;
	}
}

$eventsWidget = new CWidget();

// header
$frmForm = new CForm();
if (isset($_REQUEST['source'])) {
	$frmForm->addVar('source', $_REQUEST['source'], 'source_csv');
}
if (isset($_REQUEST['stime'])) {
	$frmForm->addVar('stime', $_REQUEST['stime'], 'stime_csv');
}
if (isset($_REQUEST['period'])) {
	$frmForm->addVar('period', $_REQUEST['period'], 'period_csv');
}
$frmForm->addVar('page', getPageNumber(), 'page_csv');
if ($source == EVENT_SOURCE_TRIGGERS) {
	if (getRequest('triggerid') != 0) {
		$frmForm->addVar('triggerid', $_REQUEST['triggerid'], 'triggerid_csv');
	}
	else {
		$frmForm->addVar('groupid', $_REQUEST['groupid'], 'groupid_csv');
		$frmForm->addVar('hostid', $_REQUEST['hostid'], 'hostid_csv');
	}
}
$eventsWidget->addPageHeader(
	_('HISTORY OF EVENTS').SPACE.'['.zbx_date2str(_('d M Y H:i:s')).']',
	array(
		$frmForm,
		SPACE,
		get_icon('fullscreen', array('fullscreen' => $_REQUEST['fullscreen']))
	)
);

$r_form = new CForm('get');
$r_form->addVar('fullscreen', $_REQUEST['fullscreen']);
$r_form->addVar('stime', get_request('stime'));
$r_form->addVar('period', get_request('period'));

// add host and group filters to the form
if ($source == EVENT_SOURCE_TRIGGERS) {
	if (getRequest('triggerid') != 0) {
		$r_form->addVar('triggerid', get_request('triggerid'));
	}

	$r_form->addItem(array(
		_('Group').SPACE,
		$pageFilter->getGroupsCB(true)
	));
	$r_form->addItem(array(
		SPACE._('Host').SPACE,
		$pageFilter->getHostsCB(true)
	));
}

$eventsWidget->addHeader(_('Events Timeline'), $r_form);

$filterForm = null;

if ($source == EVENT_SOURCE_TRIGGERS) {
	$filterForm = new CFormTable(null, null, 'get');
	$filterForm->setAttribute('name', 'zbx_filter');
	$filterForm->setAttribute('id', 'zbx_filter');
	$filterForm->addVar('triggerid', get_request('triggerid'));
	$filterForm->addVar('stime', get_request('stime'));
	$filterForm->addVar('period', get_request('period'));

	if (isset($_REQUEST['triggerid']) && $_REQUEST['triggerid'] > 0) {
		$dbTrigger = API::Trigger()->get(array(
			'triggerids' => $_REQUEST['triggerid'],
			'output' => array('description', 'expression'),
			'selectHosts' => array('name'),
			'preservekeys' => true,
			'expandDescription' => true
		));
		if ($dbTrigger) {
			$dbTrigger = reset($dbTrigger);
			$host = reset($dbTrigger['hosts']);

			$trigger = $host['name'].NAME_DELIMITER.$dbTrigger['description'];
		}
		else {
			$_REQUEST['triggerid'] = 0;
		}
	}
	if (!isset($trigger)) {
		$trigger = '';
	}

	$filterForm->addRow(new CRow(array(
		new CCol(_('Trigger'), 'form_row_l'),
		new CCol(array(
			new CTextBox('trigger', $trigger, 96, 'yes'),
			new CButton('btn1', _('Select'),
				'return PopUp("popup.php?'.
					'dstfrm='.$filterForm->getName().
					'&dstfld1=triggerid'.
					'&dstfld2=trigger'.
					'&srctbl=triggers'.
					'&srcfld1=triggerid'.
					'&srcfld2=description'.
					'&real_hosts=1'.
					'&monitored_hosts=1'.
					'&with_monitored_triggers=1'.
					($_REQUEST['hostid'] ? '&only_hostid='.$_REQUEST['hostid'] : '').
					'");',
				'T'
			)
		), 'form_row_r')
	)));

	$filterForm->addItemToBottomRow(new CSubmit('filter_set', _('Filter')));
	$filterForm->addItemToBottomRow(new CButton('filter_rst', _('Reset'),
		'javascript: var uri = new Curl(location.href); uri.setArgument("filter_rst", 1); location.href = uri.getUrl();'));
}

$eventsWidget->addFlicker($filterForm, CProfile::get('web.events.filter.state', 0));

$scroll = new CDiv();
$scroll->setAttribute('id', 'scrollbar_cntr');
$eventsWidget->addFlicker($scroll, CProfile::get('web.events.filter.state', 0));

/*
 * Display
 */
$content = array();

// trigger events
if ($source == EVENT_OBJECT_TRIGGER) {
	$sourceName = 'trigger';

	$firstEvent = API::Event()->get(array(
		'source' => EVENT_SOURCE_TRIGGERS,
		'object' => EVENT_OBJECT_TRIGGER,
		'output' => API_OUTPUT_EXTEND,
		'objectids' => !empty($_REQUEST['triggerid']) ? $_REQUEST['triggerid'] : null,
		'sortfield' => array('clock'),
		'sortorder' => ZBX_SORT_UP,
		'limit' => 1
	));
	$firstEvent = reset($firstEvent);
}

if (isset($_REQUEST['period'])) {
	$_REQUEST['period'] = get_request('period', ZBX_PERIOD_DEFAULT);
	CProfile::update('web.events.'.$sourceName.'.period', $_REQUEST['period'], PROFILE_TYPE_INT);
}
else {
	$_REQUEST['period'] = CProfile::get('web.events.'.$sourceName.'.period');
}

$effectiveperiod = navigation_bar_calc();
$from = zbxDateToTime($_REQUEST['stime']);
$till = $from + $effectiveperiod;

if (!$firstEvent) {
	$starttime = null;
	$events = array();
}
else {
	$config = select_config();
	$starttime = $firstEvent['clock'];

	if ($pageFilter->hostsSelected) {
		$knownTriggerIds = array();
		$validTriggerIds = array();

		$triggerOptions = array(
			'output' => array('triggerid'),
			'nodeids' => get_current_nodeid(),
			'preservekeys' => true,
			'monitored' => true
		);

		$allEventsSliceLimit = $config['search_limit'];

		$eventOptions = array(
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'nodeids' => get_current_nodeid(),
			'time_from' => $from,
			'time_till' => $till,
			'output' => array('eventid', 'objectid'),
			'sortfield' => array('clock', 'eventid'),
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => $allEventsSliceLimit + 1
		);

		if (getRequest('triggerid')) {
			$filterTriggerIds = array(getRequest('triggerid'));
			$knownTriggerIds = array_combine($filterTriggerIds, $filterTriggerIds);
			$validTriggerIds = $knownTriggerIds;

			$eventOptions['objectids'] = $filterTriggerIds;
		}
		elseif ($pageFilter->hostid > 0) {
			$hostTriggers = API::Trigger()->get(array(
				'output' => array('triggerid'),
				'nodeids' => get_current_nodeid(),
				'hostids' => $pageFilter->hostid,
				'monitored' => true,
				'preservekeys' => true
			));
			$filterTriggerIds = array_map('strval', array_keys($hostTriggers));
			$knownTriggerIds = array_combine($filterTriggerIds, $filterTriggerIds);
			$validTriggerIds = $knownTriggerIds;

			$eventOptions['hostids'] = $pageFilter->hostid;
			$eventOptions['objectids'] = $validTriggerIds;
		}
		elseif ($pageFilter->groupid > 0) {
			$eventOptions['groupids'] = $pageFilter->groupid;

			$triggerOptions['groupids'] = $pageFilter->groupid;
		}

		$events = array();

		while (true) {
			$allEventsSlice = API::Event()->get($eventOptions);

			$triggerIdsFromSlice = array_keys(array_flip(zbx_objectValues($allEventsSlice, 'objectid')));

			$unknownTriggerIds = array_diff($triggerIdsFromSlice, $knownTriggerIds);

			if ($unknownTriggerIds) {
				$triggerOptions['triggerids'] = $unknownTriggerIds;
				$validTriggersFromSlice = API::Trigger()->get($triggerOptions);

				foreach ($validTriggersFromSlice as $trigger) {
					$validTriggerIds[$trigger['triggerid']] = $trigger['triggerid'];
				}

				foreach ($unknownTriggerIds as $id) {
					$id = strval($id);
					$knownTriggerIds[$id] = $id;
				}
			}

			foreach ($allEventsSlice as $event) {
				if (isset($validTriggerIds[$event['objectid']])) {
					$events[] = array('eventid' => $event['eventid']);
				}
			}

			// break loop when either enough events have been retrieved, or last slice was not full
			if (count($events) >= $config['search_limit'] || count($allEventsSlice) <= $allEventsSliceLimit) {
				break;
			}

			/*
			 * Because events in slices are sorted descending by eventid (i.e. bigger eventid),
			 * first event in next slice must have eventid that is previous to last eventid in current slice.
			 */
			$lastEvent = end($allEventsSlice);
			$eventOptions['eventid_till'] = $lastEvent['eventid'] - 1;
		}

		/*
		 * At this point it is possible that more than $config['search_limit'] events are selected,
		 * therefore at most only first $config['search_limit'] + 1 events will be used for pagination.
		 */
		$events = array_slice($events, 0, $config['search_limit'] + 1);

		// query event with extend data
		$events = API::Event()->get(array(
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'nodeids' => get_current_nodeid(),
			'eventids' => zbx_objectValues($events, 'eventid'),
			'output' => API_OUTPUT_EXTEND,
			'select_acknowledges' => API_OUTPUT_COUNT,
			'sortfield' => array('clock', 'eventid'),
			'sortorder' => ZBX_SORT_DOWN,
			'nopermissions' => true
		));

		$triggers = API::Trigger()->get(array(
			'triggerids' => zbx_objectValues($events, 'objectid'),
			'selectHosts' => array('hostid'),
			'selectItems' => array('itemid', 'hostid', 'name', 'key_', 'value_type'),
			'output' => array('description', 'expression', 'priority', 'flags', 'url')
		));
		$triggers = zbx_toHash($triggers, 'triggerid');

		// fetch hosts
		$hosts = array();
		foreach ($triggers as $trigger) {
			$hosts[] = reset($trigger['hosts']);
		}
		$hostids = zbx_objectValues($hosts, 'hostid');
		$hosts = API::Host()->get(array(
			'output' => array('name', 'hostid', 'status'),
			'hostids' => $hostids,
			'selectScreens' => API_OUTPUT_COUNT,
			'preservekeys' => true
		));

		// fetch scripts for the host JS menu
		if ($_REQUEST['hostid'] == 0) {
			$scripts = API::Script()->getScriptsByHosts($hostids);
		}

		// events
		foreach ($events as $event) {
			$trigger = $triggers[$event['objectid']];

			$host = reset($trigger['hosts']);
			$host = $hosts[$host['hostid']];

			$description = CMacrosResolverHelper::resolveEventDescription(zbx_array_merge($trigger, array(
				'clock' => $event['clock'],
				'ns' => $event['ns']
			)));

			$endclock = ($nextEvent = get_next_event($event, $events))
				? $nextEvent['clock']
				: time();

			if ($event['value'] == 1) {
			    array_push($content, array(
				'content' => $description,
				'group'   => $host['name'],
				'start'   => $event['clock'] * 1000,
				'end'     => $endclock * 1000
			    ));
			};
		}
	}
	else {
		$events = array();
	}
}

$chart = new CDiv();
$chart->setAttribute('id', 'tlchart');
$eventsWidget->addItem($chart);

$timeline = array(
	'period' => $effectiveperiod,
	'starttime' => date(TIMESTAMP_FORMAT, $starttime),
	'usertime' => date(TIMESTAMP_FORMAT, $till)
);

$objData = array(
	'id' => 'timeline_1',
	'loadSBox' => 0,
	'loadImage' => 0,
	'loadScroll' => 1,
	'dynamic' => 0,
	'mainObject' => 1,
	'periodFixed' => CProfile::get('web.events.timelinefixed', 1),
	'sliderMaximumTimePeriod' => ZBX_MAX_PERIOD
);

zbx_add_post_js('jqBlink.blink();');
zbx_add_post_js('timeControl.addObject("scroll_events_id", '.zbx_jsvalue($timeline).', '.zbx_jsvalue($objData).');');
zbx_add_post_js('timeControl.processObjects();');
zbx_add_post_js('drawVisualization();');

$eventsWidget->show();
?>
<script type="text/javascript">
    var timeline;
    var data;
    function drawVisualization() {
	data = <?php echo json_encode($content) ?>;
	var options = {
	    width:  "100%",
	    height: "auto",
	    layout: "box",
	    editable: true,
	    eventMargin: 5,
	    eventMarginAxis: 0,
	    showMajorLabels: false,
	    axisOnTop: true,
	    groupsChangeable : true,
	    groupsOnRight: true,
	    cluster: true,
	    stackEvents: true
	};
	timeline = new links.Timeline(document.getElementById('tlchart'), options);
	timeline.draw(data);
    }
</script>
<?php
require_once dirname(__FILE__).'/include/page_footer.php';
