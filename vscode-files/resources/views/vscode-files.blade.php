<x-filament-panels::page>
    @assets
    <style>
        .vsf-frame { position: relative; overflow: hidden; height: calc(100vh - 12rem); min-height: 32rem; border-radius: 0.75rem; background: #1e1e1e; box-shadow: 0 0 0 1px rgb(3 7 18 / 0.08); }
        .dark .vsf-frame { box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1); }
        .vsf-iframe { display: block; width: 100%; height: 100%; border: 0; }
        .vsf-loading { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem; font-size: 0.875rem; color: rgb(156 163 175); }
        .vsf-spinner { width: 2rem; height: 2rem; }
    </style>
    @endassets
    <div
        wire:ignore
        x-data="{ loaded: false }"
        class="vsf-frame"
    >
        <div
            x-show="!loaded"
            x-transition.opacity
            class="vsf-loading"
        >
            <x-filament::loading-indicator class="vsf-spinner" />
            <span>{{ trans('vscode-files::strings.starting') }}</span>
        </div>

        <iframe
            src="{{ $this->launchUrl }}"
            x-on:load="loaded = true"
            class="vsf-iframe"
            allow="clipboard-read; clipboard-write"
            title="{{ trans('vscode-files::strings.vscode') }}"
        ></iframe>
    </div>
</x-filament-panels::page>
