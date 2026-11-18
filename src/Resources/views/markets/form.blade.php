@extends('web::layouts.grids.12')

@section('title', $isNew ? 'Add Market' : 'Edit Market')
@section('page_header', $isNew ? 'Add Market' : 'Edit Market')

@php
    $type = $fields['market_type'] === \ManagerCore\Models\Market::TYPE_HUB ? 'hub' : 'citadel';
    $idErrors = $errors->hasAny(['provider_slug', 'region_id', 'system_id', 'system_name', 'structure_name']);
    $formState = [
        'place' => $place,
        'unplaced' => $unplaced,
        'saved' => $savedProvider,
        'isNew' => $isNew,
        'marketId' => $isNew ? null : $market->id,
        'placesUrl' => route('manager-core.markets.places'),
        'editUrl' => route('manager-core.markets.edit', 0),
    ];
@endphp

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
@include('manager-core::markets.partials.styles')
<style>
    .mk-form-row { margin-bottom: 1.1rem; }
    .mk-form-row > label, .mk-step { display: block; color: #c2c7d0 !important; font-weight: 600; margin-bottom: 0.35rem; }
    .mk-hint { color: #9aa3b3 !important; font-size: 0.82rem; margin-top: 0.3rem; }
    .mk-error { color: #f17886 !important; font-size: 0.82rem; margin-top: 0.3rem; }

    .mk-seg { display: flex; flex-wrap: wrap; gap: 8px; }
    .mk-seg-option { flex: 1 1 240px; margin: 0; cursor: pointer; position: relative; }
    .mk-seg-option input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .mk-seg-option span {
        display: block; padding: 0.55rem 0.8rem; border: 1px solid #2c3138; border-radius: 6px;
        background: #20232b; color: #c2c7d0 !important;
    }
    .mk-seg-option span small { display: block; color: #9aa3b3 !important; font-weight: 400; margin-top: 0.1rem; }
    .mk-seg-option input:checked + span { border-color: #667eea; background: rgba(102, 126, 234, 0.14); color: #fff !important; }
    .mk-seg-option input:focus-visible + span { outline: 2px solid #667eea; outline-offset: 2px; }

    .mk-combo { position: relative; }
    .mk-results {
        position: absolute; z-index: 20; left: 0; right: 0; top: 100%; margin: 2px 0 0; padding: 4px 0;
        list-style: none; max-height: 340px; overflow-y: auto;
        background: #1c1f26; border: 1px solid #3a3f48; border-radius: 6px; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
    }
    .mk-result { padding: 0.4rem 0.75rem; cursor: pointer; }
    .mk-result strong { color: #fff !important; font-weight: 600; }
    .mk-result .mk-meta { display: block; }
    .mk-result.active, .mk-result:hover { background: rgba(102, 126, 234, 0.22); }
    .mk-result-kind { float: right; font-size: 0.7rem; color: #9aa3b3 !important; text-transform: uppercase; letter-spacing: 0.03em; }
    .mk-result-empty { padding: 0.5rem 0.75rem; color: #9aa3b3 !important; font-size: 0.85rem; }

    .mk-picked { border: 1px solid #667eea; border-radius: 6px; padding: 0.6rem 0.8rem; background: rgba(102, 126, 234, 0.08); }
    .mk-picked-head { display: flex; gap: 0.75rem; align-items: flex-start; }
    .mk-picked-head > div { flex: 1 1 auto; }
    .mk-picked-label { color: #fff !important; font-weight: 600; }
    .mk-existing { margin-top: 0.5rem; font-size: 0.85rem; color: #ffd75e !important; }
    .mk-existing a { color: #ffd75e !important; text-decoration: underline; }

    .mk-providers { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 8px; }
    .mk-provider { margin: 0; cursor: pointer; position: relative; }
    .mk-provider input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .mk-provider-body {
        display: block; height: 100%; padding: 0.5rem 0.75rem; border: 1px solid #2c3138; border-radius: 6px; background: #20232b;
    }
    .mk-provider-name { display: block; color: #fff !important; font-weight: 600; }
    .mk-provider-text { display: block; color: #9aa3b3 !important; font-size: 0.8rem; font-weight: 400; margin-top: 0.15rem; }
    .mk-provider input:checked + .mk-provider-body { border-color: #667eea; background: rgba(102, 126, 234, 0.14); }
    .mk-provider input:focus-visible + .mk-provider-body { outline: 2px solid #667eea; outline-offset: 2px; }
    .mk-provider.mk-provider-off { cursor: not-allowed; }
    .mk-provider.mk-provider-off .mk-provider-body { border-style: dashed; background: transparent; }
    .mk-provider.mk-provider-off .mk-provider-name { color: #8a919d !important; }
    .mk-provider.mk-provider-off .mk-provider-name::after { content: " \00B7  can't price it"; font-weight: 400; font-size: 0.75rem; }
    .mk-provider.mk-provider-setup .mk-provider-name::after { content: " \00B7  needs setting up"; font-weight: 400; font-size: 0.75rem; color: #ffd75e; }
    .mk-provider.mk-provider-saved .mk-provider-text { color: #ffd75e !important; }

    .mk-ids { border-top: 1px solid #2c3138; padding-top: 0.75rem; margin-top: 0.5rem; }
    .mk-ids summary { color: #c2c7d0 !important; cursor: pointer; font-weight: 600; margin-bottom: 0.75rem; }
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

    <div id="mk-note" class="alert alert-warning" {{ $note ? '' : 'hidden' }}>{{ $note }}</div>

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas {{ $isNew ? 'fa-plus' : 'fa-edit' }}"></i>
                {{ $isNew ? 'Add Market' : 'Edit Market: ' . $market->name }}
            </h3>
        </div>
        <div class="card-body">

            <form method="POST" id="mk-form"
                  action="{{ $isNew ? route('manager-core.markets.store') : route('manager-core.markets.update', $market->id) }}">
                @csrf
                @if(!$isNew)
                    @method('PUT')
                @endif

                <div class="mk-form-row">
                    <span class="mk-step">What kind of market?</span>
                    <div class="mk-seg" role="radiogroup" aria-label="What kind of market">
                        <label class="mk-seg-option">
                            <input type="radio" name="market_type" value="citadel" {{ $type === 'citadel' ? 'checked' : '' }}>
                            <span>
                                <strong>A player structure</strong>
                                <small>A citadel's market, read through ESI or priced by Goonpraisal</small>
                            </span>
                        </label>
                        <label class="mk-seg-option">
                            <input type="radio" name="market_type" value="hub" {{ $type === 'hub' ? 'checked' : '' }}>
                            <span>
                                <strong>A region's public market</strong>
                                <small>NPC station orders, found by system or region</small>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mk-form-row" id="mk-find">
                    <label for="mk-search">Find it</label>
                    <div class="mk-combo">
                        <input type="text" id="mk-search" class="form-control" autocomplete="off" spellcheck="false"
                               role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mk-results"
                               aria-describedby="mk-search-hint" maxlength="64">
                        <ul id="mk-results" class="mk-results" role="listbox" hidden></ul>
                    </div>
                    <div class="mk-hint" id="mk-search-hint"></div>
                </div>

                <div class="mk-form-row" id="mk-picked-row" hidden>
                    <span class="mk-step">Market at</span>
                    <div class="mk-picked">
                        <div class="mk-picked-head">
                            <div>
                                <div class="mk-picked-label" id="mk-picked-label"></div>
                                <div class="mk-meta" id="mk-picked-detail"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="mk-change">
                                <i class="fas fa-search"></i> Change
                            </button>
                        </div>
                        <div id="mk-existing" class="mk-existing" hidden></div>
                        <div id="mk-system-row" class="mt-3" hidden>
                            <label for="mk-system-search" class="mk-step">Its system</label>
                            <div class="mk-combo">
                                <input type="text" id="mk-system-search" class="form-control" autocomplete="off" spellcheck="false"
                                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mk-system-results"
                                       placeholder="System name" maxlength="64">
                                <ul id="mk-system-results" class="mk-results" role="listbox" hidden></ul>
                            </div>
                            <div class="mk-hint">SeAT hasn't seen this structure yet, so pick the system it's in. The region comes with it.</div>
                        </div>
                    </div>
                </div>

                <div class="mk-form-row">
                    <span class="mk-step">Priced by</span>
                    <div class="mk-providers" id="mk-providers" role="radiogroup" aria-label="Priced by">
                        @foreach($unplaced as $key => $info)
                            <label class="mk-provider" data-provider="{{ $key }}">
                                <input type="radio" name="provider" value="{{ $key }}" {{ $provider === $key ? 'checked' : '' }}>
                                <span class="mk-provider-body">
                                    <span class="mk-provider-name">{{ $info['label'] }}</span>
                                    <span class="mk-provider-text">{{ $info['note'] ?? $info['reason'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div class="mk-hint" id="mk-providers-hint"></div>
                    @error('provider')
                        <div class="mk-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mk-form-row">
                            <label for="name">Display name</label>
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name', $market->name) }}" required maxlength="255">
                            <div class="mk-hint">What plugins show in their market lists.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mk-form-row">
                            <label for="key">Key</label>
                            <input type="text" name="key" id="key" class="form-control"
                                   value="{{ old('key', $market->key) }}"
                                   pattern="[a-z0-9_\-]+" maxlength="{{ \ManagerCore\Models\Market::KEY_MAX_LENGTH }}"
                                   {{ $isNew ? '' : 'readonly' }}>
                            <div class="mk-hint">
                                @if($isNew)
                                    Lowercase letters, digits, hyphens and underscores, up to {{ \ManagerCore\Models\Market::KEY_MAX_LENGTH }} characters.
                                    Plugins' pricing preferences point at it, so it can't change once saved.
                                @else
                                    Plugins' pricing preferences point at it, so it stays as it was saved.
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mk-form-row">
                    <label class="mb-0">
                        <input type="hidden" name="is_enabled" value="0">
                        <input type="checkbox" name="is_enabled" value="1"
                               {{ old('is_enabled', $market->is_enabled) ? 'checked' : '' }}>
                        Enabled
                    </label>
                    <div class="mk-hint">A disabled market isn't refreshed and doesn't show in plugins' market lists.</div>
                </div>

                <details class="mk-ids" id="mk-ids" {{ $note || $idErrors ? 'open' : '' }}>
                    <summary>Show the IDs</summary>
                    <p class="mk-hint mt-0 mb-3">
                        Filled in from the place you pick. Type them here when SeAT doesn't know the place, and the form looks them up.
                    </p>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mk-form-row">
                                <label for="provider_slug">Provider market slug</label>
                                <input type="text" name="provider_slug" id="provider_slug" class="form-control mk-id"
                                       value="{{ $fields['provider_slug'] }}" maxlength="64" spellcheck="false">
                                <div class="mk-hint">
                                    What the provider is asked for: the structure ID for MCPraisal on a structure
                                    (<code>1049588174021</code>), the market for Goonpraisal (<code>C-J6MT</code>), the hub for Janice
                                    (<code>jita</code>). Empty for MCPraisal on a region's market.
                                </div>
                                @error('provider_slug')
                                    <div class="mk-error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mk-form-row">
                                <label for="structure_name">Structure name</label>
                                <input type="text" name="structure_name" id="structure_name" class="form-control"
                                       value="{{ $fields['structure_name'] }}" maxlength="255">
                                <div class="mk-hint">As SeAT names it. Only used for a player structure.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mk-form-row">
                                <label for="region_id">Region ID</label>
                                <input type="number" name="region_id" id="region_id" class="form-control mk-id"
                                       value="{{ $fields['region_id'] }}" min="1">
                                @error('region_id')
                                    <div class="mk-error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mk-form-row">
                                <label for="system_id">System ID</label>
                                <input type="number" name="system_id" id="system_id" class="form-control mk-id"
                                       value="{{ $fields['system_id'] }}" min="1">
                                <div class="mk-hint">For a region's market, reads that system's orders only. Empty reads the whole region.</div>
                            </div>
                            <div class="mk-form-row">
                                <label for="system_name">System name</label>
                                <input type="text" name="system_name" id="system_name" class="form-control"
                                       value="{{ $fields['system_name'] }}" maxlength="64">
                            </div>
                        </div>
                    </div>
                </details>

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
var mkFormState = @json($formState);

$(function () {
    var state = mkFormState;
    var place = null;
    var autoName = state.isNew && !$('#name').val();
    var autoKey = state.isNew && !$('#key').val();

    var hints = {
        citadel: {
            placeholder: 'Structure name, system or structure ID',
            hint: 'Type part of a structure\'s name, its system, or its ID. Fortizars and Keepstars are suggested, as the structures that can hold a market; any structure ID can be typed.'
        },
        hub: {
            placeholder: 'System or region name, or its ID',
            hint: 'A system reads only that system\'s orders. A region reads all of its public orders.'
        }
    };

    function marketType() {
        return $('input[name="market_type"]:checked').val() || 'citadel';
    }

    function showNote(note) {
        $('#mk-note').text(note || '').prop('hidden', !note);
        if (note) {
            $('#mk-ids').prop('open', true);
        }
    }

    // ----- Suggestions ---------------------------------------------------

    // One search box with its list: typing asks the server after a pause,
    // and only the latest answer is shown, however the requests return.
    function combo($input, $list, params, onPick) {
        var timer = null;
        var seq = 0;
        var items = [];
        var active = -1;

        function close() {
            $list.prop('hidden', true).empty();
            $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            items = [];
            active = -1;
        }

        function highlight(index) {
            var $options = $list.children('.mk-result');
            $options.removeClass('active').attr('aria-selected', 'false');
            active = index;
            if (index < 0) {
                $input.removeAttr('aria-activedescendant');
                return;
            }
            var $option = $options.eq(index).addClass('active').attr('aria-selected', 'true');
            $input.attr('aria-activedescendant', $option.attr('id'));
            var el = $option.get(0);
            if (el && el.scrollIntoView) {
                el.scrollIntoView({ block: 'nearest' });
            }
        }

        function render(results, emptyText) {
            $list.empty();
            items = results;
            active = -1;

            if (!results.length) {
                $list.append($('<li class="mk-result-empty" role="presentation">').text(emptyText));
            }

            $.each(results, function (i, result) {
                var $li = $('<li class="mk-result" role="option" aria-selected="false">')
                    .attr('id', $list.attr('id') + '-' + i)
                    .data('index', i);
                $li.append($('<span class="mk-result-kind">').text(kindLabel(result)));
                $li.append($('<strong>').text(result.label));
                if (result.detail) {
                    $li.append($('<span class="mk-meta">').text(result.detail));
                }
                if (result.existing && result.existing.length) {
                    $li.append($('<span class="mk-meta">').text('On the list as ' + $.map(result.existing, function (m) { return m.name; }).join(', ')));
                }
                $list.append($li);
            });

            $list.prop('hidden', false);
            $input.attr('aria-expanded', 'true');
        }

        function search() {
            var q = $.trim($input.val());
            var mine = ++seq;
            if (q.length < 2) {
                close();
                return;
            }

            $.getJSON(state.placesUrl, $.extend({ q: q, market_id: state.marketId || '' }, params()))
                .done(function (data) {
                    if (mine !== seq) {
                        return;
                    }
                    showNote(data.note);
                    render(data.results || [], emptyText());
                })
                .fail(function () {
                    if (mine === seq) {
                        render([], 'The search didn\'t answer. Fill in the IDs under Show the IDs instead.');
                    }
                });
        }

        function pick(index) {
            var result = items[index];
            if (result) {
                close();
                onPick(result);
            }
        }

        $input.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });

        $input.on('keydown', function (e) {
            var open = !$list.prop('hidden') && items.length;
            if (e.key === 'ArrowDown' && open) {
                e.preventDefault();
                highlight(Math.min(active + 1, items.length - 1));
            } else if (e.key === 'ArrowUp' && open) {
                e.preventDefault();
                highlight(Math.max(active - 1, 0));
            } else if (e.key === 'Enter') {
                // Enter picks a suggestion, and never sends the form from here.
                e.preventDefault();
                if (open) {
                    pick(active < 0 ? 0 : active);
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        // mousedown, so the pick lands before the box loses focus.
        $list.on('mousedown', '.mk-result', function (e) {
            e.preventDefault();
            pick($(this).data('index'));
        });

        $input.on('blur', function () {
            setTimeout(close, 150);
        });

        return { close: close };
    }

    function kindLabel(result) {
        return {
            structure: result.fields.structure_type || 'Structure',
            goonpraisal: 'Goonpraisal',
            system: 'System',
            region: 'Region',
            typed: 'Structure ID'
        }[result.kind] || '';
    }

    function emptyText() {
        return marketType() === 'hub'
            ? 'No system or region by that name.'
            : 'Nothing by that name. A structure SeAT hasn\'t seen can be typed by its ID.';
    }

    var mainSearch = combo($('#mk-search'), $('#mk-results'), function () {
        return { type: marketType() };
    }, function (result) {
        usePlace(result, false);
    });

    combo($('#mk-system-search'), $('#mk-system-results'), function () {
        return { type: 'citadel', only: 'system' };
    }, function (system) {
        if (!place) {
            return;
        }
        resolve({
            type: 'citadel',
            structure_id: place.fields.structure_id,
            structure_name: $('#structure_name').val(),
            system_id: system.fields.system_id
        }, function (found) {
            if (found) {
                usePlace(found, false);
            }
        });
    });

    function resolve(fields, done) {
        $.getJSON(state.placesUrl, $.extend({ market_id: state.marketId || '' }, fields))
            .done(function (data) {
                showNote(data.note);
                done(data.place || null);
            })
            .fail(function () {
                done(null);
            });
    }

    // ----- The picked place ---------------------------------------------

    function usePlace(found, initial) {
        place = found;

        if (!found) {
            $('#mk-picked-row').prop('hidden', true);
            $('#mk-find').prop('hidden', false);
            renderProviders(state.unplaced, initial);
            return;
        }

        $('#mk-picked-label').text(found.label);
        $('#mk-picked-detail').text(found.detail || '');
        $('#mk-picked-row').prop('hidden', false);
        $('#mk-find').prop('hidden', true);
        $('#mk-system-row').prop('hidden', !found.needs_system);
        $('#mk-system-search').val('');
        renderExisting(found.existing || []);

        // A refused save or a saved market keeps the IDs it came back with.
        if (!initial) {
            var f = found.fields;
            $('#region_id').val(f.region_id || '');
            $('#system_id').val(f.system_id || '');
            $('#system_name').val(f.system_name || '');
            $('#structure_name').val(f.structure_name || '');
        }

        // A name or key the form filled in follows the place; one typed by
        // hand stays. A structure SeAT hasn't seen comes with no name.
        if (autoName || !$('#name').val()) {
            $('#name').val(found.name || '');
            autoName = true;
        }
        if (state.isNew && (autoKey || !$('#key').val())) {
            $('#key').val(found.key || '');
            autoKey = true;
        }

        renderProviders(found.providers, initial);

        if (!initial) {
            if (found.needs_system) {
                $('#mk-system-search').trigger('focus');
            } else if (!$('#name').val()) {
                $('#name').trigger('focus');
            }
        }
    }

    function renderExisting(existing) {
        var $box = $('#mk-existing').empty();
        if (!existing.length) {
            $box.prop('hidden', true);
            return;
        }

        $.each(existing, function (i, market) {
            var $line = $('<div>').append(
                $('<i class="fas fa-exclamation-triangle mr-1">'),
                document.createTextNode(market.name + ' is already on the list, priced by ' + market.provider_label
                    + (market.enabled ? '. ' : ' and disabled. '))
            );
            if (market.custom) {
                $line.append($('<a>').attr('href', state.editUrl.replace('/0/edit', '/' + market.id + '/edit')).text('Edit it'));
            } else if (!market.enabled) {
                $line.append(document.createTextNode('Enable it on the Markets list.'));
            }
            $box.append($line);
        });

        $box.append($('<div class="mk-meta mt-1">').text('A provider that already prices it there is greyed out below.'));
        $box.prop('hidden', false);
    }

    function renderProviders(providers, initial) {
        var $checked = $('input[name="provider"]:checked');
        var current = $checked.val();
        var usable = [];

        $('#mk-providers .mk-provider').each(function () {
            var $card = $(this);
            var key = $card.data('provider');
            var info = providers[key] || state.unplaced[key];
            var isSaved = state.saved === key;
            var ok = info.offered && info.available;
            var text = info.offered ? (info.note || '') : (info.reason || '');

            if (!ok && isSaved) {
                text = 'Saved with this one. ' + text;
            }

            // Off for a provider that can't price the place, or one that could
            // but isn't set up, which says so instead.
            $card.toggleClass('mk-provider-off', !ok && !isSaved)
                .toggleClass('mk-provider-setup', info.offered && !info.available && !isSaved)
                .toggleClass('mk-provider-saved', !ok && isSaved);
            $card.find('input').prop('disabled', !ok && !isSaved);
            $card.find('.mk-provider-text').text(text);

            if (ok || isSaved) {
                usable.push(key);
            }
        });

        var keep = current && usable.indexOf(current) !== -1;
        if (!keep) {
            $('input[name="provider"]').prop('checked', false);
            var pick = place && place.provider ? place.provider : null;
            if (pick && usable.indexOf(pick) !== -1) {
                $('input[name="provider"][value="' + pick + '"]').prop('checked', true);
            }
        }

        var none = place && !usable.length;
        $('#mk-providers-hint').text(
            none ? 'No provider can price this place, so it can\'t be added.'
                : (place ? '' : 'Pick a place to see which providers can price it.')
        );

        if (!initial) {
            applySlug();
        }
    }

    // The slug is what the chosen provider is asked for at the place.
    function applySlug() {
        if (!place) {
            return;
        }
        var chosen = $('input[name="provider"]:checked').val();
        var info = chosen ? place.providers[chosen] : null;
        $('#provider_slug').val(info && info.slug ? info.slug : '');
    }

    // ----- Wiring --------------------------------------------------------

    var idNames = ['provider_slug', 'region_id', 'system_id', 'system_name', 'structure_name'];
    var remembered = {};
    var lastType = marketType();

    // Switching kind starts a fresh search, and switching back brings the
    // place picked before, with its IDs, so a slip costs nothing.
    function applyType(initial) {
        var type = marketType();
        $('#mk-search').attr('placeholder', hints[type].placeholder).val('');
        $('#mk-search-hint').text(hints[type].hint);
        mainSearch.close();

        if (initial) {
            lastType = type;
            return;
        }

        var ids = {};
        $.each(idNames, function (i, name) {
            ids[name] = $('#' + name).val();
        });
        remembered[lastType] = { place: place, ids: ids, provider: $('input[name="provider"]:checked').val() };
        lastType = type;

        var back = remembered[type];
        if (back) {
            $.each(back.ids, function (name, value) {
                $('#' + name).val(value);
            });
            $('input[name="provider"]').prop('checked', false);
            if (back.provider) {
                $('input[name="provider"][value="' + back.provider + '"]').prop('checked', true);
            }
            usePlace(back.place, true);
        } else {
            usePlace(null, true);
            $('#mk-search').trigger('focus');
        }
    }

    function idFields() {
        return {
            type: marketType(),
            provider_slug: $('#provider_slug').val(),
            structure_name: $('#structure_name').val(),
            system_id: $('#system_id').val(),
            system_name: $('#system_name').val(),
            region_id: $('#region_id').val()
        };
    }

    $('input[name="market_type"]').on('change', function () {
        applyType(false);
    });

    $('input[name="provider"]').on('change', applySlug);

    $('#mk-change').on('click', function () {
        $('#mk-find').prop('hidden', false);
        $('#mk-search').val('').trigger('focus');
    });

    // IDs typed by hand are looked up the same way once the field is left.
    // What was typed stays; the place fills only the fields left blank.
    $('.mk-id').on('change', function () {
        resolve(idFields(), function (found) {
            usePlace(found, true);
            if (!found) {
                return;
            }
            $.each(['region_id', 'system_id', 'system_name', 'structure_name'], function (i, name) {
                if (!$('#' + name).val() && found.fields[name]) {
                    $('#' + name).val(found.fields[name]);
                }
            });
            if (!$('#provider_slug').val()) {
                applySlug();
            }
        });
    });

    $('#name').on('input', function () {
        autoName = false;
    });
    $('#key').on('input', function () {
        autoKey = false;
    });

    applyType(true);
    usePlace(state.place, true);
});
</script>
@endpush
