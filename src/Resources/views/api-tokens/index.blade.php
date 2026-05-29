@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.api_tokens'))
@section('page_header', trans('manager-core::manager-core.api_tokens'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=2">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<style>
    /* Page-scoped <code> + <pre> dark-theme overrides.

       Default Bootstrap/SeAT `<code>` color is a pinkish coral
       (#e83e8c) which renders fine on light backgrounds but is
       barely legible on the .card-dark chrome MC uses. The
       Available Endpoints table has lots of <code> tags — METHOD,
       URL, scope — that need to stay readable.

       Scoping via .manager-core-wrapper so this doesn't leak to
       SeAT-core or other-plugin pages. The .card-dark prefix
       further narrows to MC's own cards only. */
    .manager-core-wrapper .card-dark code {
        color: #a5b4fc;
        background: rgba(99, 102, 241, 0.10);
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 0.85em;
    }
    .manager-core-wrapper .card-dark pre {
        color: #a5b4fc;
        background: #1a1d24;
        border: 1px solid #2c3138;
        padding: 12px;
        border-radius: 5px;
    }
    /* When <code> nests inside <pre> (rare here but defensive),
       drop the inline background so we don't get nested-pill weirdness. */
    .manager-core-wrapper .card-dark pre code {
        background: transparent;
        padding: 0;
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">

    {{-- New Token Alert --}}
    @if(session('new_token'))
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h4><i class="fas fa-key"></i> API Token Created</h4>
            <p>Copy your API token now. <strong>It will not be shown again.</strong></p>
            <div class="input-group" style="max-width: 600px; margin-top: 10px;">
                <input type="password" class="form-control" id="newToken" value="{{ session('new_token') }}" readonly>
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default" onclick="document.getElementById('newToken').type = document.getElementById('newToken').type === 'password' ? 'text' : 'password';">
                        <i class="fas fa-eye"></i> Show
                    </button>
                    <button type="button" class="btn btn-default" onclick="navigator.clipboard.writeText(document.getElementById('newToken').value); this.textContent='Copied!';">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </span>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    {{-- Create Token --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-plus-circle"></i> Create API Token</h3>
        </div>
        <div class="card-body">
            <form action="{{ route('manager-core.api-tokens.store') }}" method="POST">
                @csrf
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="name">Token Name</label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g., Discord Bot, Spreadsheet" required maxlength="255">
                            <small class="form-text text-muted">A short label for this token.</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="description">Description (optional)</label>
                            <input type="text" name="description" id="description" class="form-control" placeholder="What this token is used for" maxlength="500">
                            <small class="form-text text-muted">Stored on the token for future reference.</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="rate_limit">Rate Limit (req/min)</label>
                            <input type="number" name="rate_limit" id="rate_limit" class="form-control" value="{{ config('manager-core.api.default_rate_limit', 60) }}" min="1" max="1000">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="expires_in_days">Expires in (days)</label>
                            <input type="number" name="expires_in_days" id="expires_in_days" class="form-control" placeholder="Never" min="1" max="3650">
                            <small class="form-text text-muted">Leave blank for no expiry.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label>Scopes <small class="text-muted">(at least one read scope required)</small></label>
                    {{-- Dark-theme scope card. Original used #f8f9fa light bg
                         which left <code> tags rendered with their default
                         pinkish foreground sitting on a near-white background,
                         and the warning alert below inherited Bootstrap's
                         light-amber palette — both unreadable inside the dark
                         page chrome. Explicit per-element colors win against
                         the cascade. --}}
                    <div style="background: #1a1d24; border: 1px solid #2c3138; padding: 12px; border-radius: 5px;">
                        <h6 style="color: #5cb874; margin-bottom: 8px;"><i class="fas fa-eye"></i> Read scopes (default — safe)</h6>
                        @foreach($readOnlyScopes as $scope)
                            <div class="form-check form-check-inline">
                                <input type="checkbox" name="scopes[]" id="scope_{{ $scope }}" value="{{ $scope }}" class="form-check-input" checked>
                                <label for="scope_{{ $scope }}" class="form-check-label" style="color: #d1d5db;"><code style="color: #a5b4fc;">{{ $scope }}</code></label>
                            </div>
                        @endforeach

                        @if($isSuperuser)
                            <h6 style="color: #f17886; margin-top: 12px; margin-bottom: 8px;"><i class="fas fa-pen"></i> Write scopes (superuser only — sensitive)</h6>
                            @foreach($writeScopes as $scope)
                                <div class="form-check form-check-inline">
                                    <input type="checkbox" name="scopes[]" id="scope_{{ $scope }}" value="{{ $scope }}" class="form-check-input">
                                    <label for="scope_{{ $scope }}" class="form-check-label" style="color: #d1d5db;"><code style="color: #fbbf77;">{{ $scope }}</code></label>
                                </div>
                            @endforeach
                            <div style="margin-top: 12px; padding: 10px 12px; font-size: 0.85rem; background: rgba(255, 193, 7, 0.10); border-left: 3px solid #ffc107; border-radius: 3px; color: #e2e8f0;">
                                <strong style="color: #ffd87a;">Warning:</strong>
                                <code style="color: #fbbf77;">events.publish</code> tokens can publish events that other plugins act on (notifications, ledger entries, alerts). External tokens are forced to <code style="color: #fbbf77;">publisher='api'</code> and limited to the <code style="color: #fbbf77;">api.</code> / <code style="color: #fbbf77;">custom.</code> prefix.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="form-group" style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn btn-mc-primary">
                        <i class="fas fa-key"></i> Generate Token
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Existing Tokens --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list"></i> API Tokens</h3>
        </div>
        <div class="card-body">
            @if($tokens->isEmpty())
                <div class="text-center text-muted py-4">
                    <i class="fas fa-key fa-3x mb-3"></i>
                    <p>No API tokens created yet.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Token Prefix</th>
                                @can('global.superuser')
                                    <th>Owner</th>
                                @endcan
                                <th>Scopes</th>
                                <th>Rate Limit</th>
                                <th>Last Used</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tokens as $token)
                                <tr>
                                    <td>{{ $token->name }}</td>
                                    <td><code>{{ $token->token_prefix }}...</code></td>
                                    @can('global.superuser')
                                        <td>{{ $token->user ? $token->user->name : 'Unknown' }}</td>
                                    @endcan
                                    <td>
                                        @if(empty($token->scopes))
                                            <span class="badge badge-secondary" title="No scopes — token cannot access any endpoint">none</span>
                                        @else
                                            @foreach($token->scopes as $scope)
                                                @php
                                                    $isWrite = in_array($scope, \ManagerCore\Models\ApiToken::WRITE_SCOPES);
                                                @endphp
                                                <span class="badge badge-{{ $isWrite ? 'danger' : 'info' }}" title="{{ $isWrite ? 'Write scope' : 'Read scope' }}">{{ $scope }}</span>
                                            @endforeach
                                        @endif
                                    </td>
                                    <td>{{ $token->rate_limit }}/min</td>
                                    <td>
                                        @if($token->last_used_at)
                                            <span title="{{ $token->last_used_at }}">{{ $token->last_used_at->diffForHumans() }}</span>
                                            <br><small class="text-muted">{{ $token->last_used_ip }}</small>
                                        @else
                                            <span class="text-muted">Never</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($token->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Disabled</span>
                                        @endif
                                        @if($token->expires_at && $token->expires_at->isPast())
                                            <span class="badge badge-danger">Expired</span>
                                        @elseif($token->expires_at)
                                            <br><small class="text-muted">expires {{ $token->expires_at->diffForHumans() }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $token->created_at->format('Y-m-d') }}</td>
                                    <td>
                                        <form action="{{ route('manager-core.api-tokens.toggle', $token->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-xs btn-{{ $token->is_active ? 'warning' : 'success' }}" title="{{ $token->is_active ? 'Disable' : 'Enable' }}">
                                                <i class="fas fa-{{ $token->is_active ? 'pause' : 'play' }}"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('manager-core.api-tokens.rotate', $token->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Rotate this API token? The previous token value will be invalidated immediately and cannot be recovered.');">
                                            @csrf
                                            <button type="submit" class="btn btn-xs btn-info" title="Rotate (issue a new value, invalidate the old one)">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('manager-core.api-tokens.destroy', $token->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Revoke this API token?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-xs btn-danger" title="Revoke">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- API Documentation --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-book"></i> API Usage</h3>
        </div>
        <div class="card-body">
            <p>Authenticate requests with your token via the <code>Authorization</code> or <code>X-Api-Token</code> header. Query-string tokens are <strong>not supported</strong> (they leak to logs/Referer).</p>
            {{-- Dark-theme styling for <pre>/<code> comes from the
                 page-scoped <style> in @push('head') above. Default
                 Bootstrap/SeAT light-mode coloring rendered dark-on-dark
                 here — recurring contrast issue per
                 feedback_help_docs_visual_design.md rule #3. --}}
            <pre>Authorization: Bearer mc_your_token_here
X-Api-Token: mc_your_token_here</pre>

            <h5 class="mt-3">Available Endpoints</h5>
            <table class="table table-sm">
                <thead>
                    <tr><th>Method</th><th>Endpoint</th><th>Required Scope</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/prices/{typeId}</code></td><td><code>prices.read</code></td><td>Get price for a type ID</td></tr>
                    <tr><td><code>POST</code></td><td><code>/api/manager-core/v1/prices/batch</code></td><td><code>prices.read</code></td><td>Get prices for multiple type IDs</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/prices/{typeId}/trend</code></td><td><code>prices.read</code></td><td>Get price trend</td></tr>
                    <tr><td><code>POST</code></td><td><code>/api/manager-core/v1/appraisals</code></td><td><code>appraisals.create</code></td><td>Create an appraisal</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/appraisals/{id}</code></td><td><code>appraisals.read</code></td><td>Get an appraisal</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/plugins</code></td><td><code>plugins.read</code></td><td>List all plugins</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/subscriptions</code></td><td><code>plugins.read</code></td><td>List subscriptions</td></tr>
                    <tr><td><code>POST</code></td><td><code>/api/manager-core/v1/events/publish</code></td><td><code>events.publish</code></td><td>Publish an event (api.* / custom.* prefixes only)</td></tr>
                    <tr><td><code>GET</code></td><td><code>/api/manager-core/v1/events/log</code></td><td><code>events.read</code></td><td>View event log</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
