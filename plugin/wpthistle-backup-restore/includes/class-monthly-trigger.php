<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure month-key/day-of-month math behind the monthly scheduled-backup
 * trigger (Phase 6: "Scheduled" mode's monthly cadence, alongside
 * Daily_Trigger's day-of-week math for the daily cadence). Same "any
 * request on the matching calendar day claims the period" philosophy as
 * Daily_Trigger -- no exact instant to miss, just one specific day out of
 * the month instead of one specific day out of the week, and the same
 * "zero activity that day means no backup that period" acceptance the owner
 * confirmed for the daily case applies here too. No WordPress function
 * calls, same testability discipline as Daily_Trigger/Settings.
 *
 * $day is either an int 1-31 (exact day-of-month -- a month shorter than
 * that day simply has no match that month, e.g. 31 in April) or the
 * string 'last' (always matches whatever the final calendar day of the
 * current month actually is -- 28/29 in February, 30 or 31 elsewhere).
 */
class Monthly_Trigger {

	/**
	 * @return array{month_key:string, dom:int, is_last_day:bool} This
	 *   instant's "Y-m" month key, day-of-month (1-31), and whether
	 *   today is the last calendar day of its month, in $tz's local
	 *   time.
	 */
	public static function today( int $now_ts, \DateTimeZone $tz ): array {
		$now = ( new \DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz );
		return array(
			'month_key'   => $now->format( 'Y-m' ),
			'dom'         => (int) $now->format( 'j' ),
			'is_last_day' => $now->format( 'j' ) === $now->format( 't' ),
		);
	}

	/** @param int|string $day An int 1-31, or the string 'last'. */
	public static function is_due_today( $day, int $now_ts, \DateTimeZone $tz ): bool {
		$today = self::today( $now_ts, $tz );
		if ( 'last' === $day ) {
			return $today['is_last_day'];
		}
		$day = is_numeric( $day ) ? (int) $day : -1;
		return $day >= 1 && $day <= 31 && $day === $today['dom'];
	}
}
