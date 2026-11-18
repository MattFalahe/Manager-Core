{{-- The cells every market row ends with: last refresh, plugins using it,
     the enable toggle and the actions. --}}
<td class="mk-dim">
    @if($market->last_refresh_at)
        {{ $market->last_refresh_at->diffForHumans() }}
        <br>
        @if($market->last_refresh_status === \ManagerCore\Models\Market::STATUS_OK)
            <span class="mk-status-ok"><i class="fas fa-check-circle"></i> OK</span>
        @else
            <span class="mk-status-err" data-toggle="tooltip" title="{{ $market->last_refresh_error }}">
                <i class="fas fa-exclamation-circle"></i> {{ $market->last_refresh_status ?? 'error' }}
            </span>
        @endif
    @else
        <span class="mk-meta">never</span>
    @endif
</td>
<td class="mk-dim">
    @php $count = (int) ($pluginPreferences[$market->key] ?? 0); @endphp
    @if($count > 0)
        <strong>{{ $count }} plugin(s)</strong>
    @else
        <span class="mk-meta">0</span>
    @endif
</td>
<td>
    <form method="POST" action="{{ route('manager-core.markets.toggle', $market->id) }}" style="display:inline;">
        @csrf
        <button type="submit" class="btn btn-sm {{ $market->is_enabled ? 'btn-success' : 'btn-secondary' }}">
            {{ $market->is_enabled ? 'Enabled' : 'Disabled' }}
        </button>
    </form>
</td>
<td class="mk-actions">
    <form method="POST" action="{{ route('manager-core.markets.test', $market->id) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-info" title="Run a real fetch right now and report success/failure">
            <i class="fas fa-vial"></i> Test
        </button>
    </form>
    {{-- The seeded markets stay fixed: a changed Goonpraisal slug it doesn't
         track would quietly fall back to Jita. Markets the operator added
         can be edited. --}}
    @if($market->is_custom)
        <a href="{{ route('manager-core.markets.edit', $market->id) }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-edit"></i> Edit
        </a>
        <form method="POST" action="{{ route('manager-core.markets.destroy', $market->id) }}"
              onsubmit="return confirm('Delete this market?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                <i class="fas fa-trash"></i>
            </button>
        </form>
    @endif
</td>
