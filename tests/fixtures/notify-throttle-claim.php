<?php
/**
 * Vex security audit, LOW-N-04-RESIDUAL reopened (threat-model T28,
 * 2026-11-03) regression fixture. Run via `wp eval-file
 * notify-throttle-claim.php <barrier_unix_ts>` -- many of these launched
 * as separate OS processes (each its own `docker compose run --rm`
 * container, its own PHP process, its own MySQL connection), all
 * busy-waiting until the same $barrier_unix_ts before calling the real
 * (private) Admin::claim_notify_test_throttle() via reflection, the same
 * approach Vex's own live proof used. This is what makes the race a
 * genuine simultaneous multi-process call rather than an artifact of
 * curl/HTTP scheduling jitter, which could serialize requests enough to
 * never actually exercise the race at all.
 */
$barrier = (float) $args[0];
while ( microtime( true ) < $barrier ) {
	usleep( 200 );
}

$method = new ReflectionMethod( \WPThistle\BackupRestore\Admin::class, 'claim_notify_test_throttle' );
$method->setAccessible( true );
$won = $method->invoke( null );

echo ( $won ? 'WIN' : 'LOSE' ) . ' at=' . microtime( true ) . "\n";
