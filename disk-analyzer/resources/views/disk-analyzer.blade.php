<x-filament-panels::page>
    @assets
    <style>
        .da { --da-card: #fff; --da-border: rgb(3 7 18 / 0.08); --da-muted: rgb(107 114 128); --da-text: rgb(17 24 39); --da-track: rgb(3 7 18 / 0.06); --da-hover: rgb(3 7 18 / 0.04); --da-map: #1f2430; }
        .dark .da { --da-card: rgb(24 24 27); --da-border: rgb(255 255 255 / 0.08); --da-muted: rgb(156 163 175); --da-text: rgb(243 244 246); --da-track: rgb(255 255 255 / 0.08); --da-hover: rgb(255 255 255 / 0.04); --da-map: #161a22; }

        .da { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); color: var(--da-text); }
        @media (min-width: 1280px) { .da { grid-template-columns: minmax(0, 1fr) 24rem; } }

        .da-card { background: var(--da-card); border-radius: 0.75rem; box-shadow: 0 0 0 1px var(--da-border); overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
        .da-card-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--da-border); }
        .da-card-title { font-size: 1.05rem; font-weight: 600; white-space: nowrap; }
        .da-meta { display: flex; align-items: center; gap: 0.6rem; font-size: 0.85rem; color: var(--da-muted); flex-wrap: wrap; min-width: 0; }
        .da-meta b { color: var(--da-text); font-weight: 600; }
        .da-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; height: 2rem; padding: 0 0.6rem; border-radius: 0.5rem; color: var(--da-muted); font-size: 0.8rem; }
        .da-btn:hover { background: var(--da-hover); color: var(--da-text); }
        .da-btn[disabled] { opacity: 0.5; pointer-events: none; }
        .da-btn-label { display: none; }
        @media (min-width: 640px) { .da-btn-label { display: inline; } }
        .da-chevron { width: 0.75rem; height: 0.75rem; }
        .da-spin { animation: da-spin 1s linear infinite; }
        @keyframes da-spin { to { transform: rotate(360deg); } }

        .da-crumbs { display: flex; align-items: center; gap: 0.25rem; padding: 0.55rem 1.1rem; font-size: 0.85rem; color: var(--da-muted); border-bottom: 1px solid var(--da-border); flex-wrap: wrap; }
        .da-crumbs button:hover { color: var(--da-text); text-decoration: underline; }
        .da-crumbs .is-current { color: var(--da-text); font-weight: 500; }

        .da-map-wrap { position: relative; height: max(32rem, calc(100vh - 17rem)); background: var(--da-map); }
        .da-card.is-fullscreen .da-map-wrap { height: auto; flex: 1 1 auto; }
        .da-map { position: absolute; inset: 0; overflow: hidden; font: 600 11px/14px ui-sans-serif, system-ui, sans-serif; }
        .da-map > div { position: absolute; box-sizing: border-box; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; color: #fff; text-shadow: 0 1px 1px rgb(0 0 0 / 0.55); }
        .da-dir { background: #262c38; border: 1px solid #12151b; cursor: zoom-in; }
        .da-dir-head { padding: 0 4px; height: 14px; }
        .da-file { border: 1px solid rgb(0 0 0 / 0.35); padding: 1px 3px; }
        .da-file:hover, .da-dir:hover { filter: brightness(1.15); }
        .da-group { background: repeating-linear-gradient(45deg, #2c3340, #2c3340 4px, #262c38 4px, #262c38 8px); border: 1px solid #12151b; color: rgb(255 255 255 / 0.6) !important; padding: 1px 3px; }
        .da-state { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem; color: rgb(209 213 219); font-size: 0.9rem; }

        .da-tip { position: fixed; z-index: 60; pointer-events: none; background: rgb(17 24 39 / 0.95); color: #f3f4f6; border-radius: 0.5rem; padding: 0.45rem 0.6rem; font-size: 0.78rem; line-height: 1.35; box-shadow: 0 6px 20px rgb(0 0 0 / 0.35); max-width: 28rem; }
        .da-tip .da-tip-path { font-family: ui-monospace, monospace; word-break: break-all; }
        .da-tip .da-tip-sub { color: rgb(156 163 175); }

        .da-side { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
        @media (min-width: 1280px) { .da-side { height: calc(max(32rem, calc(100vh - 17rem)) + 6.4rem); } .da-side .da-card { flex: 1 1 0; min-height: 0; } }
        .da-list { overflow-y: auto; padding: 0.35rem 0; }
        .da-row { display: grid; grid-template-columns: 0.75rem minmax(0, 1fr) auto; column-gap: 0.75rem; row-gap: 0.3rem; align-items: center; padding: 0.55rem 1.1rem; width: 100%; text-align: left; }
        button.da-row:hover { background: var(--da-hover); }
        .da-dot { width: 0.6rem; height: 0.6rem; border-radius: 9999px; }
        .da-name { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .da-size { font-size: 0.78rem; color: var(--da-muted); text-align: right; white-space: nowrap; }
        .da-bar { grid-column: 2 / 4; height: 4px; border-radius: 9999px; background: var(--da-track); display: flex; overflow: hidden; }
        .da-bar > span { height: 100%; }
        .da-sub { grid-column: 2 / 4; display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--da-muted); }
    </style>
    @endassets

    <div class="da" x-data="diskAnalyzer" x-on:keydown.escape.window="tip = null">
        <section class="da-card" x-ref="mapCard" x-bind:class="{ 'is-fullscreen': fullscreen }">
            <div class="da-card-head">
                <span class="da-card-title">{{ trans('disk-analyzer::strings.usage') }}</span>
                <div class="da-meta" x-show="report" x-cloak>
                    <span class="flex items-center gap-1"><x-filament::icon icon="tabler-database" class="h-4 w-4" /><b x-text="size(report?.total)"></b></span>
                    <span x-text="num(report?.files) + ' {{ trans('disk-analyzer::strings.files') }}'"></span>
                    <span>·</span>
                    <span x-text="'{{ trans('disk-analyzer::strings.last_scanned') }} ' + ago + ' {{ trans('disk-analyzer::strings.ago') }}'"></span>
                </div>
                <div class="ml-auto flex items-center gap-1">
                    @if ($this->canRescan())
                    <button type="button" class="da-btn" x-on:click="load(true)" x-bind:disabled="loading" title="{{ trans('disk-analyzer::strings.rescan') }}">
                        <x-filament::icon icon="tabler-refresh" class="h-4 w-4" x-bind:class="{ 'da-spin': loading }" />
                        <span class="da-btn-label">{{ trans('disk-analyzer::strings.rescan') }}</span>
                    </button>
                    @endif
                    <button type="button" class="da-btn" x-on:click="toggleFullscreen()" title="{{ trans('disk-analyzer::strings.fullscreen') }}">
                        <x-filament::icon icon="tabler-maximize" class="h-4 w-4" x-show="!fullscreen" />
                        <x-filament::icon icon="tabler-minimize" class="h-4 w-4" x-show="fullscreen" x-cloak />
                    </button>
                </div>
            </div>

            <nav class="da-crumbs">
                <x-filament::icon icon="tabler-home" class="h-4 w-4" />
                <button type="button" x-on:click="zoom([])" x-bind:class="{ 'is-current': path.length === 0 }">{{ trans('disk-analyzer::strings.root') }}</button>
                <template x-for="(part, i) in path" :key="i">
                    <span class="flex items-center gap-1">
                        <x-filament::icon icon="tabler-chevron-right" class="da-chevron" />
                        <button type="button" x-on:click="zoom(path.slice(0, i + 1))" x-bind:class="{ 'is-current': i === path.length - 1 }" x-text="part"></button>
                    </span>
                </template>
                <span class="ml-auto text-xs text-warning-500" x-show="report?.truncated" x-cloak>{{ trans('disk-analyzer::strings.truncated') }}</span>
            </nav>

            <div class="da-map-wrap">
                <div class="da-map" x-ref="map" wire:ignore
                     x-on:mousemove="hover($event)" x-on:mouseleave="tip = null" x-on:click="clickMap($event)"></div>

                <div class="da-state" x-show="loading" x-cloak>
                    <x-filament::loading-indicator class="h-8 w-8" />
                    <span>{{ trans('disk-analyzer::strings.scanning') }}</span>
                </div>
                <div class="da-state" x-show="!loading && error" x-cloak>
                    <span>{{ trans('disk-analyzer::strings.failed') }}</span>
                    <x-filament::button size="sm" color="gray" x-on:click="load(false)">{{ trans('disk-analyzer::strings.retry') }}</x-filament::button>
                </div>
                <div class="da-state" x-show="!loading && !error && report && report.total === 0" x-cloak>
                    <span>{{ trans('disk-analyzer::strings.empty') }}</span>
                </div>
            </div>
        </section>

        <div class="da-side">
            <section class="da-card">
                <div class="da-card-head"><span class="da-card-title">{{ trans('disk-analyzer::strings.folders') }}</span></div>
                <div class="da-list">
                    <template x-for="f in report?.folders ?? []" :key="f.path">
                        <button type="button" class="da-row" x-on:click="zoom(f.path.split('/').filter(Boolean))">
                            <span class="da-dot" x-bind:style="{ background: color(f.types[0]?.ext) }"></span>
                            <span class="da-name" x-text="f.path" x-bind:title="f.path"></span>
                            <span class="da-size" x-text="size(f.size)"></span>
                            <span class="da-bar">
                                <template x-for="t in f.types" :key="t.ext">
                                    <span x-bind:style="{ width: (t.size / Math.max(f.size, 1) * 100) + '%', background: color(t.ext) }" x-bind:title="label(t.ext)"></span>
                                </template>
                            </span>
                            <span class="da-sub">
                                <span x-text="pct(f.size) + ' {{ trans('disk-analyzer::strings.of_total') }}'"></span>
                                <span x-text="num(f.entries) + ' {{ trans('disk-analyzer::strings.entries') }}'"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </section>

            <section class="da-card">
                <div class="da-card-head"><span class="da-card-title">{{ trans('disk-analyzer::strings.file_types') }}</span></div>
                <div class="da-list">
                    <template x-for="t in report?.types ?? []" :key="t.ext">
                        <div class="da-row">
                            <span class="da-dot" x-bind:style="{ background: color(t.ext) }"></span>
                            <span class="da-name" x-text="label(t.ext)"></span>
                            <span class="da-size" x-text="size(t.size)"></span>
                            <span class="da-bar"><span x-bind:style="{ width: Math.max(pctNum(t.size), 0.5) + '%', background: color(t.ext) }"></span></span>
                            <span class="da-sub">
                                <span x-text="pct(t.size)"></span>
                                <span x-text="num(t.count) + ' {{ trans('disk-analyzer::strings.files') }}'"></span>
                            </span>
                        </div>
                    </template>
                </div>
            </section>
        </div>

        <div class="da-tip" x-show="tip" x-cloak x-bind:style="tip && { left: tip.x + 'px', top: tip.y + 'px' }">
            <div class="da-tip-path" x-text="tip?.path"></div>
            <div class="da-tip-sub" x-text="tip?.sub"></div>
        </div>
    </div>

    @script
    <script>
        const PALETTE = ['#a78bfa', '#f0506e', '#2dd4bf', '#facc15', '#94a3b8', '#a3e635', '#fb923c', '#38bdf8', '#f472b6', '#4ade80', '#e879f9', '#fbbf24', '#60a5fa', '#f87171', '#34d399'];
        const FALLBACK = '#64748b';
        const LABELS = { '(none)': @js(trans('disk-analyzer::strings.no_extension')), '(other)': @js(trans('disk-analyzer::strings.other')) };
        const GROUPED = @js(trans('disk-analyzer::strings.smaller_items'));
        const HEAD = 14;
        const MAX_BOXES = 5000;

        // Squarified treemap (Bruls et al.): lays out items with value v into a rect.
        function squarify(items, x, y, w, h) {
            const total = items.reduce((a, i) => a + i.v, 0);
            if (total <= 0 || w <= 0 || h <= 0) return [];
            const scale = (w * h) / total;
            const queue = items.filter((i) => i.v > 0).map((i) => ({ item: i, area: i.v * scale }));
            const out = [];
            let row = [];

            const worst = (r, side) => {
                const s = r.reduce((a, i) => a + i.area, 0);
                let max = 0, min = Infinity;
                for (const i of r) { max = Math.max(max, i.area); min = Math.min(min, i.area); }
                return Math.max((side * side * max) / (s * s), (s * s) / (side * side * min));
            };
            const place = (r) => {
                const s = r.reduce((a, i) => a + i.area, 0);
                if (w >= h) {
                    const cw = s / h; let cy = y;
                    for (const i of r) { const ih = i.area / cw; out.push({ item: i.item, x, y: cy, w: cw, h: ih }); cy += ih; }
                    x += cw; w -= cw;
                } else {
                    const rh = s / w; let cx = x;
                    for (const i of r) { const iw = i.area / rh; out.push({ item: i.item, x: cx, y, w: iw, h: rh }); cx += iw; }
                    y += rh; h -= rh;
                }
            };

            while (queue.length) {
                const side = Math.min(w, h);
                const next = queue[0];
                if (row.length === 0 || worst([...row, next], side) <= worst(row, side)) {
                    row.push(next); queue.shift();
                } else {
                    place(row); row = [];
                }
            }
            if (row.length) place(row);
            return out;
        }

        Alpine.data('diskAnalyzer', () => ({
            report: null,
            loading: false,
            error: false,
            path: [],
            tip: null,
            ago: '0s',
            fullscreen: false,
            colors: {},
            boxes: [],

            init() {
                this.load(false);
                new ResizeObserver(() => this.render()).observe(this.$refs.map);
                document.addEventListener('fullscreenchange', () => { this.fullscreen = document.fullscreenElement === this.$refs.mapCard; });
                const timer = setInterval(() => this.tick(), 1000);
                this.$cleanup?.(() => clearInterval(timer));
            },

            async load(refresh) {
                this.loading = true;
                this.error = false;
                try {
                    const url = await $wire.reportUrl(refresh);
                    const res = await fetch(url, { credentials: 'omit' });
                    if (!res.ok) throw new Error(res.status);
                    const report = await res.json();
                    this.colors = {};
                    report.types.forEach((t, i) => { this.colors[t.ext] = t.ext === '(other)' ? FALLBACK : (PALETTE[i] ?? FALLBACK); });
                    this.report = report;
                    if (!this.find(this.path)) this.path = [];
                    this.tick();
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                    this.$nextTick(() => this.render());
                }
            },

            tick() {
                if (!this.report) return;
                const s = Math.max(0, Math.floor(Date.now() / 1000 - this.report.scanned_at));
                this.ago = s < 60 ? s + 's' : s < 3600 ? Math.floor(s / 60) + 'm' : Math.floor(s / 3600) + 'h';
            },

            find(parts) {
                let node = this.report?.tree;
                for (const p of parts) {
                    node = node?.d?.find((c) => c.n === p && c.d);
                    if (!node) return null;
                }
                return node;
            },

            zoom(parts) {
                if (!this.find(parts)) return;
                this.path = parts;
                this.tip = null;
                this.render();
            },

            render() {
                const el = this.$refs.map;
                el.replaceChildren();
                this.boxes = [];
                const root = this.find(this.path);
                if (!root || !root.s) return;

                const frag = document.createDocumentFragment();
                const add = (cls, x, y, w, h, bg, text, node, parts) => {
                    if (this.boxes.length >= MAX_BOXES) return null;
                    const d = document.createElement('div');
                    d.className = cls;
                    d.style.cssText = `left:${x}px;top:${y}px;width:${w}px;height:${h}px;${bg ? 'background:' + bg + ';' : ''}`;
                    if (text) d.textContent = text;
                    d.dataset.i = this.boxes.length;
                    this.boxes.push({ node, parts });
                    frag.appendChild(d);
                    return d;
                };

                const draw = (node, parts, x, y, w, h) => {
                    const items = (node.d ?? []).map((c) => ({ v: c.s, c }));
                    for (const r of squarify(items, x, y, w, h)) {
                        const c = r.item.c;
                        const rw = Math.max(0, r.w), rh = Math.max(0, r.h);
                        if (rw < 1 || rh < 1) continue;
                        if (c.o) {
                            add('da-group', r.x, r.y, rw, rh, null, rw > 70 && rh > 14 ? `${c.o} ${GROUPED}` : '', c, parts);
                        } else if (c.d || c.f) {
                            const childParts = [...parts, c.n];
                            const showHead = rh > HEAD + 6 && rw > 24;
                            add('da-dir', r.x, r.y, rw, rh, null, null, c, childParts)
                                ?.appendChild(Object.assign(document.createElement('div'), { className: 'da-dir-head', textContent: showHead ? c.n : '' }));
                            if (showHead && rw > 8 && rh > HEAD + 8 && c.d) {
                                draw(c, childParts, r.x + 1, r.y + HEAD, rw - 2, rh - HEAD - 1);
                            }
                        } else {
                            add('da-file', r.x, r.y, rw, rh, this.color(c.x), rw > 46 && rh > 14 ? c.n : '', c, [...parts, c.n]);
                        }
                    }
                };

                draw(root, this.path, 0, 0, el.clientWidth, el.clientHeight);
                el.appendChild(frag);
            },

            hover(e) {
                const box = this.boxes[e.target.closest('[data-i]')?.dataset.i];
                if (!box) { this.tip = null; return; }
                const n = box.node;
                const path = n.o ? '/' + box.parts.join('/') + ' · ' + n.o + ' ' + GROUPED : '/' + box.parts.join('/');
                const kind = n.d || n.f ? ' · ' + this.num(Math.max(n.f, 0)) + ' files' : n.x ? ' · ' + this.label(n.x) : '';
                const x = Math.min(e.clientX + 14, window.innerWidth - 300);
                this.tip = { x, y: e.clientY + 16, path, sub: this.size(n.s) + ' · ' + this.pct(n.s) + kind };
            },

            clickMap(e) {
                const box = this.boxes[e.target.closest('[data-i]')?.dataset.i];
                if (!box) return;
                // Clicking a file zooms into its folder; clicking a folder zooms into it.
                const parts = box.node.d ? box.parts : box.parts.slice(0, -1);
                if (parts.join('/') !== this.path.join('/')) this.zoom(parts);
            },

            async toggleFullscreen() {
                if (document.fullscreenElement) await document.exitFullscreen();
                else await this.$refs.mapCard.requestFullscreen?.();
            },

            color(ext) { return this.colors[ext] ?? FALLBACK; },
            label(ext) { return LABELS[ext] ?? ext; },
            num(n) { return (n ?? 0).toLocaleString(); },
            pctNum(s) { return this.report?.total ? (s / this.report.total) * 100 : 0; },
            pct(s) { return this.pctNum(s).toFixed(1) + '%'; },
            size(b) {
                b = b ?? 0;
                const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
                let i = 0;
                while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
                return (i === 0 ? b : b.toFixed(1)) + ' ' + u[i];
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>
