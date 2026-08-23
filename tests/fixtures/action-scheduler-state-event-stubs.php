<?php
/**
 * Focused Action Scheduler API model for isolated pricing retry tests.
 *
 * @package Digitalogic
 */

/**
 * Schedule one modeled pending action and return its numeric ID.
 *
 * @param int    $timestamp Action timestamp.
 * @param string $hook      Action hook.
 * @param array  $args      Action arguments.
 * @param string $group     Action group.
 * @param bool   $unique    Native uniqueness request.
 * @return int
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false ) {
	unset( $unique );
	$actions                                = isset( $GLOBALS['digitalogic_test_as_actions'] ) && is_array( $GLOBALS['digitalogic_test_as_actions'] )
		? $GLOBALS['digitalogic_test_as_actions']
		: array();
	$id                                     = count( $actions ) + 1;
	$actions[]                              = array(
		'id'        => $id,
		'timestamp' => (int) $timestamp,
		'hook'      => (string) $hook,
		'args'      => array_values( (array) $args ),
		'group'     => (string) $group,
		'status'    => 'pending',
	);
	$GLOBALS['digitalogic_test_as_actions'] = $actions;

	return $id;
}

/**
 * Return modeled actions matching Action Scheduler's exact public query.
 *
 * @param array  $query         Action query.
 * @param string $return_format Requested return format.
 * @return array
 */
function as_get_scheduled_actions( $query = array(), $return_format = 'objects' ) {
	$actions = isset( $GLOBALS['digitalogic_test_as_actions'] ) && is_array( $GLOBALS['digitalogic_test_as_actions'] )
		? $GLOBALS['digitalogic_test_as_actions']
		: array();
	$matches = array_values(
		array_filter(
			$actions,
			static function ( $action ) use ( $query ) {
				return ( ! isset( $query['hook'] ) || (string) $action['hook'] === (string) $query['hook'] )
					&& ( ! isset( $query['args'] ) || array_values( (array) $query['args'] ) === $action['args'] )
					&& ( ! isset( $query['group'] ) || (string) $action['group'] === (string) $query['group'] )
					&& ( ! isset( $query['status'] ) || (string) $action['status'] === (string) $query['status'] );
			}
		)
	);
	if ( isset( $query['per_page'] ) && 0 < (int) $query['per_page'] ) {
		$matches = array_slice( $matches, 0, (int) $query['per_page'] );
	}

	return 'ids' === $return_format ? array_column( $matches, 'id' ) : $matches;
}
