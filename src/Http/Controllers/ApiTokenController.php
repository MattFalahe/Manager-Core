<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use ManagerCore\Models\ApiToken;
use Seat\Web\Http\Controllers\Controller;

class ApiTokenController extends Controller
{
    /**
     * GET /manager-core/api-tokens
     *
     * List all API tokens for the current user (superusers see all).
     */
    public function index()
    {
        $user = auth()->user();

        // L23: defensive null-check on auth — should never be null because the
        // route is behind 'auth' middleware, but a misconfiguration could land
        // us here without a session.
        if (!$user) {
            abort(401);
        }

        if ($user->can('global.superuser')) {
            $tokens = ApiToken::with('user')
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $tokens = ApiToken::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $allScopes = ApiToken::ALL_SCOPES;
        $readOnlyScopes = ApiToken::READ_ONLY_SCOPES;
        $writeScopes = ApiToken::WRITE_SCOPES;
        $isSuperuser = $user->can('global.superuser');

        return view('manager-core::api-tokens.index', compact(
            'tokens', 'allScopes', 'readOnlyScopes', 'writeScopes', 'isSuperuser'
        ));
    }

    /**
     * POST /manager-core/api-tokens
     *
     * Create a new API token.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|in:' . implode(',', ApiToken::ALL_SCOPES),
            'rate_limit' => 'nullable|integer|min:1|max:1000',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        // L23: defensive null-check
        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        // Check max tokens per user
        $maxTokens = config('manager-core.api.max_tokens_per_user', 5);
        $existingCount = ApiToken::where('user_id', $user->id)->count();

        if ($existingCount >= $maxTokens) {
            return redirect()->back()
                ->with('error', "Maximum of {$maxTokens} API tokens per user reached.");
        }

        // C2: explicit scope selection. If form submitted no scopes, default to read-only.
        $scopes = $request->input('scopes');
        if (empty($scopes)) {
            $scopes = ApiToken::READ_ONLY_SCOPES;
        }

        // Only superusers can grant write scopes
        $writeScopesRequested = array_intersect($scopes, ApiToken::WRITE_SCOPES);
        if (!empty($writeScopesRequested) && !$user->can('global.superuser')) {
            return redirect()->back()
                ->with('error', 'Write scopes (events.publish, appraisals.create) require superuser permission.');
        }

        $options = [
            'scopes' => $scopes,
            'rate_limit' => $request->input('rate_limit', config('manager-core.api.default_rate_limit', 60)),
            // L18: capture optional description into the metadata JSON
            'description' => $request->input('description'),
            'created_via' => 'web_ui',
        ];

        if ($request->filled('expires_in_days')) {
            $options['expires_at'] = now()->addDays((int) $request->input('expires_in_days'));
        }

        $result = ApiToken::createToken($user->id, $request->input('name'), $options);

        return redirect()->back()
            ->with('success', 'API token created successfully.')
            ->with('new_token', $result['raw_token']);
    }

    /**
     * DELETE /manager-core/api-tokens/{id}
     *
     * Revoke (delete) an API token.
     */
    public function destroy(int $id)
    {
        // L23: defensive null-check
        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        $token = ApiToken::findOrFail($id);

        // Only owner or superuser can delete
        if ($token->user_id !== $user->id && !$user->can('global.superuser')) {
            abort(403);
        }

        $token->delete();

        return redirect()->back()
            ->with('success', 'API token revoked.');
    }

    /**
     * POST /manager-core/api-tokens/{id}/toggle
     *
     * Enable/disable an API token.
     */
    public function toggle(int $id)
    {
        // L23: defensive null-check
        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        $token = ApiToken::findOrFail($id);

        if ($token->user_id !== $user->id && !$user->can('global.superuser')) {
            abort(403);
        }

        $token->update(['is_active' => !$token->is_active]);

        $status = $token->is_active ? 'enabled' : 'disabled';

        return redirect()->back()
            ->with('success', "API token {$status}.");
    }

    /**
     * L21: POST /manager-core/api-tokens/{id}/rotate
     *
     * Generates a new raw token while keeping the same scopes/name/limits.
     * The old raw token is immediately invalidated.
     */
    public function rotate(int $id)
    {
        // L23: defensive null-check
        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        $token = ApiToken::findOrFail($id);

        if ($token->user_id !== $user->id && !$user->can('global.superuser')) {
            abort(403);
        }

        $rawToken = $token->rotate();

        return redirect()->back()
            ->with('success', "API token '{$token->name}' rotated. The previous token is now invalid.")
            ->with('new_token', $rawToken);
    }
}
