<?php

namespace GitManager;

/**
 * Subuser permissions added by this plugin. Server owners have all of them and
 * root admins bypass permission checks; subusers need them granted explicitly.
 */
final class Permissions
{
    public const VIEW = 'git.view';

    public const PULL = 'git.pull';

    public const MANAGE = 'git.manage';
}
