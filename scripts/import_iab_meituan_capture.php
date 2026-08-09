#!/usr/bin/env php
<?php
declare(strict_types=1);

// A local JSON file cannot prove that its response metadata came from the
// authorized live browser session. Keep this legacy entry fail-closed until a
// server-issued, single-use capture handle can be verified in-process.
$output = [
    'status' => 'blocked',
    'verification_status' => 'user_provided_unverified',
    'reason' => 'controlled_live_capture_handle_required',
    'message' => 'Offline IAB JSON import is disabled. Use the bound browser Profile collection path.',
    'normalized_count' => 0,
    'saved_count' => 0,
    'readback_count' => 0,
    'readback_verified' => false,
    'collection_result' => [
        'status' => 'blocked',
        'claim' => [
            'allowed' => false,
            'reason_codes' => [
                'user_provided_unverified',
                'controlled_live_capture_handle_required',
            ],
        ],
    ],
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(1);
