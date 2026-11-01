<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 2 retention: keep-last-N pruning of old backup folders on
 * Nextcloud. backup_id is Ymd\THis\Z-<random suffix> (class-job-orchestrator.php
 * create_job()), so a plain lexicographic sort is also chronological
 * order -- no need to parse timestamps out of the name.
 */
class Retention {

	// Matches create_job()'s gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)).
	const BACKUP_ID_PATTERN = '/^\d{8}T\d{6}Z-[0-9a-f]{8}$/';

	/**
	 * Lists genuinely verified backup_ids under $parent_rel, oldest
	 * first (same lexicographic/chronological order as prune()'s own
	 * sort). Shared with the one-click restore feature's backup picker
	 * (Phase 2) -- both need exactly the same "what counts as a real,
	 * restorable/prunable backup" definition: name matches the real
	 * backup_id format AND a COMPLETE.json exists.
	 *
	 * @return array<string>|\WP_Error
	 */
	public static function list_verified( Nextcloud_Client $client, string $parent_rel ) {
		$children = $client->list_children( $parent_rel );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		$verified = array();
		foreach ( $children as $name ) {
			if ( ! preg_match( self::BACKUP_ID_PATTERN, $name ) ) {
				continue;
			}
			$complete = $client->propfind_size( $parent_rel . '/' . $name . '/COMPLETE.json' );
			if ( is_wp_error( $complete ) || empty( $complete['exists'] ) ) {
				continue; // Failed/interrupted job's folder -- not a real backup.
			}
			$verified[] = $name;
		}
		sort( $verified );
		return $verified;
	}

	/**
	 * Deletes every genuinely verified backup folder under $parent_rel
	 * except the $keep most recent (by lexicographic/chronological name
	 * order). A no-op if $keep is 0 (keep-all, the default) or there
	 * are $keep or fewer verified entries present. The just-finished
	 * job's own folder is always the newest verified entry and
	 * therefore always kept, by construction (no special-casing
	 * needed) -- this must only ever be called after that folder is
	 * actually complete. $parent_rel is caller-supplied (not hardcoded
	 * to any particular site/backups path here) so this can be pointed
	 * at an isolated folder in tests without touching real backups.
	 *
	 * the security audit delta audit, 2026-10-20 (RETENTION-2): a folder only counts
	 * as a real backup -- for the keep-N count, and as a deletion
	 * candidate -- if its name matches the backup_id format AND it has
	 * a COMPLETE.json (same completion signal step_finalize() itself
	 * relies on). A failed/interrupted job's folder (created by
	 * step_init() before any failure, possibly carrying only a
	 * diagnostic job-log.json) is neither counted nor deleted: not
	 * counted, so it can't silently consume one of the N "keep" slots
	 * a verified backup should occupy; not deleted, since cleaning up
	 * non-backup remnants is a separate concern from this setting.
	 * Anything else unrecognized under $parent_rel (including a
	 * mis-excluded self-entry, should list_children()'s own exclusion
	 * ever fail again -- RETENTION-1) is likewise just ignored, never
	 * a deletion candidate, as defense in depth.
	 *
	 * Phase 2 (incremental backups): a kept backup's manifest may
	 * reference bytes that physically live in an EARLIER backup's
	 * folder (see class-backup-chain.php) -- an incremental's own
	 * folder can look like an independently prunable "old" backup by
	 * name alone, while still being load-bearing for every newer
	 * backup kept in the same chain. The naive oldest-first scheme
	 * below would delete exactly the chain's base first, since it's
	 * always the oldest, breaking every incremental built on it. Before
	 * computing what's prunable, every one of the `$keep` most recent
	 * verified backups' manifests is read to find the full set of
	 * backup_ids any of them transitively depends on; that set is
	 * never deleted regardless of its own age. For a site that never
	 * uses incremental backups, every manifest's files only ever
	 * reference themselves, so this set is just the `$keep` newest
	 * backups and behaves identically to before (RETENTION-2-era
	 * tests still cover that case).
	 *
	 * @return array{deleted:array<string>, kept:int}|\WP_Error
	 */
	public static function prune( Nextcloud_Client $client, string $parent_rel, int $keep ): array|\WP_Error {
		if ( $keep <= 0 ) {
			return array( 'deleted' => array(), 'kept' => -1 );
		}

		$verified = self::list_verified( $client, $parent_rel );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$excess = count( $verified ) - $keep;
		if ( $excess <= 0 ) {
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}

		$must_keep = self::expand_chain_dependencies( $client, $parent_rel, array_slice( $verified, -$keep ) );
		if ( is_wp_error( $must_keep ) ) {
			return $must_keep;
		}

		return self::apply_deletions( $client, $parent_rel, $verified, $must_keep );
	}

