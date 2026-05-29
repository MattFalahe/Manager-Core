@extends('web::layouts.grids.12')

@section('title', 'Appraisal #' . $appraisal->appraisal_id)
@section('page_header', 'Appraisal #' . $appraisal->appraisal_id)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=1">
@endpush

@section('full')
<div class="manager-core-wrapper">
<div class="row">
    <div class="col-md-8">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-calculator"></i> Appraisal Details
                </h3>
                <div class="card-tools">
                    @if($appraisal->is_private)
                        <span class="badge badge-warning"><i class="fas fa-lock"></i> Private</span>
                    @endif
                    <span class="badge badge-info">{{ strtoupper($appraisal->market) }}</span>
                    @if($appraisal->price_percentage != 100)
                        <span class="badge badge-secondary">{{ $appraisal->price_percentage }}%</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="info-box bg-success">
                            <span class="info-box-icon"><i class="fas fa-arrow-up"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Buy (Sell Orders)</span>
                                <span class="info-box-number">{{ number_format($appraisal->total_buy, 2) }} ISK</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-danger">
                            <span class="info-box-icon"><i class="fas fa-arrow-down"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Sell (Buy Orders)</span>
                                <span class="info-box-number">{{ number_format($appraisal->total_sell, 2) }} ISK</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-info">
                            <span class="info-box-icon"><i class="fas fa-cube"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Volume</span>
                                <span class="info-box-number">{{ number_format($appraisal->total_volume, 2) }} m³</span>
                            </div>
                        </div>
                    </div>
                </div>

                <h4>Items ({{ $appraisal->items->count() }})</h4>

                {{-- Jita-fallback banner: shown when the configured market had
                     no orders for some items and Jita was used as a backup.
                     Each affected row also gets a per-row "Jita fallback" badge. --}}
                @php
                    $parserInfoArr = $appraisal->parser_info;
                    if (!is_array($parserInfoArr)) { $parserInfoArr = []; }
                    $fallbackCount = $parserInfoArr['jita_fallback_count'] ?? 0;
                @endphp
                @if($fallbackCount > 0 && $appraisal->market !== 'jita')
                    <div class="alert" style="background: rgba(255, 193, 7, 0.12); border: 1px solid rgba(255, 193, 7, 0.45); border-left: 4px solid #ffc107; color: #d1d5db; margin-bottom: 14px;">
                        <i class="fas fa-info-circle" style="color: #ffc107;"></i>
                        <strong style="color: #ffc107;">{{ $fallbackCount }} item{{ $fallbackCount === 1 ? '' : 's' }} priced via Jita fallback</strong>
                        had no buy or sell orders in <code>{{ $appraisal->market }}</code>, so Jita prices were used instead. Fallback rows are tagged below.
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Volume</th>
                                <th class="text-right">Buy Price</th>
                                <th class="text-right">Sell Price</th>
                                <th class="text-right">Buy Total</th>
                                <th class="text-right">Sell Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($appraisal->items as $item)
                            @php
                                $priceSource = (is_array($item->prices) ? ($item->prices['source'] ?? null) : null);
                            @endphp
                            <tr>
                                <td>
                                    {{ $item->type_name }}
                                    @if($item->is_bpc)
                                        <span class="badge badge-primary">BPC ({{ $item->bpc_runs }} runs)</span>
                                    @endif
                                    @if($item->is_fitted)
                                        <span class="badge badge-info">Fitted</span>
                                    @endif
                                    @if($priceSource === 'jita_fallback')
                                        <span class="badge" style="background: rgba(255, 193, 7, 0.2); color: #ffc107; font-size: 0.72rem;" title="No orders in {{ $appraisal->market }} — priced using Jita">
                                            <i class="fas fa-info-circle"></i> Jita fallback
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($item->quantity) }}</td>
                                <td class="text-right">{{ number_format($item->total_volume, 2) }} m³</td>
                                <td class="text-right">{{ number_format($item->buy_price, 2) }}</td>
                                <td class="text-right">{{ number_format($item->sell_price, 2) }}</td>
                                <td class="text-right">{{ number_format($item->buy_total, 2) }}</td>
                                <td class="text-right">{{ number_format($item->sell_total, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="5">Total</td>
                                <td class="text-right">{{ number_format($appraisal->total_buy, 2) }} ISK</td>
                                <td class="text-right">{{ number_format($appraisal->total_sell, 2) }} ISK</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Information</h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-5">Appraisal ID</dt>
                    <dd class="col-sm-7"><code>{{ $appraisal->appraisal_id }}</code></dd>

                    <dt class="col-sm-5">Market</dt>
                    <dd class="col-sm-7">{{ strtoupper($appraisal->market) }}</dd>

                    <dt class="col-sm-5">Price Modifier</dt>
                    <dd class="col-sm-7">{{ $appraisal->price_percentage }}%</dd>

                    <dt class="col-sm-5">Parser</dt>
                    <dd class="col-sm-7">
                        <span class="badge badge-secondary">{{ $appraisal->kind ?? 'auto' }}</span>
                    </dd>

                    {{-- Price provider used for this appraisal. Null for legacy
                         rows (pre-migration 000021) or when the operator left
                         the form on "Use this market's configured provider" —
                         we surface that explicitly rather than hiding the row,
                         so the operator can always tell where the numbers came
                         from. --}}
                    <dt class="col-sm-5">Price Source</dt>
                    <dd class="col-sm-7">
                        @php
                            $providerLabels = [
                                'esi' => ['MCPraisal', 'badge-primary'],
                                'janice' => ['Janice', 'badge-warning'],
                                'fuzzwork' => ['Fuzzwork', 'badge-info'],
                                'goonpraisal' => ['Goonpraisal', 'badge-info'],
                                'seat' => ['SeAT Price Provider', 'badge-dark'],
                            ];
                            $provKey = $appraisal->price_provider ?? null;
                            $provInfo = $provKey ? ($providerLabels[$provKey] ?? [$provKey, 'badge-secondary']) : null;
                        @endphp
                        @if($provInfo)
                            <span class="badge {{ $provInfo[1] }}">{{ $provInfo[0] }}</span>
                        @else
                            <span class="badge badge-secondary" title="Provider not recorded (legacy appraisal, or operator chose 'Use this market\'s configured provider').">market provider</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5">Created</dt>
                    <dd class="col-sm-7">{{ $appraisal->created_at->format('Y-m-d H:i:s') }}</dd>

                    <dt class="col-sm-5">Age</dt>
                    <dd class="col-sm-7">{{ $appraisal->created_at->diffForHumans() }}</dd>

                    @if($appraisal->expires_at)
                    <dt class="col-sm-5">Expires</dt>
                    <dd class="col-sm-7">{{ $appraisal->expires_at->diffForHumans() }}</dd>
                    @endif

                    <dt class="col-sm-5">Items</dt>
                    <dd class="col-sm-7">{{ $appraisal->items->count() }}</dd>

                    <dt class="col-sm-5">Total Volume</dt>
                    <dd class="col-sm-7">{{ number_format($appraisal->total_volume, 2) }} m³</dd>
                </dl>

                <hr>

                <div class="btn-group-vertical btn-block">
                    <a href="{{ route('manager-core.appraisal.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to Appraisals
                    </a>
                    @if($appraisal->user_id == auth()->user()->id || auth()->user()->can('global.superuser'))
                    <form method="POST" action="{{ route('manager-core.appraisal.delete', $appraisal->appraisal_id) }}"
                          onsubmit="return confirm('Are you sure you want to delete this appraisal?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-block">
                            <i class="fas fa-trash"></i> Delete Appraisal
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        @if($appraisal->raw_input)
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-alt"></i> Raw Input</h3>
            </div>
            <div class="card-body">
                <pre class="bg-dark text-light p-2" style="max-height: 300px; overflow-y: auto; font-size: 0.85em;">{{ $appraisal->raw_input }}</pre>
            </div>
        </div>
        @endif

        @php
            $unparsedData = is_array($appraisal->unparsed_lines) ? $appraisal->unparsed_lines : (json_decode($appraisal->unparsed_lines, true) ?? []);
            $unparsedLines = $unparsedData['unparsed_lines'] ?? $unparsedData ?? [];
            $invalidItems = $unparsedData['invalid_items'] ?? [];
            $hasUnparsedLines = is_array($unparsedLines) && count($unparsedLines) > 0;
            $hasInvalidItems = is_array($invalidItems) && count($invalidItems) > 0;
        @endphp

        @if($hasUnparsedLines || $hasInvalidItems)
        <div class="card card-dark border-warning">
            <div class="card-header bg-warning">
                <h3 class="card-title"><i class="fas fa-exclamation-triangle"></i> Parsing Issues</h3>
            </div>
            <div class="card-body">
                @if($hasInvalidItems)
                    <h5 class="text-danger">Invalid Items</h5>
                    <p class="text-muted">The following items were not found in EVE Online database:</p>
                    <ul class="list-unstyled mb-3">
                        @foreach($invalidItems as $invalid)
                            <li>
                                <code>{{ $invalid['name'] ?? 'Unknown' }}</code>
                                @if(isset($invalid['quantity']))
                                    <span class="text-muted">(Qty: {{ number_format($invalid['quantity']) }})</span>
                                @endif
                                @if(isset($invalid['line']))
                                    <small class="text-muted">- Line {{ $invalid['line'] }}</small>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($hasUnparsedLines)
                    <h5 class="text-warning">Unparsed Lines</h5>
                    <p class="text-muted">The following lines could not be parsed:</p>
                    <ul class="list-unstyled">
                        @foreach($unparsedLines as $lineNum => $line)
                            <li>
                                @if(is_numeric($lineNum))
                                    <small class="text-muted">Line {{ $lineNum }}:</small>
                                @endif
                                <code>{{ $line }}</code>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
</div>
@endsection
