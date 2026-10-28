{{-- One plugin's card on the Diagnostics Plugin Connections tab, for the suite
     and for compatible plugins alike. Expects $key and $plugin from the loop. --}}
<div class="plugin-card" id="plugin-card-{{ $key }}">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <i class="fas {{ $plugin['icon'] ?? 'fa-puzzle-piece' }}" style="color: #17a2b8; margin-right: 8px;"></i>
            <span class="plugin-name">{{ $plugin['name'] }}</span>
            <span class="badge-status badge-inactive" id="status-{{ $key }}">Untested</span>
        </div>
        <button class="btn btn-mc btn-sm" onclick="testPlugin('{{ $key }}')"><i class="fas fa-stethoscope"></i> Test</button>
    </div>
    <div class="plugin-package">{{ $plugin['package'] }}</div>
    <div class="plugin-details" id="details-{{ $key }}" style="display: none;"></div>
</div>
