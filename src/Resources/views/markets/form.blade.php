@extends('web::layouts.grids.12')

@section('title', $isNew ? 'Add Custom Market' : 'Edit Market')
@section('page_header', $isNew ? 'Add Custom Market' : 'Edit Market')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=1">
<style>
    .mk-form-row { margin-bottom: 1rem; }
    .mk-form-row label { display: block; color: #c2c7d0; font-weight: 600; margin-bottom: 0.3rem; }
    .mk-form-row .mk-hint { color: #9aa3b3; font-size: 0.82rem; margin-top: 0.25rem; }
    .mk-citadel-only, .mk-hub-only { display: none; }
    .mk-citadel-only.active, .mk-hub-only.active { display: block; }
    .mk-provider-note {
        margin-top: 0.4rem;
        padding: 0.5rem 0.75rem;
        border-radius: 4px;
        font-size: 0.82rem;
        background: rgba(102, 126, 234, 0.10);
        border: 1px solid rgba(102, 126, 234, 0.30);
        color: #d1d5db;
    }
    .mk-provider-note.unavailable {
        background: rgba(220, 53, 69, 0.10);
        border-color: rgba(220, 53, 69, 0.30);
        color: #f17886;
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas {{ $isNew ? 'fa-plus' : 'fa-edit' }}"></i>
                {{ $isNew ? 'Add Custom Market' : 'Edit Market: ' . $market->name }}
            </h3>
        </div>
        <div class="card-body">

            <form method="POST"
                  action="{{ $isNew ? route('manager-core.markets.store') : route('manager-core.markets.update', $market->id) }}">
                @csrf
                @if(!$isNew)
                    @method('PUT')
                @endif

                <div class="row">
                    <div class="col-md-6">
                        <div class="mk-form-row">
                            <label for="name">Display Name *</label>
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name', $market->name) }}" required maxlength="255">
                            <div class="mk-hint">Human-friendly name shown in dropdowns: "1DQ1 - Keepstar", "Jita", etc.</div>
                        </div>

                        <div class="mk-form-row">
                            <label for="key">Key (URL-safe slug)</label>
                            <input type="text" name="key" id="key" class="form-control"
                                   value="{{ old('key', $market->key) }}"
                                   pattern="^[a-z0-9-_]+$" maxlength="64"
                                   {{ $isNew ? '' : 'readonly' }}>
                            <div class="mk-hint">
                                Lowercase, digits, hyphens, underscores only. Auto-generated if blank.
                                {{ $isNew ? '' : 'Read-only after creation to keep plugin pricing-preferences stable.' }}
                            </div>
                        </div>

                        <div class="mk-form-row">
                            <label for="market_type">Market Type *</label>
                            <select name="market_type" id="market_type" class="form-control" required>
                                <option value="hub" {{ old('market_type', $market->market_type) === 'hub' ? 'selected' : '' }}>
                                    Hub (public ESI region order book)
                                </option>
                                <option value="citadel" {{ old('market_type', $market->market_type) === 'citadel' ? 'selected' : '' }}>
                                    Citadel / Player Structure (third-party provider)
                                </option>
                            </select>
                            <div class="mk-hint">
                                Hub markets use ESI's public region endpoint. Citadel markets are priced via a third-party appraisal service since CCP's structure-orders endpoint is unreliable on large hubs.
                            </div>
                        </div>

                        <div class="mk-form-row">
                            <label for="provider">Pricing Provider *</label>
                            <select name="provider" id="provider" class="form-control" required>
                                @foreach($providers as $key => $info)
                                    <option value="{{ $key }}"
                                            {{ old('provider', $market->provider) === $key ? 'selected' : '' }}
                                            {{ $info['available'] ? '' : 'disabled' }}
                                            data-note="{{ $info['note'] }}"
                                            data-available="{{ $info['available'] ? '1' : '0' }}">
                                        {{ $info['label'] }}{{ $info['available'] ? '' : ' (not configured)' }}
                                    </option>
                                @endforeach
                            </select>
                            <div id="provider-note" class="mk-provider-note"></div>
                        </div>

                        <div class="mk-form-row" id="provider-slug-row">
                            <label for="provider_slug">Provider Market Slug</label>
                            <input type="text" name="provider_slug" id="provider_slug" class="form-control"
                                   value="{{ old('provider_slug', $market->provider_slug) }}"
                                   placeholder="e.g. insmother, jita"
                                   maxlength="64">
                            <div class="mk-hint">
                                Provider-specific market identifier.
                                For <strong>Goonpraisal</strong>: <code>insmother</code>, <code>tenerifis</code>, <code>catch</code>, <code>paragon-soul</code>, <code>esoteria</code>, <code>insmother-lawn</code>, <code>immensea</code>, <code>jita</code>, <code>amarr</code>, <code>dodixie</code>, <code>universe</code>.
                                For <strong>Janice</strong>: <code>jita</code> or <code>amarr</code>.
                                For <strong>ESI / Fuzzwork</strong>: usually leave blank — region_id + system_ids drive the lookup.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mk-form-row">
                            <label for="region_id">Region ID *</label>
                            <input type="number" name="region_id" id="region_id" class="form-control"
                                   value="{{ old('region_id', $market->region_id) }}" required min="1">
                            <div class="mk-hint">
                                EVE region ID. Examples: The Forge = <code>10000002</code>, Insmother = <code>10000009</code>, Domain = <code>10000043</code>.
                                Used for ESI/Fuzzwork providers; informational only for third-party-only markets.
                            </div>
                        </div>

                        <div class="mk-form-row">
                            <label for="system_id">System ID</label>
                            <input type="number" name="system_id" id="system_id" class="form-control"
                                   value="{{ old('system_id', $market->system_id) }}" min="1">
                            <div class="mk-hint">
                                EVE solar system ID (optional). Used for filtering hub-market orders to a specific system.
                            </div>
                        </div>

                        <div class="mk-form-row">
                            <label for="system_name">System Name (display)</label>
                            <input type="text" name="system_name" id="system_name" class="form-control"
                                   value="{{ old('system_name', $market->system_name) }}" maxlength="64">
                            <div class="mk-hint">Display label only, e.g. <code>C-J6MT</code> or <code>Jita</code>.</div>
                        </div>

                        <div class="mk-form-row mk-citadel-only">
                            <label for="structure_name">Structure Name (display)</label>
                            <input type="text" name="structure_name" id="structure_name" class="form-control"
                                   value="{{ old('structure_name', $market->structure_name) }}" maxlength="255">
                            <div class="mk-hint">
                                Optional display label for citadel markets, e.g. <code>1st Taj Mahgoon</code>.
                            </div>
                        </div>

                        <div class="mk-form-row">
                            <label>
                                <input type="hidden" name="is_enabled" value="0">
                                <input type="checkbox" name="is_enabled" value="1"
                                       {{ old('is_enabled', $market->is_enabled) ? 'checked' : '' }}>
                                Enabled
                            </label>
                            <div class="mk-hint">
                                Disabled markets are skipped by scheduled price refreshes and don't appear in dropdowns.
                            </div>
                        </div>
                    </div>
                </div>

                <hr style="border-color: #2c3138;">

                <div class="text-right">
                    <a href="{{ route('manager-core.markets.index') }}" class="btn btn-secondary mr-2">Cancel</a>
                    <button type="submit" class="btn btn-mc-primary">
                        <i class="fas {{ $isNew ? 'fa-plus' : 'fa-save' }}"></i>
                        {{ $isNew ? 'Add Market' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
$(function() {
    function showProviderNote() {
        var $sel = $('#provider');
        var $opt = $sel.find('option:selected');
        var note = $opt.data('note') || '';
        var available = String($opt.data('available')) === '1';
        var $box = $('#provider-note');
        $box.text(note).toggleClass('unavailable', !available);
    }

    function toggleTypeFields() {
        var t = $('#market_type').val();
        $('.mk-citadel-only').toggleClass('active', t === 'citadel');
        $('.mk-hub-only').toggleClass('active', t === 'hub');
    }

    $('#provider').on('change', showProviderNote);
    $('#market_type').on('change', toggleTypeFields);
    showProviderNote();
    toggleTypeFields();
});
</script>
@endpush
