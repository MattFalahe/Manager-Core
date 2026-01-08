@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.appraisal'))
@section('page_header', trans('manager-core::manager-core.appraisal'))

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create New Appraisal</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.appraisal.create') }}">
                    @csrf

                    <div class="form-group">
                        <label for="raw_input">Items (paste from game)</label>
                        <textarea class="form-control" id="raw_input" name="raw_input" rows="10" required
                                  placeholder="Paste your items here...&#10;&#10;Supports: Inventory, Cargo Scan, Contract Items, and more"></textarea>
                        <small class="form-text text-muted">
                            Press <kbd>Ctrl+A</kbd> in EVE, then <kbd>Ctrl+C</kbd> to copy. Paste here with <kbd>Ctrl+V</kbd>
                        </small>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="market">Market</label>
                                <select class="form-control" id="market" name="market" required>
                                    <option value="">Select Market</option>
                                    @foreach($markets as $key => $market)
                                        <option value="{{ $key }}" {{ $key == 'jita' ? 'selected' : '' }}>
                                            {{ $market['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Choose which market to use for pricing</small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="price_percentage">Price Percentage</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="price_percentage"
                                           name="price_percentage" value="100" min="1" max="200" step="1">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    100% = market price, 90% = quick sale, 110% = markup
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="is_private" name="is_private" value="1">
                            <label class="custom-control-label" for="is_private">
                                Make this appraisal private (only you can view it)
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg" id="appraisal-submit-btn">
                        <i class="fas fa-calculator"></i> Create Appraisal
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                    <span class="sr-only">Loading...</span>
                </div>
                <h4 class="mb-3" id="loading-message">Hamsters are calculating hard...</h4>
                <div class="progress" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                </div>
                <p class="text-muted mt-3" id="loading-tip">This may take a moment for large appraisals</p>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const funMessages = [
        "Hamsters are calculating hard...",
        "Consulting the market wizards...",
        "Crunching the numbers...",
        "Negotiating with Jita traders...",
        "Spinning up the quantum calculators...",
        "Asking CONCORD for advice...",
        "Running the numbers through the wormhole...",
        "Bribing market analysts...",
        "Summoning the spreadsheet spirits...",
        "Teaching monkeys to do math...",
        "Counting all the ISK...",
        "Waking up the accountants...",
        "Consulting the EVE gods...",
        "Decrypting ancient price scrolls..."
    ];

    let messageInterval;
    let messageIndex = 0;

    $('form').on('submit', function(e) {
        // Show loading modal
        $('#loadingModal').modal('show');

        // Start with first message
        $('#loading-message').text(funMessages[0]);

        // Rotate messages every 3 seconds
        messageIndex = 1;
        messageInterval = setInterval(function() {
            $('#loading-message').fadeOut(300, function() {
                $(this).text(funMessages[messageIndex]).fadeIn(300);
                messageIndex = (messageIndex + 1) % funMessages.length;
            });
        }, 3000);

        // Count items to give better feedback
        const itemCount = $('#raw_input').val().trim().split('\n').filter(line => line.trim()).length;
        if (itemCount > 100) {
            $('#loading-tip').text(`Processing ${itemCount} items - this may take 30-60 seconds`);
        } else if (itemCount > 20) {
            $('#loading-tip').text(`Processing ${itemCount} items - almost done!`);
        } else {
            $('#loading-tip').text('Processing your items...');
        }
    });
});
</script>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent Appraisals</h3>
            </div>
            <div class="card-body">
                @if($recentAppraisals->isEmpty())
                    <p class="text-muted">No appraisals yet. Create one above to get started.</p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Market</th>
                                <th>Total Buy</th>
                                <th>Total Sell</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentAppraisals as $appraisal)
                            <tr>
                                <td>{{ $appraisal->appraisal_id }}</td>
                                <td>{{ strtoupper($appraisal->market) }}</td>
                                <td>{{ number_format($appraisal->total_buy, 2) }} ISK</td>
                                <td>{{ number_format($appraisal->total_sell, 2) }} ISK</td>
                                <td>{{ $appraisal->created_at->diffForHumans() }}</td>
                                <td>
                                    <a href="{{ route('manager-core.appraisal.show', $appraisal->appraisal_id) }}" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
