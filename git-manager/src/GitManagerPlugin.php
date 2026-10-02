<?php

namespace GitManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use GitManager\Filament\Pages\GitManager;

class GitManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'git-manager';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([GitManager::class]);
    }

    public function boot(Panel $panel): void {}
}
