<x-filament-panels::page>
    @assets
    <style>
        .sshd { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
        @media (min-width: 1280px) { .sshd { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
        .sshd-rows { display: flex; flex-direction: column; }
        .sshd-row { display: grid; grid-template-columns: 9rem minmax(0, 1fr) auto; align-items: center; gap: 0.75rem; padding: 0.6rem 0; font-size: 0.875rem; }
        .sshd-row + .sshd-row { border-top: 1px solid rgb(3 7 18 / 0.06); }
        .dark .sshd-row + .sshd-row { border-top-color: rgb(255 255 255 / 0.08); }
        .sshd-label { color: rgb(107 114 128); }
        .dark .sshd-label { color: rgb(156 163 175); }
        .sshd-value { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sshd-help { font-size: 0.875rem; line-height: 1.5; color: rgb(107 114 128); margin-bottom: 0.75rem; }
        .dark .sshd-help { color: rgb(156 163 175); }
        .sshd-help a { text-decoration: underline; }
        .sshd-code-row { display: flex; align-items: stretch; gap: 0.5rem; margin-bottom: 1rem; }
        .sshd-code { flex: 1 1 auto; min-width: 0; margin: 0; padding: 0.6rem 0.75rem; border-radius: 0.5rem; background: rgb(3 7 18 / 0.05); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; line-height: 1.55; white-space: pre; overflow-x: auto; }
        .dark .sshd-code { background: rgb(255 255 255 / 0.05); }
        .sshd-copy { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; min-width: 5.5rem; padding: 0 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: rgb(55 65 81); background: #fff; box-shadow: 0 0 0 1px rgb(3 7 18 / 0.1); }
        .sshd-copy:hover { background: rgb(249 250 251); }
        .dark .sshd-copy { color: rgb(229 231 235); background: rgb(255 255 255 / 0.05); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1); }
        .sshd-copy.is-small { min-width: 0; padding: 0.3rem 0.5rem; }
        .sshd-icon { width: 0.9rem; height: 0.9rem; }
        .sshd-warn { display: flex; gap: 0.5rem; padding: 0.75rem; margin-bottom: 1rem; border-radius: 0.5rem; font-size: 0.875rem; background: rgb(234 179 8 / 0.1); color: rgb(161 98 7); }
        .dark .sshd-warn { color: rgb(250 204 21); }
        .sshd-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
        .sshd-h { font-weight: 600; font-size: 0.875rem; margin: 0.25rem 0 0.4rem; }
    </style>
    @endassets

    @php
        $copy = fn (string $ref) => "navigator.clipboard.writeText(\$refs.$ref.textContent.trim()); copied = '$ref'; setTimeout(() => copied = null, 1500)";
    @endphp

    <div class="sshd" x-data="{ copied: null }">
        <x-filament::section>
            <x-slot name="heading">{{ trans('vscode-files::strings.ssh_connection') }}</x-slot>
            <x-slot name="description">{{ trans('vscode-files::strings.ssh_connection_desc') }}</x-slot>

            @if ($missingSftp)
                <div class="sshd-warn">
                    <x-filament::icon icon="tabler-alert-triangle" class="sshd-icon" />
                    <span>{{ trans('vscode-files::strings.ssh_needs_sftp') }}</span>
                </div>
            @endif

            <div class="sshd-rows">
                @foreach ([
                    'host' => [trans('vscode-files::strings.ssh_host'), $host],
                    'port' => [trans('vscode-files::strings.ssh_port'), $port],
                    'username' => [trans('vscode-files::strings.ssh_username'), $username],
                    'fingerprint' => [trans('vscode-files::strings.ssh_fingerprint'), $fingerprint ?? trans('vscode-files::strings.ssh_unavailable')],
                ] as $ref => [$label, $value])
                    <div class="sshd-row">
                        <span class="sshd-label">{{ $label }}</span>
                        <span class="sshd-value" x-ref="{{ $ref }}" title="{{ $value }}">{{ $value }}</span>
                        <button type="button" class="sshd-copy is-small" x-on:click="{{ $copy($ref) }}" title="{{ trans('vscode-files::strings.copy') }}">
                            <x-filament::icon x-show="copied !== '{{ $ref }}'" icon="tabler-copy" class="sshd-icon" />
                            <x-filament::icon x-show="copied === '{{ $ref }}'" x-cloak icon="tabler-check" class="sshd-icon" />
                        </button>
                    </div>
                @endforeach
                <div class="sshd-row">
                    <span class="sshd-label">{{ trans('vscode-files::strings.ssh_password') }}</span>
                    <span class="sshd-help" style="margin:0">
                        {!! trans('vscode-files::strings.ssh_password_help', ['url' => e($profileUrl)]) !!}
                    </span>
                    <span></span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ trans('vscode-files::strings.ssh_connect') }}</x-slot>

            <div class="sshd-actions">
                <x-filament::button tag="a" :href="$vscodeUri" icon="tabler-brand-vscode">
                    {{ trans('vscode-files::strings.open_local_vscode') }}
                </x-filament::button>
            </div>
            <p class="sshd-help">{!! trans('vscode-files::strings.open_local_vscode_help', ['path' => e($remotePath)]) !!}</p>

            <div class="sshd-h">{{ trans('vscode-files::strings.ssh_terminal') }}</div>
            <div class="sshd-code-row">
                <pre class="sshd-code" x-ref="command">{{ $command }}</pre>
                <button type="button" class="sshd-copy" x-on:click="{{ $copy('command') }}">
                    <span x-text="copied === 'command' ? @js(trans('vscode-files::strings.copied')) : @js(trans('vscode-files::strings.copy'))">{{ trans('vscode-files::strings.copy') }}</span>
                </button>
            </div>

            <div class="sshd-h">{{ trans('vscode-files::strings.ssh_config') }}</div>
            <p class="sshd-help">{!! trans('vscode-files::strings.ssh_config_help', ['alias' => e($alias)]) !!}</p>
            <div class="sshd-code-row">
                <pre class="sshd-code" x-ref="config">{{ $sshConfig }}</pre>
                <button type="button" class="sshd-copy" x-on:click="{{ $copy('config') }}">
                    <span x-text="copied === 'config' ? @js(trans('vscode-files::strings.copied')) : @js(trans('vscode-files::strings.copy'))">{{ trans('vscode-files::strings.copy') }}</span>
                </button>
            </div>

            <p class="sshd-help">{{ trans('vscode-files::strings.ssh_sftp_help') }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
