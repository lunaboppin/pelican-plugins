<?php

return [
    'vscode_title' => 'VS Code',
    'vscode_desc' => 'Permissions that control a user\'s access to the VS Code file manager. VS Code includes a terminal that runs in an isolated container with this server\'s files mounted.',
    'vscode_open' => 'Allows a user to open this server\'s files in VS Code. Without the write permission the files are mounted read-only.',
    'vscode_write' => 'Allows a user to create, edit and delete files from VS Code. Requires the open permission.',

    'ssh_title' => 'SSH Console',
    'ssh_desc' => 'Permissions that control a user\'s access to the browser SSH console. Only grant this when necessary: it gives a full shell with read and write access to every file on the server.',
    'ssh_connect' => 'Allows a user to open the SSH console. Other servers on the same node where the user has this permission are mounted too.',
];
