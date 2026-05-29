@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.type_subscriptions'))
@section('page_header', trans('manager-core::manager-core.type_subscriptions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=2">
<style>
    /* Dark-theme overrides for nav-tabs + pagination inside the
       Current Subscriptions card. Bootstrap's default nav-tab colors
       are light-mode optimised — we tint them to read against the
       .card-dark chrome. Scoped via #mc-subs-tabs so we can't bleed
       to other pages that use nav-tabs differently. */
    #mc-subs-tabs {
        border-bottom: 1px solid #2c3138;
    }
    #mc-subs-tabs .nav-link {
        color: #a5b4fc;
        background: transparent;
        border-color: transparent;
        margin-bottom: -1px;
        padding: 8px 14px;
    }
    #mc-subs-tabs .nav-link:hover {
        background: rgba(102, 126, 234, 0.10);
        border-color: transparent;
        color: #c7d2fe;
    }
    #mc-subs-tabs .nav-link.active {
        background: #23262d;
        color: #fff;
        border-color: #2c3138 #2c3138 #23262d;
    }
    #mc-subs-tabs .nav-link .badge {
        font-size: 0.7rem;
    }
    /* Pagination controls — keep buttons compact on dark bg */
    .mc-subs-pager button.btn-outline-secondary {
        color: #c2c7d0;
        border-color: #2c3138;
        background: transparent;
    }
    .mc-subs-pager button.btn-outline-secondary:hover:not(:disabled) {
        background: rgba(102, 126, 234, 0.15);
        color: #fff;
        border-color: rgba(102, 126, 234, 0.4);
    }
    .mc-subs-pager button:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">
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
        <div class="card card-dark">
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
                                        <label for="priority_types">Priority (1-10) <small class="text-muted">— advisory</small></label>
                                        <input type="number" class="form-control" id="priority_types" name="priority" value="5" min="1" max="10">
                                        <small class="form-text text-muted">Stored for future fetch-budget prioritisation; not currently consulted by the price-update cron.</small>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-mc-primary">
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
                                        <label for="priority_category">Priority (1-10) <small class="text-muted">— advisory</small></label>
                                        <input type="number" class="form-control" id="priority_category" name="priority" value="5" min="1" max="10">
                                        <small class="form-text text-muted">Stored for future fetch-budget prioritisation; not currently consulted by the price-update cron.</small>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-mc-primary">
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
                                        <label for="priority_group">Priority (1-10) <small class="text-muted">— advisory</small></label>
                                        <input type="number" class="form-control" id="priority_group" name="priority" value="5" min="1" max="10">
                                        <small class="form-text text-muted">Stored for future fetch-budget prioritisation; not currently consulted by the price-update cron.</small>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-mc-primary">
                                <i class="fas fa-plus"></i> Subscribe to Group
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Current Subscriptions.

             Tabs-per-market layout (one Bootstrap nav-tab per market with
             subscriptions). Within each tab the plugin groupings render the
             same plugin-card / table structure as before — but tables with
             more than MC_SUBS_PAGE_SIZE rows get client-side pagination
             controls (rendered + handled by the inline JS at bottom of
             this view). Active tab persists via URL hash so reload /
             back-button preserves the operator's place.

             Server still hands the full subscription set to the view (no
             server-side pagination) — the JS just hides/shows rows. Keeps
             the controller simple + lets operators jump to any page
             without a page reload. ~120 LOC ceiling for the JS. --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list"></i> Current Subscriptions</h3>
                <div class="card-tools">
                    <small class="text-muted">
                        Rows per table:
                        <select id="mc-subs-page-size" class="form-control form-control-sm d-inline-block" style="width: auto; padding: 2px 6px; height: auto; font-size: 0.85rem;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="0">All</option>
                        </select>
                    </small>
                </div>
            </div>
            <div class="card-body">
                @if($subscriptions->isEmpty())
                    <p class="text-muted">No subscriptions yet. Subscribe to types above to start tracking prices.</p>
                @else
                    {{-- Per-market tab nav. Sort markets so the largest
                         subscription count surfaces first (most operators
                         care about their busiest market). --}}
                    @php
                        $marketOrder = $subscriptions->sortByDesc(fn($s) => $s->count())->keys()->all();
                        $firstMarket = $marketOrder[0] ?? null;
                    @endphp
                    <ul class="nav nav-tabs" id="mc-subs-tabs" role="tablist" style="margin-bottom: 1rem;">
                        @foreach($marketOrder as $market)
                            @php
                                $marketKey = strtolower($market);
                                $marketName = $markets[$market]['name'] ?? strtoupper($market);
                                $isFirst = ($market === $firstMarket);
                                $count = $subscriptions[$market]->count();
                            @endphp
                            <li class="nav-item">
                                <a class="nav-link {{ $isFirst ? 'active' : '' }}"
                                   id="mc-subs-tab-{{ $marketKey }}"
                                   data-toggle="tab"
                                   href="#mc-subs-pane-{{ $marketKey }}"
                                   role="tab"
                                   data-market-key="{{ $marketKey }}">
                                    <i class="fas fa-map-marker-alt"></i>
                                    {{ $marketName }}
                                    <span class="badge badge-secondary" style="margin-left: 4px;">{{ $count }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content" id="mc-subs-tabs-content">
                    @foreach($marketOrder as $market)
                        @php
                            $marketSubs = $subscriptions[$market];
                            $marketKey = strtolower($market);
                            $isFirst = ($market === $firstMarket);
                            $pluginGroups = $marketSubs->groupBy('plugin_name');
                        @endphp
                        <div class="tab-pane fade {{ $isFirst ? 'show active' : '' }}"
                             id="mc-subs-pane-{{ $marketKey }}"
                             role="tabpanel">
                            @foreach($pluginGroups as $plugin => $pluginSubs)
                                @php
                                    $pluginInfo = \ManagerCore\Models\TypeSubscription::PLUGIN_MANAGED[$plugin] ?? null;
                                    $isManaged = $pluginInfo !== null;
                                    $tableId = 'mc-subs-table-' . $marketKey . '-' . preg_replace('/[^a-z0-9]/i', '-', $plugin);
                                @endphp
                                <div class="card mb-3 {{ $isManaged ? 'card-outline card-' . $pluginInfo['color'] : '' }}">
                                    <div class="card-header">
                                        @if($isManaged)
                                            <i class="{{ $pluginInfo['icon'] }}"></i>
                                            <strong>{{ $pluginInfo['label'] }}</strong>
                                            <span class="badge badge-{{ $pluginInfo['color'] }}"><i class="fas fa-lock"></i> Plugin Managed</span>
                                        @else
                                            <i class="fas fa-user"></i>
                                            <strong>{{ $plugin }}</strong>
                                            <span class="badge badge-secondary"><i class="fas fa-hand-paper"></i> Manual</span>
                                        @endif
                                        <span class="badge badge-secondary">{{ $pluginSubs->count() }} types</span>
                                        <div class="card-tools">
                                            @if($isManaged)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Managed by {{ $pluginInfo['label'] }} — unsubscribe from that plugin's settings">
                                                    <i class="fas fa-lock"></i> Protected
                                                </button>
                                            @else
                                                <form method="POST" action="{{ route('manager-core.subscriptions.clear-plugin') }}" style="display: inline;"
                                                      onsubmit="return confirm('Remove all {{ $pluginSubs->count() }} subscriptions for {{ $plugin }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="plugin_name" value="{{ $plugin }}">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i> Clear All
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                    @if($isManaged)
                                    <div class="card-body py-2 px-3" style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                                        <small class="text-muted"><i class="fas fa-info-circle"></i> {{ $pluginInfo['description'] }}</small>
                                    </div>
                                    @endif
                                    <div class="card-body p-0">
                                        <table class="table table-sm table-striped mb-0 mc-subs-table" id="{{ $tableId }}" data-total-rows="{{ $pluginSubs->count() }}">
                                            <thead>
                                                <tr>
                                                    <th>Type ID</th>
                                                    <th>Type Name</th>
                                                    <th>Priority</th>
                                                    <th>Subscribed</th>
                                                    @if(!$isManaged)
                                                    <th>Actions</th>
                                                    @endif
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($pluginSubs as $sub)
                                                <tr class="mc-subs-row">
                                                    <td>{{ $sub->type_id }}</td>
                                                    <td>{{ $sub->type->typeName ?? 'Unknown' }}</td>
                                                    <td>
                                                        <span class="badge badge-{{ $sub->priority >= 7 ? 'danger' : ($sub->priority >= 4 ? 'warning' : 'secondary') }}">
                                                            {{ $sub->priority }}
                                                        </span>
                                                    </td>
                                                    <td>{{ $sub->created_at->diffForHumans() }}</td>
                                                    @if(!$isManaged)
                                                    <td>
                                                        <form method="POST" action="{{ route('manager-core.subscriptions.unsubscribe', $sub->id) }}" style="display: inline;">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-xs btn-danger">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                    @endif
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        {{-- Pagination controls — populated by the JS at bottom of this view.
                                             Hidden when table fits in one page (rows <= page size). --}}
                                        <div class="mc-subs-pager" data-target-table="{{ $tableId }}" style="display:none; padding: 8px 12px; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.15); font-size: 0.85rem;">
                                            <span class="mc-subs-pager-info text-muted" style="margin-right: 12px;"></span>
                                            <div class="mc-subs-pager-buttons" style="display: inline-block;"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                    </div>
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

/**
 * Current Subscriptions: client-side per-table pagination + tab persistence.
 *
 * Server hands the full subscription set down — no AJAX. This script just
 * hides/shows rows and renders pager controls per table when the table
 * is larger than the chosen page size. Page size + active tab persist
 * via URL hash so reload / back-button doesn't lose operator context.
 *
 * Hash format: #market=jita&page-mc-subs-table-jita-mining-manager=2
 * - market=X sets the active tab
 * - page-{tableId}=N sets the active page for that table
 *
 * Keeping it pure-vanilla so we don't need Bootstrap tab JS imports.
 * The tabs ARE bootstrap-class-styled but the show/hide logic is local.
 */
(function ($) {
    var pageSize = 25; // default; overridden by #mc-subs-page-size dropdown

    function getHashParams() {
        var hash = window.location.hash.replace(/^#/, '');
        var out = {};
        hash.split('&').forEach(function (kv) {
            if (!kv) return;
            var parts = kv.split('=');
            if (parts.length === 2) out[parts[0]] = decodeURIComponent(parts[1]);
        });
        return out;
    }

    function setHashParam(key, value) {
        var params = getHashParams();
        if (value === null || value === '' || value === undefined) {
            delete params[key];
        } else {
            params[key] = value;
        }
        var hash = Object.keys(params).map(function (k) {
            return k + '=' + encodeURIComponent(params[k]);
        }).join('&');
        history.replaceState(null, '', hash ? '#' + hash : window.location.pathname + window.location.search);
    }

    function renderPager(table) {
        var $table  = $(table);
        var $rows   = $table.find('tbody tr.mc-subs-row');
        var total   = $rows.length;
        var tableId = $table.attr('id');
        var $pager  = $('.mc-subs-pager[data-target-table="' + tableId + '"]');

        // pageSize=0 means "show all"
        if (pageSize === 0 || total <= pageSize) {
            $rows.show();
            $pager.hide();
            return;
        }

        var totalPages = Math.ceil(total / pageSize);
        var currentPage = parseInt(getHashParams()['page-' + tableId] || '1', 10);
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;

        var startIdx = (currentPage - 1) * pageSize;
        var endIdx   = startIdx + pageSize;

        $rows.each(function (i) {
            $(this).toggle(i >= startIdx && i < endIdx);
        });

        // Info text
        $pager.find('.mc-subs-pager-info').text(
            'Showing ' + (startIdx + 1) + '–' + Math.min(endIdx, total) + ' of ' + total
        );

        // Button row: prev + numbered (with ellipses for many pages) + next
        var $btns = $pager.find('.mc-subs-pager-buttons').empty();
        function btn(label, page, isActive, isDisabled, title) {
            var $b = $('<button type="button" class="btn btn-xs ' + (isActive ? 'btn-primary' : 'btn-outline-secondary') + '" style="margin: 0 2px;"></button>')
                .text(label)
                .attr('data-page', page);
            if (isDisabled) $b.prop('disabled', true);
            if (title)      $b.attr('title', title);
            $btns.append($b);
            return $b;
        }
        btn('‹ Prev', currentPage - 1, false, currentPage === 1);
        // Show all pages if 7 or fewer; otherwise first + last + window around current
        var pageNumbers = [];
        if (totalPages <= 7) {
            for (var p = 1; p <= totalPages; p++) pageNumbers.push(p);
        } else {
            pageNumbers.push(1);
            if (currentPage > 3) pageNumbers.push('...');
            for (var p2 = Math.max(2, currentPage - 1); p2 <= Math.min(totalPages - 1, currentPage + 1); p2++) {
                pageNumbers.push(p2);
            }
            if (currentPage < totalPages - 2) pageNumbers.push('...');
            pageNumbers.push(totalPages);
        }
        pageNumbers.forEach(function (p) {
            if (p === '...') {
                $btns.append('<span style="margin: 0 4px; color:#8b95a5;">…</span>');
            } else {
                btn(String(p), p, p === currentPage, false);
            }
        });
        btn('Next ›', currentPage + 1, false, currentPage === totalPages);

        $pager.show();
    }

    function rerenderAllTables() {
        $('.mc-subs-table').each(function () { renderPager(this); });
    }

    // Tab activation: vanilla show/hide (no Bootstrap tab.js dependency)
    $(document).on('click', '#mc-subs-tabs a[data-toggle="tab"]', function (e) {
        e.preventDefault();
        var target = $(this).attr('href');
        var marketKey = $(this).data('market-key');
        $('#mc-subs-tabs .nav-link').removeClass('active');
        $(this).addClass('active');
        $('#mc-subs-tabs-content .tab-pane').removeClass('show active');
        $(target).addClass('show active');
        setHashParam('market', marketKey);
    });

    // Page button clicks (delegated since pagers re-render)
    $(document).on('click', '.mc-subs-pager-buttons button', function () {
        var $btn      = $(this);
        var page      = parseInt($btn.attr('data-page'), 10);
        var $pager    = $btn.closest('.mc-subs-pager');
        var tableId   = $pager.data('target-table');
        setHashParam('page-' + tableId, page);
        renderPager(document.getElementById(tableId));
        // Scroll the table back into view so operator doesn't lose their place
        $('#' + tableId).get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // Page-size dropdown
    $('#mc-subs-page-size').on('change', function () {
        pageSize = parseInt($(this).val(), 10);
        // Reset page numbers to 1 across all tables since boundaries shift
        var params = getHashParams();
        Object.keys(params).forEach(function (k) {
            if (k.indexOf('page-') === 0) delete params[k];
        });
        var hash = Object.keys(params).map(function (k) {
            return k + '=' + encodeURIComponent(params[k]);
        }).join('&');
        history.replaceState(null, '', hash ? '#' + hash : window.location.pathname + window.location.search);
        rerenderAllTables();
    });

    $(document).ready(function () {
        // Restore tab from hash if present
        var hashParams = getHashParams();
        if (hashParams.market) {
            var $tab = $('#mc-subs-tab-' + hashParams.market);
            if ($tab.length) $tab.trigger('click');
        }
        rerenderAllTables();
    });
})(jQuery);
</script>
@endpush
</div>
@endsection
