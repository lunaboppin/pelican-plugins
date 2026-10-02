<?php

namespace DiskAnalyzer;

/**
 * Subuser permissions added by this plugin. Server owners have all of them and
 * root admins bypass permission checks; subusers need them granted explicitly.
 */
final class Permissions
{
    public const VIEW = 'disk.view';

    public const RESCAN = 'disk.rescan';
}
