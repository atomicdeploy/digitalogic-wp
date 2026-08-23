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
 * @throws RuntimeException Injected test-only adapter failure.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false ) {
	$exceptions = isset( $GLOBALS['digitalogic_test_as_schedule_exceptions'] ) && is_array( $GLOBALS['digitalogic_test_as_schedule_exceptions'] )
		? $GLOBALS['digitalogic_test_as_schedule_exceptions']
		: array();
	if ( ! empty( $exceptions ) && ( '*' === (string) $exceptions[0] || (string) $exceptions[0] === (string) $hook ) ) {
		array_shift( $exceptions );
		$GLOBALS['digitalogic_test_as_schedule_exceptions'] = $exceptions;
		throw new RuntimeException( 'Injected Action Scheduler insertion failure.' );
	}
	$actions = isset( $GLOBALS['digitalogic_test_as_actions'] ) && is_array( $GLOBALS['digitalogic_test_as_actions'] )
		? $GLOBALS['digitalogic_test_as_actions']
		: array();
	if ( $unique ) {
		foreach ( $actions as $action ) {
			if (
				(string) $action['hook'] === (string) $hook
				&& (string) $action['group'] === (string) $group
				&& in_array( (string) $action['status'], array( 'pending', 'in-progress' ), true )
			) {
				return 0;
			}
		}
	}
	$before_schedule = $GLOBALS['digitalogic_test_as_before_schedule'] ?? null;

	$GLOBALS['digitalogic_test_as_before_schedule'] = null;
	if ( is_callable( $before_schedule ) ) {
		call_user_func( $before_schedule, $timestamp, $hook, $args, $group, $unique );
	}
	$actions = isset( $GLOBALS['digitalogic_test_as_actions'] ) && is_array( $GLOBALS['digitalogic_test_as_actions'] )
		? $GLOBALS['digitalogic_test_as_actions']
		: array();
	if ( $unique ) {
		foreach ( $actions as $action ) {
			if (
				(string) $action['hook'] === (string) $hook
				&& (string) $action['group'] === (string) $group
				&& in_array( (string) $action['status'], array( 'pending', 'in-progress' ), true )
			) {
				return 0;
			}
		}
	}
	$id                                     = empty( $actions ) ? 1 : 1 + max( array_column( $actions, 'id' ) );
	$actions[]                              = array(
		'id'        => $id,
		'timestamp' => (int) $timestamp,
		'hook'      => (string) $hook,
		'args'      => array_values( (array) $args ),
		'group'     => (string) $group,
		'status'    => 'pending',
		'unique'    => (bool) $unique,
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
 * @throws RuntimeException Injected test-only adapter failure.
 */
function as_get_scheduled_actions( $query = array(), $return_format = 'objects' ) {
	if ( ! empty( $GLOBALS['digitalogic_test_as_query_exceptions'] ) ) {
		--$GLOBALS['digitalogic_test_as_query_exceptions'];
		throw new RuntimeException( 'Injected Action Scheduler query failure.' );
	}
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
