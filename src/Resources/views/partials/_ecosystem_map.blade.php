{{-- Interactive ecosystem map. Self-contained (scoped to .fm-scope) so it
     can be @included anywhere: the Help "Plugin Family" tab and the Plugin
     Bridge "Ecosystem simulation" tab both render this one source of truth.
     Pure vanilla JS + SVG, declarative %-coordinates (works even while the
     containing tab is hidden), wrapped in try/catch (enhancement-only). --}}
<div class="fm-scope">
    <style>
        .fm-scope .fm-intro { color: #c2c7d0; font-size: 0.92rem; margin-bottom: 6px; }
        .fm-scope .fm-wrap { max-width: 1180px; margin: 14px auto 0; }
        .fm-scope .fm-canvas { position: relative; width: 100%; padding-bottom: 58%; }
        .fm-scope .fm-svg { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
        /* Family edges carry the Manager Core purple gradient (set via attribute
           in JS); external edges are yellow. Data flows are bold and always on;
           the shared Discord role picker is the thin fan in the background. */
        .fm-scope .fm-edge { transition: opacity 0.18s, stroke-width 0.18s; }
        .fm-scope .fm-edge.data { stroke-width: 0.55; opacity: 0.45; }
        .fm-scope .fm-edge.pick { stroke-width: 0.3; opacity: 0.16; }
        .fm-scope .fm-edge.hl { opacity: 0.97; stroke-width: 0.9; }
        /* A switched-off plugin's node greys out, but the connections it
           breaks go red so you can see exactly what's lost. */
        .fm-scope .fm-edge.broken { stroke: #ef4444; opacity: 0.9; stroke-width: 0.9; stroke-dasharray: 1.6 1.4; }
        .fm-scope .fm-node { position: absolute; transform: translate(-50%, -50%); width: 10.5%; min-width: 68px; text-align: center; cursor: pointer; }
        .fm-scope .fm-node .fm-ic { width: 100%; aspect-ratio: 1 / 1; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; background: #2a2f3a; border: 1px solid #454d55; color: #c7d2fe; transition: all 0.2s; }
        .fm-scope .fm-node .fm-lbl { font-size: 0.73rem; color: #b6bdc8; margin-top: 5px; line-height: 1.2; }
        .fm-scope .fm-node.hub .fm-ic { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border: none; }
        .fm-scope .fm-node.external .fm-ic { border-style: dashed; border-color: #fbbf24; background: #2a2620; color: #fbbf24; }
        .fm-scope .fm-node:hover .fm-ic { border-color: #667eea; transform: translateY(-2px); }
        .fm-scope .fm-node.external:hover .fm-ic { border-color: #fbbf24; }
        .fm-scope .fm-node.absent .fm-ic { filter: grayscale(1) brightness(0.75); opacity: 0.5; border-color: #555; color: #777; }
        .fm-scope .fm-node.absent .fm-lbl { text-decoration: line-through; opacity: 0.5; color: #777; }
        .fm-scope .fm-node.degraded .fm-ic { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.45); }
        .fm-scope .fm-ext-band { position: absolute; top: 4%; bottom: 4%; right: 0; width: 14%; border-left: 1px dashed rgba(251, 191, 36, 0.35); border-radius: 12px; background: rgba(251, 191, 36, 0.04); }
        .fm-scope .fm-ext-band span { position: absolute; top: 6px; left: 0; right: 0; text-align: center; font-size: 0.62rem; letter-spacing: 0.6px; text-transform: uppercase; color: #b9912f; }
        .fm-scope .fm-controls { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .fm-scope .fm-legend { font-size: 0.76rem; color: #8b95a5; }
        .fm-scope .fm-legend .fm-dot { display: inline-block; width: 11px; height: 11px; border-radius: 2px; vertical-align: middle; margin: 0 4px 0 12px; }
        .fm-scope .fm-losses { margin-top: 14px; background: #23272f; border: 1px solid #3a4049; border-radius: 8px; padding: 13px 16px; min-height: 56px; }
        .fm-scope .fm-hint { color: #8b95a5; font-size: 0.9rem; }
        .fm-scope .fm-loss-group { margin-bottom: 12px; }
        .fm-scope .fm-loss-group:last-child { margin-bottom: 0; }
        .fm-scope .fm-loss-head { color: #e2e8f0; font-size: 0.95rem; margin-bottom: 5px; }
        .fm-scope .fm-loss-crit .fm-loss-head { color: #fca5a5; }
        .fm-scope .fm-losses ul { margin: 0; padding-left: 20px; }
        .fm-scope .fm-losses li { color: #c2c7d0; font-size: 0.88rem; line-height: 1.55; }
        @media (max-width: 620px) {
            .fm-scope .fm-node { min-width: 50px; }
            .fm-scope .fm-node .fm-ic { font-size: 0.95rem; border-radius: 9px; }
            .fm-scope .fm-node .fm-lbl { font-size: 0.58rem; }
        }
    </style>

    <p class="fm-intro">Manager Core and the family are on the left; the external SeAT plugins we plug into are in the yellow band on the right. <strong>Click any plugin to switch it off</strong> and the panel below shows what the rest of the suite loses. The bold purple lines are data flows; the thin fan is the shared Discord role picker. Hover a plugin to light up its connections.</p>

    @php
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
            ['id' => 'conn', 'name' => 'SeAT Connector', 'icon' => 'fas fa-plug', 'kind' => 'external'],
            ['id' => 'fit', 'name' => 'SeAT Fitting', 'icon' => 'fas fa-rocket', 'kind' => 'external'],
            ['id' => 'price', 'name' => 'seat-prices', 'icon' => 'fas fa-tags', 'kind' => 'external'],
        ];
    @endphp
    <div class="fm-wrap">
        <div class="fm-canvas" id="fmCanvas">
            <div class="fm-ext-band"><span>External</span></div>
            <svg class="fm-svg" id="fmSvg" viewBox="0 0 100 100" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="fmGradFam" x1="0" y1="0" x2="100" y2="100" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#667eea"></stop>
                        <stop offset="100%" stop-color="#9d6ad6"></stop>
                    </linearGradient>
                </defs>
            </svg>
            @foreach($fmNodes as $n)
                <div class="fm-node {{ $n['kind'] }}" data-id="{{ $n['id'] }}">
                    <div class="fm-ic"><i class="{{ $n['icon'] }}"></i></div>
                    <div class="fm-lbl">{{ $n['name'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="fm-controls">
            <span class="fm-legend">
                <span class="fm-dot" style="background: linear-gradient(135deg,#667eea,#764ba2);"></span> Manager Core
                <span class="fm-dot" style="background:#2a2f3a;border:1px solid #454d55;"></span> Our family
                <span class="fm-dot" style="background:#2a2620;border:1px dashed #fbbf24;"></span> External SeAT plugin
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

            // Family on the left, externals in the right band.
            var POS = {
                mc:[36,50], hr:[36,12], bp:[10,31], cwm:[58,30], mm:[64,55], pings:[48,84], sm:[10,61], bb:[27,84],
                conn:[94,16], fit:[94,50], price:[94,84]
            };
            var NAME = { mc:'Manager Core', hr:'HR Manager', cwm:'Corp Wallet Manager', mm:'Mining Manager', sm:'Structure Manager', pings:'SeAT Broadcast', bb:'Buyback Manager', bp:'Blueprint Manager', conn:'SeAT Connector', fit:'SeAT Fitting', price:'seat-prices' };
            var EXT = { conn: 1, fit: 1, price: 1 };
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
                { f:'mc', t:'sm', mc:true, k:'data', l:'two of its three ESI detection paths: the ~2 minute Manager Core fast-poll and the ~10 minute MC sweep both ride on MC\'s shared director key pool. Without them, structure attack / reinforcement-timer / fuel alerts fall back to the native ~15-20 minute sweep, so the most time-critical notifications often arrive too late to act on' },
                { f:'mc', t:'mm', mc:true, k:'data', l:'centralised ore pricing (falls back to local price sources)' },
                { f:'mc', t:'bb', mc:true, k:'data', l:'Manager Core pricing for appraisals (falls back to its own chain)' },
                { f:'price', t:'mc', mc:false, k:'data', l:'the optional seat-prices source in the pricing chain (Mining and Buyback price through it)' },
                { f:'fit', t:'pings', mc:false, k:'data', l:'attaching fitting doctrines to broadcasts' },
                { f:'pings', t:'mm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'sm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'cwm', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'pings', t:'bb', mc:false, k:'pick', l:'the curated Broadcast role list in its Discord role picker' },
                { f:'conn', t:'hr', mc:false, k:'pick', l:'Connector roles in its Discord role picker, plus Discord-role-based activity tiers' },
                { f:'conn', t:'mm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'sm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'cwm', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'bb', mc:false, k:'pick', l:'Connector roles in its Discord role picker' },
                { f:'conn', t:'pings', mc:false, k:'pick', l:'Connector roles in its Discord role picker' }
            ];

            var absent = {};
            var nodes = canvas.querySelectorAll('.fm-node');

            nodes.forEach(function (n) {
                var id = n.getAttribute('data-id');
                if (POS[id]) { n.style.left = POS[id][0] + '%'; n.style.top = POS[id][1] + '%'; }
                n.addEventListener('click', function () { absent[id] = !absent[id]; render(); });
                n.addEventListener('mouseenter', function () { highlight(id); });
                n.addEventListener('mouseleave', clearHighlight);
            });

            FLOWS.forEach(function (fl, i) {
                var a = POS[fl.f], b = POS[fl.t];
                if (!a || !b) return;
                var isExt = !!(EXT[fl.f] || EXT[fl.t]);
                var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', a[0]); line.setAttribute('y1', a[1]);
                line.setAttribute('x2', b[0]); line.setAttribute('y2', b[1]);
                line.setAttribute('stroke', isExt ? '#fbbf24' : 'url(#fmGradFam)');
                line.setAttribute('class', 'fm-edge ' + fl.k);
                line.setAttribute('data-i', i);
                svg.appendChild(line);
            });

            function highlight(id) {
                var relE = {};
                FLOWS.forEach(function (fl, i) { if (fl.f === id || fl.t === id) relE[i] = true; });
                svg.querySelectorAll('.fm-edge').forEach(function (line) { line.classList.toggle('hl', !!relE[+line.getAttribute('data-i')]); });
            }
            function clearHighlight() {
                svg.querySelectorAll('.fm-edge').forEach(function (line) { line.classList.remove('hl'); });
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
                svg.querySelectorAll('.fm-edge').forEach(function (line) {
                    var fl = FLOWS[+line.getAttribute('data-i')];
                    line.classList.toggle('broken', broken(fl) && !absent[fl.t]);
                });
                var anyAbsent = Object.keys(absent).some(function (k) { return absent[k]; });
                if (!anyAbsent) {
                    lossesEl.innerHTML = '<span class="fm-hint">Click a plugin to switch it off and see what the rest of the suite loses.</span>';
                    return;
                }
                var groups = {};
                FLOWS.forEach(function (fl) {
                    if (!broken(fl) || absent[fl.t]) return;
                    var cause = absent[fl.f] ? fl.f : 'mc';
                    if (!groups[cause]) groups[cause] = [];
                    groups[cause].push(NAME[fl.t] + ' loses ' + fl.l);
                });
                var html = '';
                Object.keys(groups).forEach(function (cause) {
                    var crit = (cause === 'mc');
                    var head = crit
                        ? '<i class="fas fa-exclamation-triangle"></i> Without <strong>Manager Core</strong> the shared layer (EventBus, Plugin Bridge, pricing) is gone, so:'
                        : 'Without <strong>' + NAME[cause] + '</strong>:';
                    html += '<div class="fm-loss-group ' + (crit ? 'fm-loss-crit' : '') + '"><div class="fm-loss-head">' + head + '</div><ul>';
                    groups[cause].forEach(function (item) { html += '<li>' + item + '</li>'; });
                    html += '</ul></div>';
                });
                lossesEl.innerHTML = html || '<span class="fm-hint">Nothing else depends on that one &mdash; it is a leaf consumer.</span>';
            }

            if (resetBtn) resetBtn.addEventListener('click', function () { absent = {}; render(); });
            render();
        } catch (e) { /* enhancement-only; never break the host page */ }
    })();
    </script>
</div>
