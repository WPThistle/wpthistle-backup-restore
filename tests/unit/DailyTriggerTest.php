<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Daily_Trigger;

function test_empty_days_is_never_due() {
	$tz = new \DateTimeZone( 'UTC' );
	wpguard_assert( false === Daily_Trigger::is_due_today( array(), time(), $tz ), 'no days selected must mean never due' );
}

function test_selected_day_is_due() {
	$tz = new \DateTimeZone( 'UTC' );
	// 2026-10-21 is a Wednesday (dow 3).
	$ts = ( new \DateTimeImmutable( '2026-10-21 10:00:00', $tz ) )->getTimestamp();
	wpguard_assert( Daily_Trigger::is_due_today( array( 3 ), $ts, $tz ), 'Wednesday must be due when Wednesday is selected' );
	wpguard_assert( false === Daily_Trigger::is_due_today( array( 1, 5 ), $ts, $tz ), 'Wednesday must not be due when only Mon/Fri are selected' );
}

function test_today_reports_correct_date_and_dow() {
	$tz = new \DateTimeZone( 'UTC' );
	$ts = ( new \DateTimeImmutable( '2026-10-21 23:59:00', $tz ) )->getTimestamp();
	$today = Daily_Trigger::today( $ts, $tz );
	wpguard_assert_equals( '2026-10-21', $today['date'], 'date key must match the calendar date in the given timezone' );
	wpguard_assert_equals( 3, $today['dow'], 'dow must match PHP DateTime\'s own w convention (Wednesday = 3)' );
}

function test_timezone_shifts_the_calendar_date_near_midnight() {
	// 2026-10-21 23:30 UTC is already 2026-10-22 in a positive-offset zone
	// (e.g. Tokyo, UTC+9) -- the date/dow returned must reflect the SITE's
	// timezone, not UTC, since that's what an admin configuring "which
	// days" actually means.
	$utc = new \DateTimeZone( 'UTC' );
	$tokyo = new \DateTimeZone( 'Asia/Tokyo' );
	$ts = ( new \DateTimeImmutable( '2026-10-21 23:30:00', $utc ) )->getTimestamp();
	$in_tokyo = Daily_Trigger::today( $ts, $tokyo );
	wpguard_assert_equals( '2026-10-22', $in_tokyo['date'], 'the same instant must fall on the next calendar day in a timezone far enough ahead of UTC' );
	wpguard_assert_equals( 4, $in_tokyo['dow'], 'dow must be Thursday (4) in Tokyo even though it is still Wednesday in UTC' );
}

function test_dst_spring_forward_does_not_break_the_date_or_dow() {
	// America/New_York springs forward 2027-03-14 02:00 -> 03:00. An
	// instant right at the transition must still resolve to a sane local
	// calendar date/dow, not throw or silently misbehave.
	$tz = new \DateTimeZone( 'America/New_York' );
	$ts = ( new \DateTimeImmutable( '2027-03-14 06:30:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	$today = Daily_Trigger::today( $ts, $tz );
	wpguard_assert_equals( '2027-03-14', $today['date'], 'date must still be 2027-03-14 local, straddling the DST transition correctly' );
	wpguard_assert_equals( 0, $today['dow'], '2027-03-14 is a Sunday (dow 0)' );
}

function test_year_boundary_dow_is_correct() {
	$tz = new \DateTimeZone( 'UTC' );
	// 2027-01-01 is a Friday (dow 5).
	$ts = ( new \DateTimeImmutable( '2027-01-01 00:05:00', $tz ) )->getTimestamp();
	$today = Daily_Trigger::today( $ts, $tz );
	wpguard_assert_equals( '2027-01-01', $today['date'], 'date must correctly roll over the year boundary' );
	wpguard_assert_equals( 5, $today['dow'], '2027-01-01 is a Friday (dow 5)' );
}

return array(
	'test_empty_days_is_never_due',
	'test_selected_day_is_due',
	'test_today_reports_correct_date_and_dow',
	'test_timezone_shifts_the_calendar_date_near_midnight',
	'test_dst_spring_forward_does_not_break_the_date_or_dow',
	'test_year_boundary_dow_is_correct',
);