	/**
	 * GFS-style tiered retention (daily/weekly/monthly buckets), the
	 * standard algorithm restic/Borg/rsnapshot use for exactly this:
	 * no fixed day-of-week/day-of-month anchor to miss (a short month or
	 * a quiet anchor day just falls back to whichever backup actually
	 * exists in that period), see tiered_keep_set()'s own docblock for
	 * the bucketing rule itself. $keep: array{daily?:int, weekly?:int,
	 * monthly?:int, timezone?:\DateTimeZone} -- timezone defaults to UTC
	 * if omitted, but callers should always pass wp_timezone() so
	 * bucketing matches the admin's own calendar, not raw UTC (backup_id
	 * itself is always stored in UTC).
	 *
	 * @return array{deleted:array<string>, kept:int}|\WP_Error Same
	 *   shape as prune() -- kept=-1 is the same "disabled" sentinel,
	 *   returned when every tier is 0.
	 */
	public static function prune_tiered( Nextcloud_Client $client, string $parent_rel, array $keep ): array|\WP_Error {
		$daily = max( 0, (int) ( $keep['daily'] ?? 0 ) );
		$weekly = max( 0, (int) ( $keep['weekly'] ?? 0 ) );
		$monthly = max( 0, (int) ( $keep['monthly'] ?? 0 ) );
		if ( 0 === $daily && 0 === $weekly && 0 === $monthly ) {
			return array( 'deleted' => array(), 'kept' => -1 );
		}

		$verified = self::list_verified( $client, $parent_rel );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$tz = isset( $keep['timezone'] ) && $keep['timezone'] instanceof \DateTimeZone ? $keep['timezone'] : new \DateTimeZone( 'UTC' );
		$seed = self::tiered_keep_set( $verified, $tz, array( 'daily' => $daily, 'weekly' => $weekly, 'monthly' => $monthly ) );
		if ( empty( $seed ) ) {
			// Every verified backup predates the oldest bucket this
			// config reaches (or there are none) -- nothing qualifies
			// for ANY tier, so nothing is kept and nothing is deleted
			// either: fail-safe, same spirit as prune()'s own
			// "excess <= 0" no-op, never interpreted as "delete
			// everything".
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}

		$must_keep = self::expand_chain_dependencies( $client, $parent_rel, $seed );
		if ( is_wp_error( $must_keep ) ) {
			return $must_keep;
		}

		return self::apply_deletions( $client, $parent_rel, $verified, $must_keep );
	}

	/**
	 * Pure. Buckets $backup_ids by calendar day/ISO week/calendar month
	 * in $tz's local time (backup_id's own embedded timestamp is always
	 * UTC -- gmdate('Ymd\THis\Z') in Job_Orchestrator::create_job() --
	 * so this converts before bucketing; grouping by raw UTC would
	 * misclassify a backup relative to what the admin, looking at their
	 * own calendar, expects). Within each bucket, only the single most
	 * recent backup_id is a tiering candidate (backup_id sorts
	 * lexicographically == chronologically, same property
	 * list_verified()/prune() already rely on). Across each tier,
	 * returns the representatives of only the N most recent distinct
	 * bucket keys. A backup_id that doesn't match the real backup_id
	 * shape at all is silently skipped (defense in depth, mirrors
	 * list_verified()'s own BACKUP_ID_PATTERN filtering -- in practice
	 * every entry here already passed that filter via list_verified()).
	 * A backup that lands in more than one tier's top-N (the common
	 * case: the newest backup is almost always daily+weekly+monthly's
	 * newest bucket all at once) is simply deduplicated by the
	 * returned set, not double-counted.
	 *
	 * @return array<string> Deduplicated backup_ids to keep.
	 */
	public static function tiered_keep_set( array $backup_ids, \DateTimeZone $tz, array $keep ): array {
		$daily_n = max( 0, (int) ( $keep['daily'] ?? 0 ) );
		$weekly_n = max( 0, (int) ( $keep['weekly'] ?? 0 ) );
		$monthly_n = max( 0, (int) ( $keep['monthly'] ?? 0 ) );

		$daily_buckets = array();
		$weekly_buckets = array();
		$monthly_buckets = array();

		foreach ( $backup_ids as $backup_id ) {
			$local = self::parse_backup_id_timestamp( $backup_id, $tz );
			if ( null === $local ) {
				continue;
			}
			// ISO-8601 week: 'o' (ISO year, NOT calendar 'Y' -- the last
			// days of December/first days of January can belong to an
			// ISO week numbered in the other year) + zero-padded 'W'.
			$day_key = $local->format( 'Y-m-d' );
			$week_key = $local->format( 'o' ) . '-W' . sprintf( '%02d', (int) $local->format( 'W' ) );
			$month_key = $local->format( 'Y-m' );

			// Within each tier's bucket, only the lexicographically
			// (== chronologically) greatest backup_id survives as that
			// bucket's representative.
			if ( ! isset( $daily_buckets[ $day_key ] ) || $backup_id > $daily_buckets[ $day_key ] ) {
				$daily_buckets[ $day_key ] = $backup_id;
			}
			if ( ! isset( $weekly_buckets[ $week_key ] ) || $backup_id > $weekly_buckets[ $week_key ] ) {
				$weekly_buckets[ $week_key ] = $backup_id;
			}
			if ( ! isset( $monthly_buckets[ $month_key ] ) || $backup_id > $monthly_buckets[ $month_key ] ) {
				$monthly_buckets[ $month_key ] = $backup_id;
			}
		}

		$keep_set = array();
		foreach ( self::top_n_bucket_values( $daily_buckets, $daily_n ) as $id ) {
			$keep_set[ $id ] = true;
		}
		foreach ( self::top_n_bucket_values( $weekly_buckets, $weekly_n ) as $id ) {
			$keep_set[ $id ] = true;
		}
		foreach ( self::top_n_bucket_values( $monthly_buckets, $monthly_n ) as $id ) {
			$keep_set[ $id ] = true;
		}
		return array_keys( $keep_set );
	}

