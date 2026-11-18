{{-- Interactive ecosystem map. Self-contained (scoped to .fm-scope) so it
     can be @included anywhere: the Help "Plugins & Ecosystem" tab and the Plugin
     Bridge "Ecosystem simulation" tab both render this one source of truth.
     Pure vanilla JS + SVG, declarative %-coordinates (works even while the
     containing tab is hidden), wrapped in try/catch (enhancement-only). --}}
@php
    // Market Manager is drawn only where it is installed, as on the Plugin
    // Bridge map. The compatible plugins are the ones that registered with
    // Manager Core here; nothing else says what they take from it.
    $fmBridge = app(\ManagerCore\Services\PluginBridge::class);
    $fmMarket = isset(\ManagerCore\Services\PluginBridge::suitePlugins()['market-manager']);
    $fmCompatible = [];
    $fmCompatibleMore = 0;
    try {
        foreach ($fmBridge->getExternalPlugins() as $fmPlugin) {
            if (count($fmCompatible) === 3) {
                $fmCompatibleMore++;
                continue;
            }
            $fmUse = $fmPlugin['integration'] ?? [];
            $fmTakes = [];
            if (($fmUse['esi_handlers'] ?? 0) > 0) {
                $fmTakes[] = 'the ESI notifications it registered for, from the Manager Core fast poll and sweep';
            }
            if (($fmUse['pricing_subscriptions'] ?? 0) > 0) {
                $fmTakes[] = 'Manager Core prices for the items it subscribes to';
            }
            if (($fmUse['event_subscriptions'] ?? 0) > 0) {
                $fmTakes[] = 'the Manager Core events it subscribes to';
            }
            $fmIcon = (string) ($fmPlugin['icon'] ?? 'fa-puzzle-piece');
            $fmCompatible[] = [
                'id' => 'cp' . count($fmCompatible),
                'name' => (string) $fmPlugin['name'],
                'icon' => preg_match('/\bfa[bsr]\b/', $fmIcon) ? $fmIcon : 'fas ' . $fmIcon,
                'loss' => $fmTakes ? implode(', and ', $fmTakes) : 'its place on the Plugin Bridge (it registered, but takes nothing from Manager Core yet)',
            ];
        }
    } catch (\Throwable $e) {
        $fmCompatible = [];
    }

    $fmNodes = [
        // Icons match each plugin's SeAT sidebar, with a few intentional
        // choices the plugin sidebars will be brought in line with: Manager
        // Core uses the microchip (already updated), Mining keeps the
        // diamond, and SeAT Broadcast uses the Discord brand icon. The icon
        // string carries its own Font Awesome prefix (fas / fab).
        ['id' => 'mc', 'name' => 'Manager Core', 'icon' => 'fas fa-microchip', 'kind' => 'hub'],
        ['id' => 'hr', 'name' => 'HR Manager', 'icon' => 'fas fa-user-tie', 'kind' => 'family'],
        ['id' => 'cwm', 'name' => 'Corp Wallet Manager', 'icon' => 'fas fa-wallet', 'kind' => 'family'],
        ['id' => 'mm', 'name' => 'Mining Manager', 'icon' => 'fas fa-gem', 'kind' => 'family'],
        ['id' => 'sm', 'name' => 'Structure Manager', 'icon' => 'fas fa-industry', 'kind' => 'family'],
        ['id' => 'pings', 'name' => 'SeAT Broadcast', 'icon' => 'fab fa-discord', 'kind' => 'family'],
        ['id' => 'bb', 'name' => 'Buyback Manager', 'icon' => 'fas fa-exchange-alt', 'kind' => 'family'],
        ['id' => 'bp', 'name' => 'Blueprint Manager', 'icon' => 'fas fa-clipboard-list', 'kind' => 'family'],
    ];
    if ($fmMarket) {
        $fmNodes[] = ['id' => 'mkt', 'name' => 'Market Manager', 'icon' => 'fas fa-chart-line', 'kind' => 'family'];
    }
    foreach ($fmCompatible as $fmPlugin) {
        $fmNodes[] = ['id' => $fmPlugin['id'], 'name' => $fmPlugin['name'], 'icon' => $fmPlugin['icon'], 'kind' => 'compatible'];
    }
    $fmNodes[] = ['id' => 'conn', 'name' => 'SeAT Connector', 'icon' => 'fas fa-plug', 'kind' => 'external'];
    $fmNodes[] = ['id' => 'fit', 'name' => 'SeAT Fitting', 'icon' => 'fas fa-rocket', 'kind' => 'external'];
    $fmNodes[] = ['id' => 'price', 'name' => 'seat-prices', 'icon' => 'fas fa-tags', 'kind' => 'external'];

    $fmCompatibleFlows = array_map(fn ($p) => ['f' => 'mc', 't' => $p['id'], 'mc' => true, 'k' => 'data', 'l' => $p['loss']], $fmCompatible);
