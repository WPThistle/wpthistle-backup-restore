<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Admin;

// 2026-11-06: Dock-page nag-banner filter (keep_important_notices()). Real
// nag banner markup captured from a live site is used for the primary
// case -- a third-party plugin's upsell notice carrying
// 'notice notice-info <vendor>-pro-notice' -- so this isn't guessing at
// what an upsell banner looks like.

function wpguard_royal_mcp_pro_nag_fixture() {
	return '<div class="notice notice-info royal-mcp-pro-notice" style="padding: 14px 18px;">'
		. '<p><strong>See everything your AI agent changed this week, and undo any of it.</strong></p>'
		. '<p><a href="https://royalplugins.com/royally/" class="button button-primary">Compare Pro Plans Now</a></p>'
		. '</div>';
}

function test_keep_important_notices_drops_real_upsell_nag() {
	wpguard_assert_equals( '', Admin::keep_important_notices( wpguard_royal_mcp_pro_nag_fixture() ), 'a real-world upsell notice (notice-info, no error/warning class) must be dropped entirely' );
}

function test_keep_important_notices_keeps_notice_error() {
	$html = '<div class="notice notice-error"><p>Backup failed.</p></div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Backup failed.' ), 'a notice-error block must survive the filter' );
}

function test_keep_important_notices_keeps_notice_warning() {
	$html = '<div class="notice notice-warning"><p>Disk space low.</p></div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Disk space low.' ), 'a notice-warning block must survive the filter -- warnings are never allowed to be silently dropped' );
}

function test_keep_important_notices_keeps_legacy_bare_error_class() {
	$html = '<div class="error"><p>Old-style fatal.</p></div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Old-style fatal.' ), 'the pre-notice-* legacy bare "error" class (still used by some plugins) must be kept' );
}

function test_keep_important_notices_drops_legacy_bare_updated_class() {
	$html = '<div class="updated"><p>Settings saved.</p></div>';
	wpguard_assert_equals( '', Admin::keep_important_notices( $html ), 'the legacy bare "updated" (success) class is not an error/warning and must be dropped' );
}

function test_keep_important_notices_keeps_core_update_nag() {
	$html = '<div class="update-nag"><p>Please update WordPress.</p></div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Please update WordPress.' ), 'core\'s own update-nag (used for WP core / PHP-EOL nags) must never be filtered out' );
}

function test_keep_important_notices_handles_mixed_blocks() {
	$html = wpguard_royal_mcp_pro_nag_fixture() . '<div class="notice notice-error"><p>Real problem.</p></div>';
	$result = Admin::keep_important_notices( $html );
	wpguard_assert( ! str_contains( $result, 'Compare Pro Plans Now' ) && str_contains( $result, 'Real problem.' ), 'when an upsell nag and a real error render on the same page, only the error must survive' );
}

function test_keep_important_notices_fails_open_on_malformed_html() {
	$malformed = '<div class="notice notice-error"><p>unclosed';
	wpguard_assert( str_contains( Admin::keep_important_notices( $malformed ), 'unclosed' ), 'malformed markup must pass through untouched rather than risk dropping a real notice' );
}

function test_keep_important_notices_preserves_utf8() {
	$html = '<div class="notice notice-error"><p>Café — naïve 日本語</p></div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Café — naïve 日本語' ), 'non-ASCII text in a kept notice must round-trip intact, not get mangled by the HTML parser' );
}

function test_keep_important_notices_returns_empty_string_for_empty_input() {
	wpguard_assert_equals( '', Admin::keep_important_notices( '' ), 'empty input must return empty output, not error' );
}

// Security audit (Vex, 2026-11-06) MEDIUM-DOCK-01: the original allowlist
// design silently dropped a real warning/error whenever malformed markup
// from some other notice caused DOMDocument's HTML-repair to restructure
// the tree so the real notice wasn't a direct child of the wrap element.
// These four reproduce the cases Vex proved live on PHP 8.3.

function test_keep_important_notices_does_not_lose_error_nested_inside_unclosed_nag() {
	$html = '<div class="notice notice-info">Buy Pro<div class="notice notice-error">Real problem</div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Real problem' ), 'Vex Case A: an unclosed promotional div must never take a real error nested inside it down with it' );
}

function test_keep_important_notices_does_not_lose_error_pushed_outside_wrap_by_stray_closing_tag() {
	$html = '</div><div class="notice notice-error">Escaped real error</div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Escaped real error' ), 'Vex Case B: a stray closing tag ahead of a real error must never cause it to be silently lost' );
}

function test_keep_important_notices_keeps_classless_raw_php_warning_text() {
	$html = '<br /><b>Warning</b>: Undefined variable $foo in <b>bar.php</b> on line <b>12</b><br />';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Undefined variable' ), 'Vex Case C: raw PHP warning/deprecation output (no class to match anything against) must be kept by default, not dropped for lacking a recognized class' );
}

function test_keep_important_notices_does_not_lose_error_sibling_of_id_colliding_notice() {
	$html = '<div class="notice notice-info" id="wpthistle-notice-wrap">Buy Pro</div><div class="notice notice-error">Sibling real error</div>';
	wpguard_assert( str_contains( Admin::keep_important_notices( $html ), 'Sibling real error' ), 'Vex Case D: a notice colliding with this filter\'s own synthetic wrap id must never cause a sibling real error to be lost' );
}

return array(
	'test_keep_important_notices_drops_real_upsell_nag',
	'test_keep_important_notices_keeps_notice_error',
	'test_keep_important_notices_keeps_notice_warning',
	'test_keep_important_notices_keeps_legacy_bare_error_class',
	'test_keep_important_notices_drops_legacy_bare_updated_class',
	'test_keep_important_notices_keeps_core_update_nag',
	'test_keep_important_notices_handles_mixed_blocks',
	'test_keep_important_notices_fails_open_on_malformed_html',
	'test_keep_important_notices_preserves_utf8',
	'test_keep_important_notices_returns_empty_string_for_empty_input',
	'test_keep_important_notices_does_not_lose_error_nested_inside_unclosed_nag',
	'test_keep_important_notices_does_not_lose_error_pushed_outside_wrap_by_stray_closing_tag',
	'test_keep_important_notices_keeps_classless_raw_php_warning_text',
	'test_keep_important_notices_does_not_lose_error_sibling_of_id_colliding_notice',
);
