<?php

return [
    // Supply STUN/TURN servers in production. Without them, WebRTC audio may work only on local networks.
    'ice_servers' => json_decode((string) env('CHAT_ICE_SERVERS_JSON', '[]'), true) ?: [],
];
