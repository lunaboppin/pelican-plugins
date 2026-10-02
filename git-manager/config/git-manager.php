<?php

return [
    // Where the node gateway (pelican-vscode-gateway) is reachable from the panel.
    'gateway_url' => env('GIT_MANAGER_GATEWAY_URL', rtrim(config('app.url'), '/') . '/vscode-gw'),

    // Per-node overrides, keyed by node id.
    'nodes' => [
        // 2 => 'https://node2.example.com/vscode-gw',
    ],

    // Lifetime of the single-use operation token, in seconds.
    'token_ttl' => 60,

    // How long pulls and clones may take, in seconds.
    'timeout' => 290,
];
