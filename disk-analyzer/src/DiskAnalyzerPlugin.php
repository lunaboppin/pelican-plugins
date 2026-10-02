<?php

namespace DiskAnalyzer;

use DiskAnalyzer\Filament\Pages\DiskAnalyzer;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DiskAnalyzerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'disk-analyzer';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([DiskAnalyzer::class]);
    }

    public function boot(Panel $panel): void {}
}
