<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Monthly_Trigger;

function test_numeric_day_is_due_only_on_that_day_of_month() {
	$tz = new \DateTimeZone( 'UTC' );
	$on_day = ( new \DateTimeImmutable( '2026-11-17 10:00:00', $tz ) )->getTimestamp();
	$off_day = ( new \DateTimeImmutable( '2026-11-18 10:00:00', $tz ) )->getTimestamp();
	wpguard_assert( Monthly_Trigger::is_due_today( 17, $on_day, $tz ), 'day 17 must be due on the 17th' );
	wpguard_assert( false === Monthly_Trigger::is_due_today( 17, $off_day, $tz ), 'day 17 must not be due on the 18th' );
}

function test_numeric_day_beyond_a_shorter_month_never_matches_that_month() {
	$tz = new \DateTimeZone( 'UTC' );
	// April has 30 days -- day 31 can never be due in April.
	foreach ( range( 1, 30 ) as $d ) {
		$ts = ( new \DateTimeImmutable( "2026-04-{$d} 10:00:00", $tz ) )->getTimestamp();
		wpguard_assert( false === Monthly_Trigger::is_due_today( 31, $ts, $tz ), "day 31 must never be due in April (checked the {$d}th)" );
	}
}

function test_last_day_option_matches_the_actual_final_day_of_varying_month_lengths() {
	$tz = new \DateTimeZone( 'UTC' );
	$cases = array(
		'2026-04-30' => true,  // April, 30 days.
		'2026-04-29' => false,
		'2027-02-28' => true,  // 2027 is not a leap year.
		'2027-02-27' => false,
		'2028-02-29' => true,  // 2028 IS a leap year.
		'2028-02-28' => false,
		'2026-12-31' => true,
	);
	foreach ( $cases as $date => $expected ) {
		$ts = ( new \DateTimeImmutable( "{$date} 10:00:00", $tz ) )->getTimestamp();
		wpguard_assert( $expected === Monthly_Trigger::is_due_today( 'last', $ts, $tz ), "'last' due-ness for {$date} must be " . ( $expected ? 'true' : 'false' ) );
	}
}

function test_today_reports_correct_month_key_dom_and_last_day_flag() {
	$tz = new \DateTimeZone( 'UTC' );
	$ts = ( new \DateTimeImmutable( '2026-11-30 23:59:00', $tz ) )->getTimestamp();
	$today = Monthly_Trigger::today( $ts, $tz );
	wpguard_assert_equals( '2026-11', $today['month_key'], 'month_key must be the Y-m of the given instant' );
	wpguard_assert_equals( 30, $today['dom'], 'dom must be the day-of-month' );
	wpguard_assert( true === $today['is_last_day'], '2026-11-30 is the last day of November' );
}

function test_timezone_shifts_the_month_key_near_a_month_boundary() {
	// 2026-11-30 23:30 UTC is already 2026-12-01 in Tokyo (UTC+9) -- the
	// month key/dom must reflect the SITE's timezone, same reasoning as
	// Daily_Trigger's equivalent test.
	$utc = new \DateTimeZone( 'UTC' );
	$tokyo = new \DateTimeZone( 'Asia/Tokyo' );
	$ts = ( new \DateTimeImmutable( '2026-11-30 23:30:00', $utc ) )->getTimestamp();
	$in_tokyo = Monthly_Trigger::today( $ts, $tokyo );
	wpguard_assert_equals( '2026-12', $in_tokyo['month_key'], 'the same instant must fall in the next month in a timezone far enough ahead of UTC' );
	wpguard_assert_equals( 1, $in_tokyo['dom'], 'dom must be the 1st in Tokyo even though it is still the 30th in UTC' );
}

function test_invalid_day_value_is_never_due() {
	$tz = new \DateTimeZone( 'UTC' );
	$ts = ( new \DateTimeImmutable( '2026-11-17 10:00:00', $tz ) )->getTimestamp();
	wpguard_assert( false === Monthly_Trigger::is_due_today( 0, $ts, $tz ), 'day 0 is out of range and must never be due' );
	wpguard_assert( false === Monthly_Trigger::is_due_today( 32, $ts, $tz ), 'day 32 is out of range and must never be due' );
	wpguard_assert( false === Monthly_Trigger::is_due_today( 'not-a-day', $ts, $tz ), 'a non-numeric, non-"last" value must never be due' );
}

return array(
	'test_numeric_day_is_due_only_on_that_day_of_month',
	'test_numeric_day_beyond_a_shorter_month_never_matches_that_month',
	'test_last_day_option_matches_the_actual_final_day_of_varying_month_lengths',
	'test_today_reports_correct_month_key_dom_and_last_day_flag',
	'test_timezone_shifts_the_month_key_near_a_month_boundary',
	'test_invalid_day_value_is_never_due',
);
