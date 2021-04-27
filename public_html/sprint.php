<?php
require_once 'header.php';
// Configuration
$domain = 'https://phabricator.wikimedia.org';
$token = '<redacted>';
$suggestedProjects = [
	'RL Module Terminators Trailblazing',
	'wikidata-campsite-iteration-∞',
	'wikidata-bridge-sprint-12',
	'Wikidata-Tainted-References-Sprint'
];
$days = 7;

$project = isset( $_REQUEST['project'] ) ? $_REQUEST['project'] : '';
$other = isset( $_REQUEST['other'] ) ? $_REQUEST['other'] : '';
if (isset($_REQUEST['days']) && $_REQUEST['days']) {
	$days = (int)$_REQUEST['days'];
} else {
	$days = 7;
}
$hasFormData = $project || $other;
?>
<script>
$(function() {
	$('.ui.dropdown').dropdown();
});
</script>
<div style="padding: 3em;">
<form action="<?php echo basename( __FILE__ ); ?>">
<label>Project</label><br>
      <div class="ui selection dropdown">
          <input type="hidden" name="project">
          <i class="dropdown icon"></i>
          <div class="default text">Suggested Projects</div>
          <div class="menu"><?php
function dropdownItems( $value ) {
	echo "<div class=\"item\" data-value=\"$value\">$value</div>";
}
foreach ( $suggestedProjects as $suggestedProject ) {
	dropdownItems( $suggestedProject );
}
?>
          </div>
      </div>
	  <br><br>
<label for="other">Other project</label><br>
  <div class="ui labeled input">
  <input style="margin-bottom: 0.5em" type="text" name="other" id="other" <?php
  if ( $other !== '' ) {
	echo 'value="' . htmlspecialchars( $other ) . '"';
  }
?>></div><br>
<label for="limit">Days</label><br>
<div class="ui labeled input">
  <input style="margin-bottom: 0.5em" id="days" name="days" type="number" min="1" max="50" required value="<?php echo htmlspecialchars( $days ); ?>">
</div>
<br>
<br>
  <button type="submit" class="ui primary button">Get the report</button>
</form>
<?php
if ( !$hasFormData ) {
    echo '<div class="ui negative message">
    <div class="header">
     No query
    </div>
	<p>You need to determine either one of the suggested projects or other projects.</p></div>';
	die();
}
if ( !$project ) {
	$project = $other;
}
$mainProject = str_replace( ' ', '_', $project );
//$mainProject = $project;
$thisMorning = min( time(), mktime( 3, 0, 0 ) );
$startTime = $thisMorning - $days * 86400;


require_once 'vendor/libphutil/src/__phutil_library_init__.php';

$client = new ConduitClient( $domain );
$client->setConduitToken( $token );

function getFullNameByPhId( $phId ) {
	global $client;
	static $names = [];

	if ( !isset( $names[$phId] ) ) {
		$params = [
			'phids' => (array)$phId,
		];
		$data = $client->callMethodSynchronous( 'phid.query', $params );
		$names[$phId] = $data[$phId]['fullName'];
	}

	return $names[$phId];
}

function isColumn( $projectPhId, $columnPhId, $columns ) {
	static $columnPhIds = [];
	if ( !isset( $columnPhIds[$projectPhId] ) && in_array( getFullNameByPhId( $columnPhId ), $columns) ) {
		$columnPhIds[$projectPhId] = $columnPhId;
	}

	return isset( $columnPhIds[$projectPhId] ) && in_array( getFullNameByPhId( $columnPhId ), $columns);
}

function getProjectPhIdsByNames( $names ) {
	global $client;

	$params = [
		'names' => (array)$names,
		'limit' => 1,
	];
	$projects = $client->callMethodSynchronous( 'project.query', $params );
	return array_keys( $projects['data'] );
}

