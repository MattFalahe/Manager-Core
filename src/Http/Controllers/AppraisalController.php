<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\AppraisalService;
use ManagerCore\Models\Appraisal;

class AppraisalController extends Controller
{
    /**
     * Appraisal Service
     *
     * @var AppraisalService
     */
    protected $appraisalService;

    /**
     * Constructor
     */
    public function __construct(AppraisalService $appraisalService)
    {
        $this->appraisalService = $appraisalService;
    }

    /**
     * Display appraisal creation form
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $markets = config('manager-core.pricing.markets', []);
        $recentAppraisals = Appraisal::where('user_id', auth()->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return view('manager-core::appraisal.index', compact('markets', 'recentAppraisals'));
    }

    /**
     * Create a new appraisal
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create(Request $request)
    {
        $request->validate([
            'raw_input' => 'required|string|max:100000',
            'market' => 'required|string',
            'price_percentage' => 'nullable|numeric|min:0|max:200',
            'is_private' => 'nullable|boolean',
        ]);

        try {
            $options = [
                'market' => $request->input('market'),
                'price_percentage' => $request->input('price_percentage', 100),
                'user_id' => auth()->user()->id,
                'is_private' => $request->boolean('is_private'),
            ];

            $appraisal = $this->appraisalService->createAppraisal(
                $request->input('raw_input'),
                $options
            );

            return redirect()->route('manager-core.appraisal.show', $appraisal->appraisal_id)
                ->with('success', 'Appraisal created successfully');

        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Failed to create appraisal: ' . $e->getMessage());
        }
    }

    /**
     * Show an appraisal
     *
     * @param string $appraisalId
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function show($appraisalId, Request $request)
    {
        $privateToken = $request->input('token');

        $appraisal = $this->appraisalService->getAppraisal($appraisalId, $privateToken);

        if (!$appraisal) {
            abort(404, 'Appraisal not found or access denied');
        }

        return view('manager-core::appraisal.show', compact('appraisal'));
    }

    /**
     * Delete an appraisal
     *
     * @param string $appraisalId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete($appraisalId)
    {
        $appraisal = Appraisal::where('appraisal_id', $appraisalId)->first();

        if (!$appraisal) {
            abort(404);
        }

        // Check ownership
        if ($appraisal->user_id !== auth()->user()->id && !auth()->user()->can('global.superuser')) {
            abort(403);
        }

        $appraisal->delete();

        return redirect()->route('manager-core.appraisal.index')
            ->with('success', 'Appraisal deleted successfully');
    }
}
