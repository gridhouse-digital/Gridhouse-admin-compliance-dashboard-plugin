<?php
/**
 * Child process for tests/browser-redesign-bulk.cjs: answers one bulk reminder AJAX request with the plugin's
 * real GHCA_ACD_Bulk_Messaging and messaging service over the in-memory repository in
 * tests/fixtures/bulk-messaging-env.php (state in GHCA_BULK_STATE). Mocked delivery; nothing is sent.
 *
 *   php tests/bulk-ajax-runner.php '<json POST body>'
 */
require __DIR__ . '/fixtures/bulk-messaging-env.php';
ghca_bulk_load();
$post = json_decode( $argv[1] ?? '{}', true ) ?: array();
$r    = ghca_bulk_ajax( (string) ( $post['action'] ?? '' ), $post );
ghca_bulk_save();
http_response_code( $r['status'] ?: 200 );
echo json_encode( $r['payload'] );
