<?php

return [
    // Where the node gateway (pelican-vscode-gateway) is reachable from the browser.
    'gateway_url' => env('DISK_ANALYZER_GATEWAY_URL', rtrim(config('app.url'), '/') . '/vscode-gw'),

    // Per-node overrides, keyed by node id.
    'nodes' => [
        // 2 => 'https://node2.example.com/vscode-gw',
    ],

    // Lifetime of the single-use scan token, in seconds.
    'token_ttl' => 120,
];
