<?php

return [
    // Where the pelican-vscode-gateway is reachable from the browser. It must be
    // same-site with the panel so the iframe can keep its session cookie.
    'gateway_url' => env('VSCODE_FILES_GATEWAY_URL', rtrim(config('app.url'), '/') . '/vscode-gw'),

    // Per-node overrides, keyed by node id, for nodes that expose their own gateway.
    'nodes' => [
        // 2 => 'https://node2.example.com/vscode-gw',
    ],

    // SSH console (browser) and the gateway's SSH port for desktop tools.
    'ssh' => [
        'enabled' => env('VSCODE_FILES_SSH_ENABLED', true),

        // Address users connect to. Defaults to the node's SFTP alias, then its FQDN.
        // Must not be behind an HTTP-only proxy (e.g. Cloudflare's orange cloud).
        'host' => env('VSCODE_FILES_SSH_HOST'),
        'port' => (int) env('VSCODE_FILES_SSH_PORT', 2222),

        // Per-node overrides, keyed by node id.
        'nodes' => [
            // 2 => ['host' => 'node2.example.com', 'port' => 2222],
        ],
    ],

    // Lifetime of the single-use launch token, in seconds.
    'token_ttl' => 300,
];