	/** @return \DateTimeImmutable|null The backup_id's own embedded UTC instant, converted to $tz. Null if $backup_id doesn't match the real shape. */
	private static function parse_backup_id_timestamp( string $backup_id, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( ! preg_match( '/^(\d{8}T\d{6})Z-[0-9a-f]{8}$/', $backup_id, $m ) ) {
			return null;
		}
		$utc = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], new \DateTimeZone( 'UTC' ) );
		if ( false === $utc ) {
			return null;
		}
		return $utc->setTimezone( $tz );
	}

	/** @return array<string> The representative backup_ids of the $n most recent (by bucket key, descending) buckets. */
	private static function top_n_bucket_values( array $buckets, int $n ): array {
		if ( $n <= 0 || empty( $buckets ) ) {
			return array();
		}
		krsort( $buckets ); // Bucket keys are zero-padded/ISO-shaped -- string order == chronological order.
		return array_values( array_slice( $buckets, 0, $n ) );
	}

	/**
	 * Shared by prune() and prune_tiered(): expands a seed set of
	 * "definitely keep" backup_ids into the full transitive set
	 * (including anything any of them depends on via an incremental
	 * chain) that must survive deletion. See the class docblock
	 * (Phase 2, incremental backups) for why this exists at all.
	 *
	 * @return array<string,true>|\WP_Error Map of backup_id => true (a
	 *   set, not a list) for fast array_diff()/isset() use by the
	 *   caller, or a WP_Error (fail-closed) if any seed's manifest
	 *   couldn't be read.
	 */
	private static function expand_chain_dependencies( Nextcloud_Client $client, string $parent_rel, array $seed_ids ) {
		$must_keep = array();
		foreach ( $seed_ids as $backup_id ) {
			$must_keep[ $backup_id ] = true;
			$refs = self::referenced_source_backups( $client, $parent_rel, $backup_id );
			if ( is_wp_error( $refs ) ) {
				// Fail-closed: if a kept backup's own manifest can't be
				// read to determine what it depends on, nothing is
				// deleted this run rather than risk breaking it.
				return $refs;
			}
			foreach ( $refs as $ref ) {
				$must_keep[ $ref ] = true;
			}
		}
		return $must_keep;
	}

	/** @return array{deleted:array<string>, kept:int}|\WP_Error */
	private static function apply_deletions( Nextcloud_Client $client, string $parent_rel, array $verified, array $must_keep ) {
		$to_delete = array_values( array_diff( $verified, array_keys( $must_keep ) ) );
		if ( empty( $to_delete ) ) {
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}
		$deleted = array();
		foreach ( $to_delete as $name ) {
			$result = $client->delete( $parent_rel . '/' . $name );
			if ( is_wp_error( $result ) ) {
				// Best-effort: report what was actually deleted before
				// the failure, rather than losing that information by
				// returning only the error.
				return new \WP_Error(
					'wpthistle_backup_retention_delete_failed',
					sprintf( 'Deleted %d of %d old backup(s) before a delete failed: %s', count( $deleted ), count( $to_delete ), $result->get_error_message() ),
					array( 'deleted' => $deleted )
				);
			}
			$deleted[] = $name;
		}

		return array( 'deleted' => $deleted, 'kept' => count( $verified ) - count( $deleted ) );
	}

	// Generous relative to realistic file counts; matches
	// Backup_Chain::MAX_MANIFEST_BYTES.
	const MAX_MANIFEST_BYTES = 64 * 1024 * 1024;

	/**
	 * @return array<string>|\WP_Error Distinct backup_ids (excluding
	 *   $backup_id itself) that $backup_id's own manifest.json's `files`
	 *   list points at. Empty for a full backup -- every entry there
	 *   points at itself.
	 */
	private static function referenced_source_backups( Nextcloud_Client $client, string $parent_rel, string $backup_id ) {
		$manifest = $client->get_json( $parent_rel . '/' . $backup_id . '/manifest.json', self::MAX_MANIFEST_BYTES );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$sources = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $entry ) {
			$source = $entry['source_backup_id'] ?? '';
			if ( is_string( $source ) && '' !== $source && $source !== $backup_id ) {
				$sources[ $source ] = true;
			}
		}
		return array_keys( $sources );
	}
}
