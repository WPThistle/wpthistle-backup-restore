<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Retention;

function make_backup_id( string $y_m_d_His ): string {
	// Matches Job_Orchestrator::create_job()'s real shape: gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)).
	$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $y_m_d_His, new \DateTimeZone( 'UTC' ) );
	return $dt->format( 'Ymd\THis\Z' ) . '-aaaa0001';
}

function test_keeps_most_recent_n_per_tier_independently() {
	$tz = new \DateTimeZone( 'UTC' );
	// 10 consecutive daily backups, 2026-01-01 .. 2026-01-10.
	$ids = array();
	for ( $d = 1; $d <= 10; $d++ ) {
		$ids[] = make_backup_id( sprintf( '2026-01-%02d 03:00:00', $d ) );
	}
	$kept = Retention::tiered_keep_set( $ids, $tz, array( 'daily' => 3, 'weekly' => 0, 'monthly' => 0 ) );
	sort( $kept );
	$expected = array( $ids[7], $ids[8], $ids[9] ); // Jan 8, 9, 10 -- the 3 most recent days.
	sort( $expected );
	wpguard_assert_equals( $expected, $kept, 'daily=3 with no weekly/monthly must keep exactly the 3 most recent distinct days' );
}

function test_single_backup_qualifies_for_multiple_tiers_at_once_without_duplication() {
	$tz = new \DateTimeZone( 'UTC' );
	$only = make_backup_id( '2026-06-15 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $only ), $tz, array( 'daily' => 7, 'weekly' => 4, 'monthly' => 12 ) );
	wpguard_assert_equals( array( $only ), $kept, 'a single backup qualifying for all three tiers must appear exactly once in the result, not three times' );
}

function test_short_month_anchor_is_a_non_issue_no_fixed_day_of_month() {
	$tz = new \DateTimeZone( 'UTC' );
	// February 2026 has no 29th/30th/31st -- a fixed day-of-month anchor
	// would miss it entirely. The bucket scheme has no such anchor: one
	// backup anywhere in Feb is still Feb's monthly representative.
	$jan = make_backup_id( '2026-01-15 03:00:00' );
	$feb = make_backup_id( '2026-02-20 03:00:00' );
	$mar = make_backup_id( '2026-03-05 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $jan, $feb, $mar ), $tz, array( 'daily' => 0, 'weekly' => 0, 'monthly' => 3 ) );
	sort( $kept );
	$expected = array( $jan, $feb, $mar );
	sort( $expected );
	wpguard_assert_equals( $expected, $kept, 'each distinct month must keep its one representative regardless of which day of the month it happened to land on' );
}

function test_missed_anchor_day_the_next_completed_backup_steps_in() {
	$tz = new \DateTimeZone( 'UTC' );
	// No backup on the 1st -- the 3rd's backup is the only one that
	// month, so it must still represent that month (simulates "zero
	// activity on the anchor day, the next completed backup takes its
	// place").
	$id = make_backup_id( '2026-04-03 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $id ), $tz, array( 'daily' => 0, 'weekly' => 0, 'monthly' => 1 ) );
	wpguard_assert_equals( array( $id ), $kept, 'the only backup in a month must represent that month even though it did not land on the 1st' );
}

function test_week_spanning_a_year_boundary_is_bucketed_by_iso_week_not_calendar_year() {
	$tz = new \DateTimeZone( 'UTC' );
	// 2026-12-31 (Thursday) and 2027-01-01 (Friday) fall in the SAME
	// ISO-8601 week (week 53 of ISO-year 2026) -- a plain calendar-year
	// grouping would wrongly split them into two different "weeks".
	$dec31 = make_backup_id( '2026-12-31 03:00:00' );
	$jan1 = make_backup_id( '2027-01-01 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $dec31, $jan1 ), $tz, array( 'daily' => 0, 'weekly' => 1, 'monthly' => 0 ) );
	// Only the later one (jan1, lexicographically greater) should
	// survive as that single week's representative -- if the year
	// boundary were mishandled, both would appear (two different
	// "weeks" instead of one).
	wpguard_assert_equals( array( $jan1 ), $kept, 'both instants must bucket into the same ISO week, leaving exactly one representative' );
}

function test_dst_boundary_does_not_crash_or_misbucket() {
	$tz = new \DateTimeZone( 'America/New_York' );
	// Two backups either side of the 2027-03-14 spring-forward transition.
	$before = make_backup_id( '2027-03-14 05:00:00' ); // ~00:00 EST
	$after = make_backup_id( '2027-03-14 08:00:00' ); // ~04:00 EDT, same local calendar day
	$kept = Retention::tiered_keep_set( array( $before, $after ), $tz, array( 'daily' => 1, 'weekly' => 0, 'monthly' => 0 ) );
	wpguard_assert_equals( array( $after ), $kept, 'both instants land on the same local calendar day across the DST transition, so only the later one survives as that day\'s representative' );
}

function test_non_matching_backup_id_shape_is_silently_skipped() {
	$tz = new \DateTimeZone( 'UTC' );
	$valid = make_backup_id( '2026-05-01 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $valid, 'not-a-real-backup-id', '' ), $tz, array( 'daily' => 5, 'weekly' => 0, 'monthly' => 0 ) );
	wpguard_assert_equals( array( $valid ), $kept, 'malformed entries must be ignored, never crash or get kept themselves' );
}

function test_all_tiers_zero_keeps_nothing() {
	$tz = new \DateTimeZone( 'UTC' );
	$id = make_backup_id( '2026-05-01 03:00:00' );
	$kept = Retention::tiered_keep_set( array( $id ), $tz, array( 'daily' => 0, 'weekly' => 0, 'monthly' => 0 ) );
	wpguard_assert_equals( array(), $kept, 'every tier at 0 must keep nothing from the tiering step itself' );
}

return array(
	'test_keeps_most_recent_n_per_tier_independently',
	'test_single_backup_qualifies_for_multiple_tiers_at_once_without_duplication',
	'test_short_month_anchor_is_a_non_issue_no_fixed_day_of_month',
	'test_missed_anchor_day_the_next_completed_backup_steps_in',
	'test_week_spanning_a_year_boundary_is_bucketed_by_iso_week_not_calendar_year',
	'test_dst_boundary_does_not_crash_or_misbucket',
	'test_non_matching_backup_id_shape_is_silently_skipped',
	'test_all_tiers_zero_keeps_nothing',
);
