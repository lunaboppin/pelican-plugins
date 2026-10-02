<x-filament-panels::page>
    @assets
    <style>
        .gm { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
        @media (min-width: 1280px) { .gm { grid-template-columns: minmax(0, 1fr) 24rem; } }
        .gm-side { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
        .gm-repo { display: flex; align-items: center; gap: 1rem; padding: 0.85rem 0; flex-wrap: wrap; }
        .gm-repo + .gm-repo { border-top: 1px solid rgb(3 7 18 / 0.06); }
        .dark .gm-repo + .gm-repo { border-top-color: rgb(255 255 255 / 0.06); }
        .gm-repo-main { flex: 1 1 22rem; min-width: 0; }
        .gm-path { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem; word-break: break-all; }
        .gm-meta { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 0.25rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; color: rgb(107 114 128); }
        .dark .gm-meta { color: rgb(156 163 175); }
        .gm-meta span { display: inline-flex; align-items: center; gap: 0.25rem; min-width: 0; }
        .gm-remote { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 32rem; }
        .gm-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .gm-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0; font-size: 0.85rem; }
        .gm-item + .gm-item { border-top: 1px solid rgb(3 7 18 / 0.06); }
        .dark .gm-item + .gm-item { border-top-color: rgb(255 255 255 / 0.06); }
        .gm-item-main { flex: 1 1 auto; min-width: 0; }
        .gm-item-sub { font-size: 0.75rem; color: rgb(107 114 128); }
        .dark .gm-item-sub { color: rgb(156 163 175); }
        .gm-empty { display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1.5rem 0; text-align: center; font-size: 0.875rem; color: rgb(107 114 128); }
        .gm-badges, .gm-head-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .gm-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; }
        .gm-strong { font-weight: 500; }
        .gm-error { color: rgb(239 68 68); }
        .gm-ok { color: rgb(22 163 74); }
        .gm-dim { opacity: 0.6; transition: opacity 150ms; }
        .gm-icon-xs { width: 0.875rem; height: 0.875rem; flex-shrink: 0; }
        .gm-icon-sm { width: 1rem; height: 1rem; flex-shrink: 0; color: rgb(156 163 175); }
        .gm-spinner { width: 1.25rem; height: 1.25rem; }
        .gm-list-empty { padding-bottom: 0.75rem; }
        .gm-section-foot { margin-top: 0.75rem; }
        .gm-section-foot > * { width: 100%; }

        /* Modal content */
        .gm-modal { display: flex; flex-direction: column; gap: 0.9rem; font-size: 0.875rem; }
        .gm-help { color: rgb(107 114 128); line-height: 1.5; }
        .dark .gm-help { color: rgb(156 163 175); }
        .gm-label { font-weight: 500; margin-bottom: 0.35rem; }
        .gm-code-row { display: flex; align-items: stretch; gap: 0.5rem; }
        .gm-code { flex: 1 1 auto; min-width: 0; margin: 0; padding: 0.6rem 0.75rem; border-radius: 0.5rem; background: rgb(3 7 18 / 0.05); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; line-height: 1.5; white-space: pre-wrap; word-break: break-all; }
        .dark .gm-code { background: rgb(255 255 255 / 0.05); }
        .gm-code.is-single { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; word-break: normal; }
        .gm-copy { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; min-width: 5.5rem; padding: 0 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: rgb(55 65 81); background: #fff; box-shadow: 0 0 0 1px rgb(3 7 18 / 0.1); }
        .gm-copy:hover { background: rgb(249 250 251); }
        .dark .gm-copy { color: rgb(229 231 235); background: rgb(255 255 255 / 0.05); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1); }
        .dark .gm-copy:hover { background: rgb(255 255 255 / 0.1); }
        .gm-meta-line { font-size: 0.75rem; color: rgb(107 114 128); }
        .gm-commits { list-style: none; margin: 0; padding: 0; }
        .gm-commit { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.55rem 0; }
        .gm-commit + .gm-commit { border-top: 1px solid rgb(3 7 18 / 0.06); }
        .dark .gm-commit + .gm-commit { border-top-color: rgb(255 255 255 / 0.08); }
        .gm-sha { flex-shrink: 0; padding: 0.1rem 0.4rem; border-radius: 0.3rem; background: rgb(3 7 18 / 0.05); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
        .dark .gm-sha { background: rgb(255 255 255 / 0.08); }
        .gm-commit-main { flex: 1 1 auto; min-width: 0; }
        .gm-commit-subject { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .gm-output { margin: 0.25rem 0 0; max-height: 15rem; overflow: auto; white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
    </style>
    @endassets

    <div class="gm" wire:init="scan">
        {{-- Repositories --}}
        <x-filament::section>
            <x-slot name="heading">{{ trans('git-manager::strings.repositories') }}</x-slot>
            <x-slot name="afterHeader">
                <div class="gm-head-actions">
                    <x-filament::button size="sm" color="gray" icon="tabler-refresh" wire:click="scan" wire:loading.attr="disabled" wire:target="scan">
                        {{ trans('git-manager::strings.refresh') }}
                    </x-filament::button>
                    {{ $this->cloneAction }}
                </div>
            </x-slot>

            @if (!$scanned)
                <div class="gm-empty">
                    <x-filament::loading-indicator class="gm-spinner" />
                    {{ trans('git-manager::strings.scanning') }}
                </div>
            @elseif ($scanError)
                <div class="gm-empty gm-error">{{ $scanError }}</div>
            @elseif (empty($repos))
                <div class="gm-empty">
                    {{ trans('git-manager::strings.no_repos') }}
                    @if ($this->userCan(\GitManager\Permissions::PULL))
                        {{ trans('git-manager::strings.no_repos_hint') }}
                    @endif
                </div>
            @else
                <div wire:loading.class="gm-dim" wire:target="scan">
                    @foreach ($repos as $repo)
                        <div class="gm-repo" wire:key="repo-{{ md5($repo['path']) }}">
                            <div class="gm-repo-main">
                                <div class="gm-badges">
                                    <span class="gm-path">{{ $repo['path'] }}</span>
                                    @if ($repo['changes'] > 0)
                                        <x-filament::badge color="warning" size="sm">{{ trans_choice('git-manager::strings.changes', $repo['changes'], ['count' => $repo['changes']]) }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="info" size="sm">{{ trans('git-manager::strings.clean') }}</x-filament::badge>
                                    @endif
                                    @if ($repo['behind'] > 0)
                                        <x-filament::badge color="gray" size="sm">{{ trans('git-manager::strings.behind', ['count' => $repo['behind']]) }}</x-filament::badge>
                                    @endif
                                    @if ($repo['ahead'] > 0)
                                        <x-filament::badge color="gray" size="sm">{{ trans('git-manager::strings.ahead', ['count' => $repo['ahead']]) }}</x-filament::badge>
                                    @endif
                                </div>
                                <div class="gm-meta">
                                    <span><x-filament::icon icon="tabler-git-branch" class="gm-icon-xs" />{{ $repo['branch'] }}</span>
                                    @if ($repo['commit'])
                                        <span title="{{ $repo['updated'] ? \Illuminate\Support\Carbon::createFromTimestamp($repo['updated'])->toDayDateTimeString() : '' }}">{{ $repo['commit'] }}</span>
                                    @endif
                                    <span class="gm-remote" title="{{ $repo['remote'] }}">{{ $repo['remote'] ?: trans('git-manager::strings.no_remote') }}</span>
                                </div>
                            </div>
                            <div class="gm-actions">
                                @if ($repo['remote'])
                                    {{ ($this->pullAction)(['path' => $repo['path']]) }}
                                @endif
                                {{ ($this->historyAction)(['path' => $repo['path']]) }}
                                {{ ($this->openFilesAction)(['path' => $repo['path']]) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        @if ($this->userCan(\GitManager\Permissions::MANAGE))
            <div class="gm-side">
                {{-- Deploy key --}}
                <x-filament::section>
                    <x-slot name="heading">{{ trans('git-manager::strings.deploy_key') }}</x-slot>
                    <x-slot name="description">{{ trans('git-manager::strings.deploy_key_desc') }}</x-slot>
                    {{ $this->deployKeyAction }}
                </x-filament::section>

                {{-- HTTPS credentials --}}
                <x-filament::section>
                    <x-slot name="heading">{{ trans('git-manager::strings.credentials') }}</x-slot>
                    <x-slot name="description">{{ trans('git-manager::strings.credentials_desc') }}</x-slot>
                    @forelse ($this->credentials as $credential)
                        <div class="gm-item" wire:key="cred-{{ $credential->id }}">
                            <x-filament::icon icon="tabler-lock" class="gm-icon-sm" />
                            <div class="gm-item-main">
                                <div class="gm-strong">{{ $credential->host }}</div>
                                <div class="gm-item-sub">{{ $credential->username }} · {{ $credential->maskedToken() }}</div>
                            </div>
                            {{ ($this->deleteCredentialAction)(['id' => $credential->id]) }}
                        </div>
                    @empty
                        <div class="gm-item-sub gm-list-empty">{{ trans('git-manager::strings.no_credentials') }}</div>
                    @endforelse
                    <div class="gm-section-foot">{{ $this->addCredentialAction }}</div>
                </x-filament::section>

                {{-- Webhooks --}}
                <x-filament::section>
                    <x-slot name="heading">{{ trans('git-manager::strings.webhooks') }}</x-slot>
                    <x-slot name="description">{{ trans('git-manager::strings.webhooks_desc') }}</x-slot>
                    @forelse ($this->webhooks as $webhook)
                        <div class="gm-item" wire:key="hook-{{ $webhook->id }}">
                            <x-filament::icon icon="tabler-webhook" class="gm-icon-sm" />
                            <div class="gm-item-main">
                                <div class="gm-path gm-truncate" title="{{ $webhook->path }}">{{ $webhook->path }}</div>
                                <div class="gm-item-sub">
                                    @if ($webhook->branch) {{ $webhook->branch }} · @endif
                                    @if ($webhook->last_triggered_at)
                                        <span @class(['gm-ok' => $webhook->last_status === 'success', 'gm-error' => $webhook->last_status === 'failed']) title="{{ $webhook->last_output }}">
                                            {{ trans('git-manager::strings.triggered', ['status' => $webhook->last_status, 'time' => $webhook->last_triggered_at->diffForHumans()]) }}
                                        </span>
                                    @else
                                        {{ trans('git-manager::strings.never_triggered') }}
                                    @endif
                                </div>
                            </div>
                            {{ ($this->showWebhookAction)(['id' => $webhook->id]) }}
                            {{ ($this->deleteWebhookAction)(['id' => $webhook->id]) }}
                        </div>
                    @empty
                        <div class="gm-item-sub gm-list-empty">{{ trans('git-manager::strings.no_webhooks') }}</div>
                    @endforelse
                    <div class="gm-section-foot">{{ $this->addWebhookAction }}</div>
                </x-filament::section>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
