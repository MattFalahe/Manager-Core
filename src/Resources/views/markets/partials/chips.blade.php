@foreach($chips as $chip)
    <span class="mk-chip mk-chip-{{ $chip['state'] }}" data-toggle="tooltip" title="{{ $chip['title'] }}">
        @if(in_array($chip['state'], ['own', 'own-off'], true))
            <i class="fas fa-star"></i>
        @endif
        {{ $chip['label'] }}
        @if($chip['readers'] > 0)
            <span class="mk-chip-count">{{ $chip['readers'] }}</span>
        @endif
    </span>
@endforeach
