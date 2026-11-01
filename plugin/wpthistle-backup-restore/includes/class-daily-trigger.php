<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure day-of-week/date-key math behind the day-based backup trigger
 * (replaces the old Scheduler::next_run() exact-instant model -- see
 * wpthistle-backup-restore.php's maybe_run_daily_backup()). An exact
 * HH:MM slot needed a request to land near one specific instant each
 * day for WP-Cron's pseudo-cron to ever pick it up; a genuinely quiet
 * site could miss that narrow window entirely. This instead asks only
 * "is today one of the selected days, and has today's backup already
 * been claimed" -- any request at any time that day is early enough.
 * No WordPress function calls, same testability discipline as
 * Settings/the old Scheduler class -- the caller passes in both the
 * instant and the site's own \DateTimeZone (wp_timezone()) rather than
 * this class reading either itself.
 */
class Daily_Trigger {

	/**
	 * @return array{date:string, dow:int} This instant's calendar date
	 *   ("Y-m-d") and day-of-week (0=Sunday..6=Saturday, PHP DateTime's
	 *   own 'w' convention) in $tz's local time.
	 */
	public static function today( int $now_ts, \DateTimeZone $tz ): array {
		$now = ( new \DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz );
		return array(
			'date' => $now->format( 'Y-m-d' ),
			'dow'  => (int) $now->format( 'w' ),
		);
	}

	/** True if $days (each 0-6) contains today's local day-of-week; false (never due) if $days is empty. */
	public static function is_due_today( array $days, int $now_ts, \DateTimeZone $tz ): bool {
		if ( empty( $days ) ) {
			return false;
		}
		return in_array( self::today( $now_ts, $tz )['dow'], $days, true );
	}
}
