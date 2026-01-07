<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\PricingService;
use ManagerCore\Models\TypeSubscription;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvCategory;

class SubscriptionController extends Controller
{
    /**
     * Pricing Service
     *
     * @var PricingService
     */
    protected $pricingService;

    /**
     * Constructor
     */
    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Display subscription management page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $markets = config('manager-core.pricing.markets', []);

        // Get all subscriptions grouped by market and plugin
        $subscriptions = TypeSubscription::with('type')
            ->orderBy('market')
            ->orderBy('plugin_name')
            ->orderBy('priority', 'desc')
            ->get()
            ->groupBy('market');

        // Get statistics
        $stats = [
            'total_subscriptions' => TypeSubscription::count(),
            'unique_types' => TypeSubscription::distinct('type_id')->count(),
            'plugins' => TypeSubscription::distinct('plugin_name')->count(),
            'markets' => TypeSubscription::distinct('market')->count(),
        ];

        return view('manager-core::subscriptions.index', compact('markets', 'subscriptions', 'stats'));
    }

    /**
     * Subscribe to specific type IDs
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function subscribeTypes(Request $request)
    {
        $request->validate([
            'type_ids' => 'required|string',
            'market' => 'required|string',
            'plugin_name' => 'required|string|max:255',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        // Parse type IDs
        $typeIdsInput = $request->input('type_ids');
        $typeIds = array_map('intval', array_filter(preg_split('/[\s,]+/', $typeIdsInput)));

        if (empty($typeIds)) {
            return back()->with('error', 'No valid type IDs provided');
        }

        // Verify all type IDs exist in SDE
        $validTypes = InvType::whereIn('typeID', $typeIds)->pluck('typeID')->toArray();
        $invalidTypes = array_diff($typeIds, $validTypes);

        if (!empty($invalidTypes)) {
            return back()->with('warning', 'Some type IDs were invalid and skipped: ' . implode(', ', $invalidTypes));
        }

        // Subscribe
        $this->pricingService->registerTypes(
            $request->input('plugin_name'),
            $validTypes,
            $request->input('market'),
            $request->input('priority', 5)
        );

        return back()->with('success', 'Successfully subscribed to ' . count($validTypes) . ' type(s)');
    }

    /**
     * Subscribe to entire category
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function subscribeCategory(Request $request)
    {
        $request->validate([
            'category_id' => 'required|integer',
            'market' => 'required|string',
            'plugin_name' => 'required|string|max:255',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        $categoryId = $request->input('category_id');

        // Get all type IDs in this category
        $typeIds = InvType::join('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
            ->where('invGroups.categoryID', $categoryId)
            ->where('invTypes.published', 1)
            ->pluck('invTypes.typeID')
            ->toArray();

        if (empty($typeIds)) {
            return back()->with('error', 'No published types found in this category');
        }

        // Subscribe
        $this->pricingService->registerTypes(
            $request->input('plugin_name'),
            $typeIds,
            $request->input('market'),
            $request->input('priority', 5)
        );

        $category = InvCategory::find($categoryId);
        $categoryName = $category ? $category->categoryName : "Category #{$categoryId}";

        return back()->with('success', "Successfully subscribed to {$categoryName} (" . count($typeIds) . ' types)');
    }

    /**
     * Subscribe to entire group
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function subscribeGroup(Request $request)
    {
        $request->validate([
            'group_id' => 'required|integer',
            'market' => 'required|string',
            'plugin_name' => 'required|string|max:255',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        $groupId = $request->input('group_id');

        // Get all type IDs in this group
        $typeIds = InvType::where('groupID', $groupId)
            ->where('published', 1)
            ->pluck('typeID')
            ->toArray();

        if (empty($typeIds)) {
            return back()->with('error', 'No published types found in this group');
        }

        // Subscribe
        $this->pricingService->registerTypes(
            $request->input('plugin_name'),
            $typeIds,
            $request->input('market'),
            $request->input('priority', 5)
        );

        $group = InvGroup::find($groupId);
        $groupName = $group ? $group->groupName : "Group #{$groupId}";

        return back()->with('success', "Successfully subscribed to {$groupName} (" . count($typeIds) . ' types)');
    }

    /**
     * Unsubscribe from a type
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function unsubscribe($id)
    {
        $subscription = TypeSubscription::findOrFail($id);
        $subscription->delete();

        return back()->with('success', 'Subscription removed successfully');
    }

    /**
     * Clear all subscriptions for a plugin
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearPlugin(Request $request)
    {
        $request->validate([
            'plugin_name' => 'required|string',
        ]);

        $count = TypeSubscription::where('plugin_name', $request->input('plugin_name'))->delete();

        return back()->with('success', "Removed {$count} subscription(s) for plugin: " . $request->input('plugin_name'));
    }

    /**
     * Get categories for AJAX
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCategories()
    {
        $categories = InvCategory::where('published', 1)
            ->orderBy('categoryName')
            ->get(['categoryID', 'categoryName']);

        return response()->json($categories);
    }

    /**
     * Get groups for a category (AJAX)
     *
     * @param int $categoryId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGroups($categoryId)
    {
        $groups = InvGroup::where('categoryID', $categoryId)
            ->where('published', 1)
            ->orderBy('groupName')
            ->get(['groupID', 'groupName']);

        return response()->json($groups);
    }
}
