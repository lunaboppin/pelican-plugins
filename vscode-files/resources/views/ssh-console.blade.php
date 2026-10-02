@php
    $consoleFont = (string) user()?->getCustomization(\App\Enums\CustomizationKey::ConsoleFont);
    $consoleFontSize = (int) user()?->getCustomization(\App\Enums\CustomizationKey::ConsoleFontSize);
@endphp

<x-filament::widget class="pssh-widget">
    @assets
    @vite(['resources/js/console.js', 'resources/css/console.css'])
    <style>
        /* Split the console row while the SSH console is open: the 3-column grid
           becomes 6 columns, so the game console and SSH console get half each and
           the charts keep a third. Full-width widgets are unaffected. */
        @media (min-width: 1024px) {
            .fi-wi:has(.pssh-card.is-open) {
                grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
            }
            .fi-wi:has(.pssh-card.is-open) > .fi-wi-widget:not([style*="1 / -1"]) {
                grid-column: span 2 / span 2 !important;
            }
            .fi-wi:has(.pssh-card.is-open) > .fi-wi-widget:has(#terminal),
            .fi-wi:has(.pssh-card.is-open) > .fi-wi-widget.pssh-widget {
                grid-column: span 3 / span 3 !important;
            }
            .fi-wi:has(.pssh-card.is-open) > .fi-wi-widget.pssh-widget > .pssh-card {
                height: 100%;
                min-height: 0;
            }
        }

        .fi-wi > .fi-wi-widget.pssh-widget:not(:has(.pssh-card.is-open)) {
            display: none;
        }

        .pssh-card {
            display: flex;
            flex-direction: column;
            height: 34rem;
            overflow: hidden;
            border-radius: 10px;
            background: rgba(19, 26, 32, 0.7);
            box-shadow: 0 0 0 1px rgb(255 255 255 / 0.06);
        }
        .pssh-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.6rem 0.9rem;
            border-bottom: 1px solid rgb(255 255 255 / 0.06);
            background: rgb(255 255 255 / 0.03);
            color: rgb(229 231 235);
            font-size: 0.875rem;
            font-weight: 600;
        }
        .pssh-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pssh-status {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: rgb(156 163 175);
        }
        .pssh-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background: rgb(107 114 128);
        }
        .pssh-dot.is-connected { background: rgb(34 197 94); box-shadow: 0 0 6px rgb(34 197 94 / 0.6); }
        .pssh-dot.is-connecting { background: rgb(234 179 8); }
        .pssh-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.375rem;
            color: rgb(156 163 175);
        }
        .pssh-icon-btn:hover {
            background: rgb(255 255 255 / 0.06);
            color: rgb(229 231 235);
        }
        .pssh-terminal {
            flex: 1 1 auto;
            min-height: 0;
            padding-top: 4px;
        }
        .pssh-terminal .xterm,
        .pssh-terminal .xterm-viewport {
            height: 100%;
            background-color: transparent !important;
        }

        /* Header button label swaps while open. */
        .pssh-label-close { display: none; }
        body:has(.pssh-card.is-open) .pssh-label-open { display: none; }
        body:has(.pssh-card.is-open) .pssh-label-close { display: inline; }
    </style>
    @endassets

    <div
        wire:ignore
        x-data="sshConsole(@js(\Filament\Facades\Filament::getTenant()->uuid), @js($consoleFont), @js($consoleFontSize))"
        x-on:toggle-ssh-console.window="toggle()"
        x-bind:class="{ 'is-open': open }"
        class="pssh-card"
    >
        <div class="pssh-header">
            <div class="pssh-title">
                <x-filament::icon icon="tabler-terminal-2" class="h-4 w-4 text-gray-400" />
                {{ trans('vscode-files::strings.ssh_console') }}
            </div>
            <div class="flex items-center gap-2">
                <div class="pssh-status">
                    <span class="pssh-dot" x-bind:class="{ 'is-connected': connected, 'is-connecting': connecting }"></span>
                    <span x-text="state"></span>
                </div>
                <button type="button" class="pssh-icon-btn" x-on:click="reconnect()" x-bind:title="@js(trans('vscode-files::strings.reconnect'))">
                    <x-filament::icon icon="tabler-refresh" class="h-4 w-4" />
                </button>
                <button type="button" class="pssh-icon-btn" x-on:click="toggle()" x-bind:title="@js(trans('vscode-files::strings.close_ssh_console'))">
                    <x-filament::icon icon="tabler-x" class="h-4 w-4" />
                </button>
            </div>
        </div>
        <div x-ref="terminal" class="pssh-terminal"></div>
    </div>

    @script
    <script>
        Alpine.data('sshConsole', (uuid, font, fontSize) => ({
            open: false,
            connected: false,
            connecting: false,
            state: '',
            term: null,
            fit: null,
            socket: null,
            storageKey: 'ssh-console-open:' + uuid,

            init() {
                try { this.open = localStorage.getItem(this.storageKey) === '1'; } catch (e) {}
                if (this.open) this.$nextTick(() => this.start());
            },

            toggle() {
                this.open = !this.open;
                try { localStorage.setItem(this.storageKey, this.open ? '1' : '0'); } catch (e) {}

                this.$nextTick(() => {
                    // Let the game console refit to its new width.
                    window.dispatchEvent(new Event('resize'));
                    if (!this.open) return;
                    this.term ? this.refit() : this.start();
                    this.term.focus();
                });
            },

            start() {
                const { Terminal, FitAddon, WebLinksAddon } = window.Xterm;
                this.term = new Terminal({
                    fontSize: fontSize || 14,
                    fontFamily: (font && font !== 'monospace' ? font + ', ' : '') + 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                    lineHeight: 1.2,
                    cursorBlink: true,
                    allowTransparency: true,
                    scrollback: 5000,
                    theme: {
                        background: 'rgba(0,0,0,0)',
                        foreground: '#e6edf3',
                        cursor: '#e6edf3',
                        selectionBackground: 'rgba(250,240,137,0.3)',
                        black: '#000000', red: '#E54B4B', green: '#9ECE58', yellow: '#FAED70',
                        blue: '#396FE2', magenta: '#BB80B3', cyan: '#2DDAFD', white: '#d0d0d0',
                        brightBlack: '#6b7280', brightRed: '#FF5370', brightGreen: '#C3E88D', brightYellow: '#FFCB6B',
                        brightBlue: '#82AAFF', brightMagenta: '#C792EA', brightCyan: '#89DDFF', brightWhite: '#ffffff',
                    },
                });
                this.fit = new FitAddon();
                this.term.loadAddon(this.fit);
                this.term.loadAddon(new WebLinksAddon());
                this.term.open(this.$refs.terminal);
                this.refit();

                this.term.onData((d) => this.send({ t: 'i', d }));
                this.term.onResize(({ cols, rows }) => this.send({ t: 'r', c: cols, r: rows }));
                new ResizeObserver(() => this.open && this.refit()).observe(this.$refs.terminal);

                this.connect();
            },

            refit() {
                try { this.fit.fit(); } catch (e) {}
            },

            async connect() {
                this.connecting = true;
                this.state = @js(trans('vscode-files::strings.connecting'));
                let url;
                try {
                    url = await $wire.socketUrl();
                } catch (e) {
                    this.connecting = false;
                    this.state = @js(trans('vscode-files::strings.disconnected'));
                    return;
                }

                const socket = new WebSocket(url + '&cols=' + this.term.cols + '&rows=' + this.term.rows);
                socket.binaryType = 'arraybuffer';
                socket.onopen = () => {
                    this.connecting = false;
                    this.connected = true;
                    this.state = @js(trans('vscode-files::strings.connected'));
                    this.term.focus();
                };
                socket.onmessage = (e) => this.term.write(new Uint8Array(e.data));
                socket.onclose = () => {
                    if (this.socket !== socket) return;
                    this.connecting = false;
                    this.connected = false;
                    this.state = @js(trans('vscode-files::strings.disconnected'));
                    this.term.write('\r\n\x1b[2m' + @js(trans('vscode-files::strings.session_ended')) + '\x1b[0m\r\n');
                };
                this.socket = socket;
            },

            reconnect() {
                const old = this.socket;
                this.socket = null;
                if (old) old.close();
                this.term.reset();
                this.connect();
            },

            send(msg) {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.socket.send(JSON.stringify(msg));
                }
            },
        }));
    </script>
    @endscript
</x-filament::widget>
