<?php

return [
    // Supply STUN/TURN servers in production. Without them, WebRTC audio may work only on local networks.
    'ice_servers' => json_decode((string) env('CHAT_ICE_SERVERS_JSON', '[]'), true) ?: [],

    // Attachments are scanned by ClamAV before they are stored. Uploads are refused when the
    // scanner is unreachable. Set CHAT_VIRUS_SCAN=off only for local development.
    'virus_scan' => [
        'driver' => env('CHAT_VIRUS_SCAN', 'clamav'),
        'host' => env('CHAT_CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CHAT_CLAMAV_PORT', 3310),
        'timeout' => (int) env('CHAT_CLAMAV_TIMEOUT', 10),
    ],
];