function getProjectPhIdByAlias( $alias ) {
	global $client;
	static $names = [];
	if ( !isset( $names[$phId] ) ) {
	$params = [
		'slugs' => (array)$alias,
		'limit' => 1,
	];
	$projects = $client->callMethodSynchronous( 'project.query', $params );
	$names[$alias] =  $projects['slugMap'][$alias];
	}
	return $names[$alias];
}

function queryAllTasksData( $project, $startTime ) {
	global $client;

	$data = [];
	$after = null;

	$days = round( ( time() - $startTime ) / 86400 );

	do {
		$params = [
			'attachments' => [ 'projects' => true ],
			'constraints' => [
				'modifiedStart' => $startTime,
				'projects' => (array)$project,
			],
			'order' => 'updated',
		];
		if ( $after !== null ) {
			$params['after'] = $after;
		}

		$result = $client->callMethodSynchronous( 'maniphest.search', $params );

		$data = array_merge( $data, $result['data'] );
		$after = $result['cursor']['after'];

		if ( $after !== null ) {
			echo '…';
		}
		echo "\n";
	} while ( $after !== null );

	return $data;
}

function isTaskInProject( array $task, $projectPhId ) {
	return isset( $task['attachments']['projects']['projectPHIDs'] )
		&& in_array( $projectPhId, $task['attachments']['projects']['projectPHIDs'] );
}

function updateDateField( array &$task, $fieldName, $timestamp, $prefer = 'max' ) {
	if ( !$timestamp ) {
		return;
	}

	if ( !isset( $task[$fieldName] )
		|| ( $prefer === 'min' && $timestamp < $task[$fieldName] )
		|| ( $prefer === 'max' && $timestamp > $task[$fieldName] )
	) {
		$task[$fieldName] = $timestamp;
	}
}

$tasks = queryAllTasksData( $mainProject, $startTime );

$taskIndizes = [];
foreach ( $tasks as $index => $task ) {
	$taskIndizes[$task['id']] = $index;
}

$params = [
	'ids' => array_keys( $taskIndizes ),
];
$allTransactions = $client->callMethodSynchronous( 'maniphest.gettasktransactions', $params );
foreach ( $allTransactions as $taskId => $transactions ) {
	$transactions = array_reverse( $transactions );
	$index = $taskIndizes[$taskId];
	$task = &$tasks[$index];

	// We must consider the entire history of a task, because it might have moved to "Done" a
	// long time ago, but got closed just recently. We want to know what happaned first.
	foreach ( $transactions as $transaction ) {
		
		if ( $transaction['transactionType'] == 'core:columns' ) {
			$boardPhId = $transaction['newValue'][0]['boardPHID'];
			if ( $boardPhId != getProjectPhIdByAlias($mainProject) ) {
				continue;
			}
			$columnPhId = $transaction['newValue'][0]['columnPHID'];

			$fieldName = 'dateMovedToDone';

			if ( isColumn( $boardPhId, $columnPhId, [ 'Done', 'Test (Verification)'] ) ) {
				updateDateField( $task, $fieldName, $transaction['dateCreated'] );
			} elseif ( isset( $task[$fieldName] )
				&& $transaction['dateCreated'] > $task[$fieldName]
			) {
				// The task moved back to an other colum
				unset( $task[$fieldName] );
			}

			$fieldName = 'dateMovedToReview';
			if ( isColumn( $boardPhId, $columnPhId, [ 'Peer Review', 'Review', 'review' ] ) ) {
				updateDateField( $task, $fieldName, $transaction['dateCreated' ] );
			} elseif ( isset( $task[$fieldName] )
				&& $transaction['dateCreated'] > $task[$fieldName]
			) {
				// The task moved back to an other column
				unset( $task[$fieldName] );
			}

			$fieldName = 'dateMovedToDoing';
			if ( isColumn( $boardPhId, $columnPhId, [ 'Doing' ] ) ) {
				updateDateField( $task, $fieldName, $transaction['dateCreated' ] );
			} elseif ( isset( $task[$fieldName] )
				&& $transaction['dateCreated'] > $task[$fieldName]
			) {
				// The task moved back to an other column
				unset( $task[$fieldName] );
			}
		}

		// The tasks status changed to it's current status
		if ( $transaction['transactionType'] === 'status'
			&& $transaction['oldValue']
			&& $transaction['newValue'] === $task['fields']['status']['value']
		) {
			$fieldName = 'dateOtherResolved';

			if ( $transaction['newValue'] === 'resolved' ) {
				// The tasks status changed to "resolved"
				updateDateField( $task, $fieldName, $transaction['dateCreated'] );
			} else {
				// Other status changes, excluding new and resolved tasks
				updateDateField( $task, 'dateOtherStatusChange', $transaction['dateCreated'] );
			}
		}

		// A comment was added
		if ( $transaction['transactionType'] === 'core:comment' ) {
			updateDateField( $task, 'dateCommented', $transaction['dateCreated'] );
		}
	}

	// Either closed as "resolved" or moved to the "Done" column, whatever happened first
	if ( isset( $task['dateResolved'] ) ) {
		updateDateField( $task, 'dateResolvedOrMovedToDone', $task['dateResolved'], 'min' );
	}
	if ( isset( $task['dateMovedToDone'] ) ) {
		updateDateField( $task, 'dateResolvedOrMovedToDone', $task['dateMovedToDone'], 'min' );
	}
}