@endphp
<div class="fm-scope">
    <style>
        .fm-scope .fm-intro { color: #c2c7d0; font-size: 0.92rem; margin-bottom: 6px; }
        .fm-scope .fm-wrap { max-width: 1180px; margin: 14px auto 0; }
        .fm-scope .fm-canvas { position: relative; width: 100%; padding-bottom: 60%; }
        .fm-scope .fm-svg { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
        /* Strokes are in screen pixels, so the stretched viewBox doesn't
           thicken a line by the way it runs. */
        .fm-scope .fm-svg * { vector-effect: non-scaling-stroke; }
        .fm-scope .fm-orbit { fill: none; stroke: rgba(102, 126, 234, 0.18); stroke-width: 1; stroke-dasharray: 3 5; }
        /* What one plugin sends another goes through Manager Core's EventBus
           or Plugin Bridge, so every plugin gets one spoke to the hub and a
           flow lights the two spokes it travels: purple for the family, teal
           for a compatible plugin. Only what bypasses Manager Core is drawn
           plugin to plugin, in yellow: the external plugins' data, and the
           Discord role pickers, which stay hidden until you hover. */
        .fm-scope .fm-edge { fill: none; transition: opacity 0.18s, stroke-width 0.18s; }
        .fm-scope .fm-edge.spoke { stroke-width: 2.4; opacity: 0.55; }
        .fm-scope .fm-edge.data { stroke-width: 1.8; opacity: 0.45; }
        .fm-scope .fm-edge.pick { stroke-width: 1; opacity: 0; }
        .fm-scope .fm-edge.hl { opacity: 0.97; stroke-width: 3.2; }
        .fm-scope .fm-edge.pick.hl { opacity: 0.6; stroke-width: 1.4; }
        /* A switched-off plugin's spoke fades with its node; the links that
           lose something go red so you can see exactly what's lost. */
        .fm-scope .fm-edge.broken { stroke: #ef4444; opacity: 0.9; stroke-width: 2.4; stroke-dasharray: 5 4; }
        .fm-scope .fm-edge.off { stroke: #555; opacity: 0.25; stroke-dasharray: 2 5; }
        .fm-scope .fm-halo { fill: rgba(102, 126, 234, 0.07); stroke: rgba(102, 126, 234, 0.3); stroke-width: 1; }
        .fm-scope .fm-node { position: absolute; transform: translate(-50%, -50%); width: 9%; min-width: 64px; text-align: center; cursor: pointer; z-index: 2; }
        .fm-scope .fm-node .fm-ic { width: 100%; aspect-ratio: 1 / 1; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; background: #2a2f3a; border: 1px solid #454d55; color: #c7d2fe; transition: all 0.2s; }
        .fm-scope .fm-node .fm-lbl { position: absolute; left: 50%; transform: translateX(-50%); top: 100%; width: 160%; font-size: 0.72rem; color: #b6bdc8; margin-top: 4px; line-height: 1.2; }
        /* A plugin in the top half of the ring has its name above it, away
           from the spoke that runs in to Manager Core. */
        .fm-scope .fm-node.lbl-top .fm-lbl { top: auto; bottom: 100%; margin: 0 0 4px; }
        .fm-scope .fm-node.hub { width: 11.5%; }
        .fm-scope .fm-node.hub .fm-ic { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border: none; font-size: 1.7rem; box-shadow: 0 0 22px rgba(102, 126, 234, 0.35); }
        .fm-scope .fm-node.hub .fm-lbl { color: #e2e8f0; font-weight: 600; }
        .fm-scope .fm-node.external .fm-ic { border-style: dashed; border-color: #fbbf24; background: #2a2620; color: #fbbf24; }
        .fm-scope .fm-node.compatible .fm-ic { border-style: dashed; border-color: #3ec9dd; background: #1f2a2e; color: #3ec9dd; }
        .fm-scope .fm-node:hover .fm-ic { border-color: #667eea; transform: translateY(-2px); }
        .fm-scope .fm-node.external:hover .fm-ic { border-color: #fbbf24; }
        .fm-scope .fm-node.compatible:hover .fm-ic { border-color: #3ec9dd; }
        .fm-scope .fm-node.absent .fm-ic { filter: grayscale(1) brightness(0.75); opacity: 0.5; border-color: #555; color: #777; }
        .fm-scope .fm-node.absent .fm-lbl { text-decoration: line-through; opacity: 0.5; color: #777; }
        .fm-scope .fm-node.degraded .fm-ic { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.45); }
        .fm-scope .fm-band { position: absolute; top: 3%; bottom: 3%; width: 14%; border-radius: 12px; }
        .fm-scope .fm-band > span { position: absolute; top: 7px; left: 0; right: 0; text-align: center; font-size: 0.62rem; letter-spacing: 0.6px; text-transform: uppercase; }
        .fm-scope .fm-band.ext { right: 0; border-left: 1px dashed rgba(251, 191, 36, 0.35); background: rgba(251, 191, 36, 0.04); }
        .fm-scope .fm-band.ext > span { color: #b9912f; }
        .fm-scope .fm-band.compat { left: 0; border-right: 1px dashed rgba(62, 201, 221, 0.35); background: rgba(62, 201, 221, 0.04); }
        .fm-scope .fm-band.compat > span { color: #4fb3c2; }
        .fm-scope .fm-band-empty { position: absolute; top: 50%; left: 8%; right: 8%; transform: translateY(-50%); text-align: center; font-size: 0.7rem; line-height: 1.35; color: #6b7a86; }
        .fm-scope .fm-band-more { position: absolute; bottom: 7px; left: 0; right: 0; text-align: center; font-size: 0.66rem; color: #6b7a86; }
        .fm-scope .fm-controls { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 30px; }
        .fm-scope .fm-legend { font-size: 0.76rem; color: #8b95a5; }
        .fm-scope .fm-legend .fm-dot { display: inline-block; width: 11px; height: 11px; border-radius: 2px; vertical-align: middle; margin: 0 4px 0 12px; }
        .fm-scope .fm-legend .fm-line { display: inline-block; width: 18px; height: 3px; border-radius: 2px; vertical-align: middle; margin: 0 4px 0 12px; }
        .fm-scope .fm-losses { margin-top: 14px; background: #23272f; border: 1px solid #3a4049; border-radius: 8px; padding: 13px 16px; min-height: 56px; }
        .fm-scope .fm-hint { color: #8b95a5; font-size: 0.9rem; }
        .fm-scope .fm-loss-group { margin-bottom: 12px; }
        .fm-scope .fm-loss-group:last-child { margin-bottom: 0; }
        .fm-scope .fm-loss-head { color: #e2e8f0; font-size: 0.95rem; margin-bottom: 5px; }
        .fm-scope .fm-loss-crit .fm-loss-head { color: #fca5a5; }
        .fm-scope .fm-losses ul { margin: 0; padding-left: 20px; }
        .fm-scope .fm-losses li { color: #c2c7d0; font-size: 0.88rem; line-height: 1.55; }
        @media (max-width: 620px) {
            .fm-scope .fm-node { min-width: 46px; }
            .fm-scope .fm-node .fm-ic { font-size: 0.95rem; border-radius: 9px; }
            .fm-scope .fm-node .fm-lbl { font-size: 0.58rem; }
            .fm-scope .fm-band-empty { display: none; }
        }
    </style>

    <p class="fm-intro">Manager Core is the hub. Each plugin of the family has a spoke to it, because whatever one plugin sends another travels through Manager Core. Compatible plugins, from outside the suite but connected to Manager Core on their own, are in the band on the left. The external SeAT plugins the family builds on are in the band on the right, and their yellow lines are the only ones that bypass Manager Core. <strong>Click any plugin to switch it off</strong> and the panel below shows what the rest loses. Hover a plugin to light up the links it uses, Discord role pickers included.</p>

    <div class="fm-wrap">
        <div class="fm-canvas" id="fmCanvas">
            <div class="fm-band compat">
                <span>Compatible</span>
                @if(empty($fmCompatible))
                    <div class="fm-band-empty">None on this server yet. A plugin from outside the suite shows here once it registers with Manager Core, as SeAT Timerboard can.</div>
                @endif
                @if($fmCompatibleMore > 0)
                    <div class="fm-band-more">and {{ $fmCompatibleMore }} more on the Plugin Bridge page</div>
                @endif
            </div>
            <div class="fm-band ext"><span>External</span></div>
            <svg class="fm-svg" id="fmSvg" viewBox="0 0 100 100" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="fmGradFam" x1="0" y1="0" x2="100" y2="100" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#667eea"></stop>
                        <stop offset="100%" stop-color="#9d6ad6"></stop>
                    </linearGradient>
                </defs>
            </svg>
            @foreach($fmNodes as $n)
                <div class="fm-node {{ $n['kind'] }}" data-id="{{ $n['id'] }}" data-kind="{{ $n['kind'] }}">
                    <div class="fm-ic"><i class="{{ $n['icon'] }}"></i></div>
                    <div class="fm-lbl">{{ $n['name'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="fm-controls">
            <span class="fm-legend">
                <span class="fm-dot" style="background: linear-gradient(135deg,#667eea,#764ba2);"></span> Manager Core
                <span class="fm-dot" style="background:#2a2f3a;border:1px solid #454d55;"></span> Our family
                <span class="fm-dot" style="background:#1f2a2e;border:1px dashed #3ec9dd;"></span> Compatible plugin
                <span class="fm-dot" style="background:#2a2620;border:1px dashed #fbbf24;"></span> External SeAT plugin
                <span class="fm-line" style="background: linear-gradient(90deg,#667eea,#9d6ad6);"></span> Through Manager Core
                <span class="fm-line" style="background:#fbbf24;"></span> Direct, outside Manager Core
            </span>
            <button type="button" id="fmReset" class="btn btn-sm btn-mc-primary"><i class="fas fa-undo"></i> Reset</button>
        </div>
        <div class="fm-losses" id="fmLosses"></div>
    </div>
    <script>
    (function () {
        try {
            var canvas = document.getElementById('fmCanvas');
            if (!canvas) return;
            var svg = document.getElementById('fmSvg');
            var lossesEl = document.getElementById('fmLosses');
            var resetBtn = document.getElementById('fmReset');
            var SVGNS = 'http://www.w3.org/2000/svg';
            var nodes = canvas.querySelectorAll('.fm-node');

            var NAME = {}, KIND = {}, compat = [];
            nodes.forEach(function (n) {
                var id = n.getAttribute('data-id');
                NAME[id] = n.querySelector('.fm-lbl').textContent;
                KIND[id] = n.getAttribute('data-kind');
                if (KIND[id] === 'compatible') compat.push(id);
            });

            // The family sits evenly on a ring around Manager Core, in degrees
            // clockwise from the right, HR near the top and Broadcast on the
            // side of the external band it takes from. The ring is a circle on
            // screen (the canvas is 60% as tall as it is wide), and it is turned
            // so there is always a gap at 180 degrees, where the compatible
            // band's lines come in.
            var RING = { cx: 50, cy: 50, rx: 22, ry: 22 / 0.6 };
            var ringIds = ['hr', 'cwm', 'pings', 'mm', 'sm', 'bb', 'mkt', 'bp'].filter(function (id) { return NAME[id] !== undefined; });
            var STEP = 360 / ringIds.length;
            var START = 180 - STEP / 2 - STEP * Math.round((270 - STEP / 2) / STEP);
            var ANGLE = {};
            ringIds.forEach(function (id, i) { ANGLE[id] = START + i * STEP; });
            function onRing(deg, grow) {
                var a = deg * Math.PI / 180;
                return [RING.cx + (RING.rx + grow) * Math.cos(a), RING.cy + (RING.rx + grow) / 0.6 * Math.sin(a)];
            }
            var POS = { mc: [RING.cx, RING.cy], conn: [93, 16], fit: [93, 50], price: [93, 86] };
            Object.keys(ANGLE).forEach(function (id) { POS[id] = onRing(ANGLE[id], 0); });
            // Names go above a plugin in the top half and below one in the
            // bottom half, so the sides of the ring stay clear for the lines
            // coming in from the bands.
            function labelSide(deg) {
                return Math.sin(deg * Math.PI / 180) < 0 ? 'lbl-top' : 'lbl-bottom';
            }
            // A line from outside the ring to Manager Core comes in through the
            // gap nearest its own direction, just past the plugins' edge, so it
            // never runs through a plugin.
            function viaGap(p) {
                var deg = Math.atan2((p[1] - RING.cy) * 0.6, p[0] - RING.cx) * 180 / Math.PI;
                var k = Math.round((deg - START - STEP / 2) / STEP);
                return onRing(START + STEP / 2 + k * STEP, 4.5);
            }
            compat.forEach(function (id, i) {
                POS[id] = [7, 50 + (i - (compat.length - 1) / 2) * 23];
            });

            // k: 'data' = a real data/feature flow; 'pick' = the shared Discord role picker.
            var FLOWS = [
                { f:'bp', t:'hr', mc:true, k:'data', l:'the Blueprint activity panel on member profiles and the corp blueprint-engagement card' },
                { f:'cwm', t:'hr', mc:true, k:'data', l:'member contribution and wallet signals, the Corp Health Economy tab and the wallet-audit panel' },
                { f:'mm', t:'hr', mc:true, k:'data', l:'the mining activity timeline and corp ore-op attendance' },
                { f:'pings', t:'hr', mc:true, k:'data', l:'FC activity and the fleet-commander roster' },
                { f:'sm', t:'hr', mc:true, k:'data', l:'the structure-compliance tab on Corp Health (per-structure doctrine compliance vs alliance fits)' },
                { f:'sm', t:'mm', mc:true, k:'data', l:'extraction at-risk / lost alerts when a structure is attacked' },
                { f:'sm', t:'pings', mc:true, k:'data', l:'structure timers on the fleet calendar' },
                { f:'mm', t:'pings', mc:true, k:'data', l:'mining extractions on the FC opportunities board' },
                { f:'mc', t:'sm', mc:true, k:'data', l:'two of its three ESI detection paths: the ~2 minute Manager Core fast-poll, which rides on MC\'s shared director key pool, and MC\'s sweep of SeAT\'s notifications every minute. Without them, structure attack / reinforcement-timer / fuel alerts fall back to the native ~15-20 minute sweep, so the most time-critical notifications often arrive too late to act on' },
                { f:'mc', t:'mm', mc:true, k:'data', l:'centralised ore pricing (falls back to local price sources)' },
                { f:'mc', t:'bb', mc:true, k:'data', l:'Manager Core pricing for appraisals (falls back to its own chain)' },
                { f:'mc', t:'mkt', mc:true, k:'data', l:'everything: it is built on Manager Core, so its watches, charts and deal alerts have no prices' },
                { f:'price', t:'mc', mc:false, k:'data', l:'the optional seat-prices source in the pricing chain (Mining and Buyback price through it)' },
                { f:'fit', t:'pings', mc:false, k:'data', l:'attaching fitting doctrines to broadcasts' },
                { f:'pings', t:'mm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'sm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'cwm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'bb', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'mkt', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'conn', t:'hr', mc:false, k:'pick', l:'Connector roles in its Discord role picker, plus Discord-role-based activity tiers' },
                { f:'conn', t:'mm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'sm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'cwm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'bb', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'pings', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'mkt', mc:false, k:'pick', l:'Connector roles in its Discord role picker' }
            ].concat(@json($fmCompatibleFlows)).filter(function (fl) { return POS[fl.f] && POS[fl.t]; });

            function esc(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            var absent = {};

            nodes.forEach(function (n) {
                var id = n.getAttribute('data-id');
                if (POS[id]) { n.style.left = POS[id][0] + '%'; n.style.top = POS[id][1] + '%'; }
                if (ANGLE[id] !== undefined) n.classList.add(labelSide(ANGLE[id]));
                n.addEventListener('click', function () { absent[id] = !absent[id]; render(); });
                n.addEventListener('mouseenter', function () { highlight(id); });
                n.addEventListener('mouseleave', clearHighlight);
            });

            var orbit = document.createElementNS(SVGNS, 'ellipse');
            orbit.setAttribute('cx', RING.cx); orbit.setAttribute('cy', RING.cy);
            orbit.setAttribute('rx', RING.rx); orbit.setAttribute('ry', RING.ry);
            orbit.setAttribute('class', 'fm-orbit');
            svg.appendChild(orbit);

            function line(a, b, stroke, cls, via) {
                var path = document.createElementNS(SVGNS, 'path');
                path.setAttribute('d', 'M' + a[0] + ' ' + a[1] + (via ? ' L' + via[0] + ' ' + via[1] : '') + ' L' + b[0] + ' ' + b[1]);
                path.setAttribute('stroke', stroke);
                path.setAttribute('class', 'fm-edge ' + cls);
                svg.appendChild(path);
                return path;
            }

            var halo = document.createElementNS(SVGNS, 'ellipse');
            halo.setAttribute('cx', RING.cx); halo.setAttribute('cy', RING.cy);
            halo.setAttribute('rx', 9.5); halo.setAttribute('ry', 9.5 / 0.6);
            halo.setAttribute('class', 'fm-halo');
            svg.appendChild(halo);

            // One spoke per plugin that reaches the others through Manager Core.
            var spokes = {};
            ringIds.forEach(function (id) {
                spokes[id] = line(POS.mc, POS[id], 'url(#fmGradFam)', 'spoke');
            });
            compat.forEach(function (id) {
                spokes[id] = line(POS.mc, POS[id], '#3ec9dd', 'spoke', viaGap(POS[id]));
            });

            // A flow through Manager Core travels the spokes of the plugins at
            // either end; any other flow gets a line of its own.
            FLOWS.forEach(function (fl) {
                if (fl.mc) {
                    fl.via = [fl.f, fl.t].filter(function (id) { return spokes[id]; });
                } else {
                    var outside = fl.t === 'mc' ? fl.f : (fl.f === 'mc' ? fl.t : null);
                    fl.el = line(POS[fl.f], POS[fl.t], '#fbbf24', fl.k, outside ? viaGap(POS[outside]) : null);
                }
            });
            function edgesOf(fl) {
                return fl.el ? [fl.el] : fl.via.map(function (id) { return spokes[id]; });
            }

            function highlight(id) {
                clearHighlight();
                if (spokes[id]) spokes[id].classList.add('hl');
                FLOWS.forEach(function (fl) {
                    if (fl.f === id || fl.t === id || (id === 'mc' && fl.mc)) {
                        edgesOf(fl).forEach(function (e) { e.classList.add('hl'); });
                    }
                });
            }
            function clearHighlight() {
                svg.querySelectorAll('.fm-edge').forEach(function (e) { e.classList.remove('hl'); });
            }

            function broken(fl) { return !!(absent[fl.f] || absent[fl.t] || (fl.mc && absent['mc'])); }

            function render() {
                var degraded = {};
                FLOWS.forEach(function (fl) { if (broken(fl) && !absent[fl.t]) degraded[fl.t] = true; });
                nodes.forEach(function (n) {
                    var id = n.getAttribute('data-id');
                    n.classList.toggle('absent', !!absent[id]);
                    n.classList.toggle('degraded', !!degraded[id] && !absent[id]);
                });
                svg.querySelectorAll('.fm-edge').forEach(function (e) { e.classList.remove('broken', 'off'); });
                FLOWS.forEach(function (fl) {
                    if (!broken(fl) || absent[fl.t]) return;
                    edgesOf(fl).forEach(function (e) { e.classList.add('broken'); });
                });
                Object.keys(spokes).forEach(function (id) {
                    if (absent[id]) { spokes[id].classList.remove('broken'); spokes[id].classList.add('off'); }
                });
                var anyAbsent = Object.keys(absent).some(function (k) { return absent[k]; });
                if (!anyAbsent) {
                    lossesEl.innerHTML = '<span class="fm-hint">Click a plugin to switch it off and see what the rest loses.</span>';
                    return;
                }
                var groups = {};
                FLOWS.forEach(function (fl) {
                    if (!broken(fl) || absent[fl.t]) return;
                    var cause = absent[fl.f] ? fl.f : 'mc';
                    if (!groups[cause]) groups[cause] = [];
                    groups[cause].push(esc(NAME[fl.t]) + ' loses ' + esc(fl.l));
                });
                var html = '';
                Object.keys(groups).forEach(function (cause) {
                    var crit = (cause === 'mc');
                    var head = crit
                        ? '<i class="fas fa-exclamation-triangle"></i> Without <strong>Manager Core</strong> the shared layer (EventBus, Plugin Bridge, pricing) is gone, so:'
                        : 'Without <strong>' + esc(NAME[cause]) + '</strong>:';
                    html += '<div class="fm-loss-group ' + (crit ? 'fm-loss-crit' : '') + '"><div class="fm-loss-head">' + head + '</div><ul>';
                    groups[cause].forEach(function (item) { html += '<li>' + item + '</li>'; });
                    html += '</ul></div>';
                });
                lossesEl.innerHTML = html || '<span class="fm-hint">Nothing else depends on that one: it only takes, and gives nothing back.</span>';
            }

            if (resetBtn) resetBtn.addEventListener('click', function () { absent = {}; render(); });
            render();
        } catch (e) { /* enhancement-only; never break the host page */ }
    })();
    </script>
</div>
