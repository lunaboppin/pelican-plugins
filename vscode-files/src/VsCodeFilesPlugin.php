<?php

namespace VsCodeFiles;

use Filament\Contracts\Plugin;
use Filament\Panel;
use VsCodeFiles\Filament\Pages\SshDetails;
use VsCodeFiles\Http\Middleware\RedirectToPreferredFileManager;

class VsCodeFilesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'vscode-files';
    }

    public function register(Panel $panel): void
    {
        // Tenant middleware runs after auth and tenant resolution, so user() and the server are available.
        $panel->tenantMiddleware([RedirectToPreferredFileManager::class]);

        $panel->pages([SshDetails::class]);
    }

    public function boot(Panel $panel): void {}
}