function buildSorter( $field ) {
	return function ( $a, $b ) use ( $field ) {
		if ( isset( $a[$field] ) && !isset( $b[$field] ) ) {
			return -1;
		}

		if ( !isset( $a[$field] ) && isset( $b[$field] ) ) {
			return 1;
		}

		if ( !isset( $a[$field] ) && !isset( $b[$field] )
			|| $a[$field] === $b[$field]
		) {
			return $a['id'] < $b['id'] ? -1 : 1;
		}

		return $a[$field] > $b[$field] ? -1 : 1;
	};
}

function printTasks( array $tasks, $field ) {
	global $domain,
		$startTime;

	foreach ( $tasks as $task ) {
		if ( !isset( $task[$field] )
			// We might find tasks that moved to "Done" a long time ago, but got closed
			// just recently (or the other way around). We track what happened first.
			|| $task[$field] < $startTime
		) {
			continue;
		}
		$url = $domain . '/T' . $task['id'];
		echo '<li>' . "<a href=$url>T" . $task['id'] . '</a>'
			. "\t"
			. '"' . str_replace( '"', '', $task['fields']['name'] ) . '"'
			. "</li>\n";
	}
}

function formatDate( $timestamp ) {
	$today = new DateTime();
	$today->setTime( 0, 0, 0 );

	$date = new DateTime();
	$date->setTimestamp( $timestamp );
	$date->setTime( 0, 0, 0 );

	$daysAgo = (int)$today->diff( $date )->format( '%r%a' );

	switch ( $daysAgo ) {
		case 0:
			return 'Today, ' . date( 'H:i', $timestamp );
		case -1:
			return 'Yesterday';
		default:
			return abs( $daysAgo ) . ' days ago';
	}
}

$fields = [
	'dateResolvedOrMovedToDone',
	'dateMovedToReview',
	'dateMovedToDoing',
	'dateStatusChange',
];

foreach ( $fields as $field ) {
	$title = preg_replace( '/\B(?=[A-Z])/', ' ', preg_replace( '/^date/', '', $field ) );
	?><div class="ui items">
	<div class="item">
	  <div class="content">
		<a class="header"><?php echo $title ?></a>
		<div class="description"><ul>
		  <?php
		  usort( $tasks, buildSorter( $field ) );
		  printTasks( $tasks, $field );
		  ?>
		</ul></div>
	  </div>
	</div>
  </div><?php
	
}
