@extends('web::layouts.grids.12')

@section('title', 'Appraisal #' . $appraisal->appraisal_id)
@section('page_header', 'Appraisal #' . $appraisal->appraisal_id)

@section('full')
<div class="row">
    <div class="col-md-8">
        <div class="card">
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
                            <tr>
                                <td>
                                    {{ $item->type_name }}
                                    @if($item->is_bpc)
                                        <span class="badge badge-primary">BPC ({{ $item->bpc_runs }} runs)</span>
                                    @endif
                                    @if($item->is_fitted)
                                        <span class="badge badge-info">Fitted</span>
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
        <div class="card">
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
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-alt"></i> Raw Input</h3>
            </div>
            <div class="card-body">
                <pre class="bg-dark text-light p-2" style="max-height: 300px; overflow-y: auto; font-size: 0.85em;">{{ $appraisal->raw_input }}</pre>
            </div>
        </div>
        @endif

        @php
            $unparsedData = json_decode($appraisal->unparsed_lines, true) ?? [];
            $unparsedLines = $unparsedData['unparsed_lines'] ?? $unparsedData ?? [];
            $invalidItems = $unparsedData['invalid_items'] ?? [];
            $hasUnparsedLines = is_array($unparsedLines) && count($unparsedLines) > 0;
            $hasInvalidItems = is_array($invalidItems) && count($invalidItems) > 0;
        @endphp

        @if($hasUnparsedLines || $hasInvalidItems)
        <div class="card border-warning">
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
@endsection
