<?php

namespace VsCodeFiles;

/**
 * Subuser permissions added by this plugin. Server owners have all of them and
 * root admins bypass permission checks; subusers need them granted explicitly.
 */
final class Permissions
{
    public const VSCODE_OPEN = 'vscode.open';

    public const VSCODE_WRITE = 'vscode.write';

    public const SSH_CONNECT = 'ssh.connect';
}
