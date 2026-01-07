@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.type_subscriptions'))
@section('page_header', trans('manager-core::manager-core.type_subscriptions'))

@section('full')
<div class="row">
    <div class="col-md-12">
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-info"><i class="fas fa-list"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Subscriptions</span>
                        <span class="info-box-number">{{ number_format($stats['total_subscriptions']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-success"><i class="fas fa-cube"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unique Types</span>
                        <span class="info-box-number">{{ number_format($stats['unique_types']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-warning"><i class="fas fa-plug"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Plugins</span>
                        <span class="info-box-number">{{ $stats['plugins'] }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-danger"><i class="fas fa-map-marker-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Markets</span>
                        <span class="info-box-number">{{ $stats['markets'] }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subscribe Forms -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus"></i> Subscribe to Types</h3>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs" id="subscriptionTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="types-tab" data-toggle="tab" href="#types" role="tab">
                            <i class="fas fa-hashtag"></i> By Type IDs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="category-tab" data-toggle="tab" href="#category" role="tab">
                            <i class="fas fa-folder"></i> By Category
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="group-tab" data-toggle="tab" href="#group" role="tab">
                            <i class="fas fa-layer-group"></i> By Group
                        </a>
                    </li>
                </ul>

                <div class="tab-content mt-3">
                    <!-- By Type IDs -->
                    <div class="tab-pane fade show active" id="types" role="tabpanel">
                        <form method="POST" action="{{ route('manager-core.subscriptions.subscribe-types') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="type_ids">Type IDs</label>
                                        <textarea class="form-control" id="type_ids" name="type_ids" rows="4" required
                                                  placeholder="Enter type IDs separated by commas or spaces&#10;Example: 34, 35, 36, 37&#10;or&#10;34 35 36 37"></textarea>
                                        <small class="form-text text-muted">You can paste multiple type IDs separated by commas or spaces</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="market_types">Market</label>
                                        <select class="form-control" id="market_types" name="market" required>
                                            @foreach($markets as $key => $market)
                                                <option value="{{ $key }}">{{ $market['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="plugin_name_types">Plugin Name</label>
                                        <input type="text" class="form-control" id="plugin_name_types" name="plugin_name" value="admin" required>
                                        <small class="form-text text-muted">Used to group subscriptions</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="priority_types">Priority (1-10)</label>
                                        <input type="number" class="form-control" id="priority_types" name="priority" value="5" min="1" max="10">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Subscribe to Types
                            </button>
                        </form>
                    </div>

                    <!-- By Category -->
                    <div class="tab-pane fade" id="category" role="tabpanel">
                        <form method="POST" action="{{ route('manager-core.subscriptions.subscribe-category') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="category_id">Category</label>
                                        <select class="form-control" id="category_id" name="category_id" required>
                                            <option value="">Loading categories...</option>
                                        </select>
                                        <small class="form-text text-muted">Subscribe to all published types in a category</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="market_category">Market</label>
                                        <select class="form-control" id="market_category" name="market" required>
                                            @foreach($markets as $key => $market)
                                                <option value="{{ $key }}">{{ $market['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="plugin_name_category">Plugin Name</label>
                                        <input type="text" class="form-control" id="plugin_name_category" name="plugin_name" value="admin" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="priority_category">Priority (1-10)</label>
                                        <input type="number" class="form-control" id="priority_category" name="priority" value="5" min="1" max="10">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Subscribe to Category
                            </button>
                        </form>
                    </div>

                    <!-- By Group -->
                    <div class="tab-pane fade" id="group" role="tabpanel">
                        <form method="POST" action="{{ route('manager-core.subscriptions.subscribe-group') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="group_category">Category</label>
                                        <select class="form-control" id="group_category" required>
                                            <option value="">Loading categories...</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="group_id">Group</label>
                                        <select class="form-control" id="group_id" name="group_id" required disabled>
                                            <option value="">Select a category first...</option>
                                        </select>
                                        <small class="form-text text-muted">Subscribe to all published types in a group</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="market_group">Market</label>
                                        <select class="form-control" id="market_group" name="market" required>
                                            @foreach($markets as $key => $market)
                                                <option value="{{ $key }}">{{ $market['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="plugin_name_group">Plugin Name</label>
                                        <input type="text" class="form-control" id="plugin_name_group" name="plugin_name" value="admin" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="priority_group">Priority (1-10)</label>
                                        <input type="number" class="form-control" id="priority_group" name="priority" value="5" min="1" max="10">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Subscribe to Group
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Subscriptions -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list"></i> Current Subscriptions</h3>
            </div>
            <div class="card-body">
                @if($subscriptions->isEmpty())
                    <p class="text-muted">No subscriptions yet. Subscribe to types above to start tracking prices.</p>
                @else
                    @foreach($subscriptions as $market => $marketSubs)
                        <h4>{{ strtoupper($market) }} Market</h4>
                        @php
                            $pluginGroups = $marketSubs->groupBy('plugin_name');
                        @endphp
                        @foreach($pluginGroups as $plugin => $pluginSubs)
                            <div class="card mb-3">
                                <div class="card-header">
                                    <strong>{{ $plugin }}</strong>
                                    <span class="badge badge-secondary">{{ $pluginSubs->count() }} types</span>
                                    <div class="card-tools">
                                        <form method="POST" action="{{ route('manager-core.subscriptions.clear-plugin') }}" style="display: inline;"
                                              onsubmit="return confirm('Remove all {{ $pluginSubs->count() }} subscriptions for {{ $plugin }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="plugin_name" value="{{ $plugin }}">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Clear All
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>Type ID</th>
                                                <th>Type Name</th>
                                                <th>Priority</th>
                                                <th>Subscribed</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($pluginSubs as $sub)
                                            <tr>
                                                <td>{{ $sub->type_id }}</td>
                                                <td>{{ $sub->type->typeName ?? 'Unknown' }}</td>
                                                <td>
                                                    <span class="badge badge-{{ $sub->priority >= 7 ? 'danger' : ($sub->priority >= 4 ? 'warning' : 'secondary') }}">
                                                        {{ $sub->priority }}
                                                    </span>
                                                </td>
                                                <td>{{ $sub->created_at->diffForHumans() }}</td>
                                                <td>
                                                    <form method="POST" action="{{ route('manager-core.subscriptions.unsubscribe', $sub->id) }}" style="display: inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-xs btn-danger">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Load categories
    function loadCategories() {
        $.get('{{ route('manager-core.subscriptions.categories') }}', function(data) {
            const categorySelect = $('#category_id, #group_category');
            categorySelect.empty();
            categorySelect.append('<option value="">Select a category...</option>');
            data.forEach(function(category) {
                categorySelect.append(`<option value="${category.categoryID}">${category.categoryName}</option>`);
            });
        });
    }

    // Load groups when category is selected
    $('#group_category').change(function() {
        const categoryId = $(this).val();
        const groupSelect = $('#group_id');

        if (!categoryId) {
            groupSelect.prop('disabled', true);
            groupSelect.html('<option value="">Select a category first...</option>');
            return;
        }

        groupSelect.prop('disabled', true);
        groupSelect.html('<option value="">Loading groups...</option>');

        $.get(`{{ url('manager-core/subscriptions/groups') }}/${categoryId}`, function(data) {
            groupSelect.empty();
            groupSelect.append('<option value="">Select a group...</option>');
            data.forEach(function(group) {
                groupSelect.append(`<option value="${group.groupID}">${group.groupName}</option>`);
            });
            groupSelect.prop('disabled', false);
        });
    });

    loadCategories();
});
</script>
@endpush
@endsection
