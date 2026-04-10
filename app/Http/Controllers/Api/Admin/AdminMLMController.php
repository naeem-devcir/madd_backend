<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Mlm\MlmAgent;
use App\Models\Mlm\MlmCommission;
use App\Models\User;
use App\Services\Mlm\MlmService;
use App\Services\Mlm\CommissionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMLMController extends Controller
{
    protected $mlmService;
    protected $commissionCalculator;

    public function __construct(MlmService $mlmService, CommissionCalculator $commissionCalculator)
    {
        $this->mlmService = $mlmService;
        $this->commissionCalculator = $commissionCalculator;
    }

    /**
     * Get all MLM agents with filters
     */
    public function index(Request $request)
    {
        $query = MlmAgent::with(['user', 'parent']);

        // Apply filters
        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('kyc_status')) {
            $query->where('kyc_status', $request->kyc_status);
        }

        if ($request->has('territory_type')) {
            $query->where('territory_type', $request->territory_type);
        }

        if ($request->has('territory_code')) {
            $query->where('territory_code', $request->territory_code);
        }

        if ($request->has('search')) {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('email', 'like', '%' . $request->search . '%')
                  ->orWhere('first_name', 'like', '%' . $request->search . '%')
                  ->orWhere('last_name', 'like', '%' . $request->search . '%');
            });
        }

        $agents = $query->orderBy('level', 'asc')
            ->orderBy('total_commissions_earned', 'desc')
            ->paginate($request->get('per_page', 20));

        // Get downline counts
        foreach ($agents as $agent) {
            $agent->downline_count = $agent->getDownlineCount();
            $agent->active_downline = $agent->children()->where('status', 'active')->count();
        }

        return response()->json([
            'success' => true,
            'data' => $agents,
            'meta' => [
                'current_page' => $agents->currentPage(),
                'last_page' => $agents->lastPage(),
                'total' => $agents->total(),
            ]
        ]);
    }

    /**
     * Get single MLM agent details
     */
    public function show($id)
    {
        $agent = MlmAgent::with(['user', 'parent', 'children', 'commissions'])
            ->findOrFail($id);

        // Get downline tree
        $downlineTree = $this->mlmService->getDownlineTree($agent);

        // Get commission summary
        $commissionSummary = [
            'total_earned' => $agent->total_commissions_earned,
            'pending' => $agent->commissions()->where('status', 'pending')->sum('amount'),
            'approved' => $agent->commissions()->where('status', 'approved')->sum('amount'),
            'paid' => $agent->commissions()->where('status', 'paid')->sum('amount'),
            'by_level' => $agent->commissions()
                ->select('level', DB::raw('SUM(amount) as total'))
                ->groupBy('level')
                ->get(),
        ];

        // Get recruitment stats
        $recruitmentStats = [
            'direct_recruits' => $agent->children()->count(),
            'total_downline' => $agent->getDownlineCount(),
            'active_downline' => $agent->children()->where('status', 'active')->count(),
            'vendors_recruited' => $agent->total_vendors_recruited,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => $agent,
                'user' => $agent->user,
                'parent' => $agent->parent,
                'downline_tree' => $downlineTree,
                'downline_count' => $agent->getDownlineCount(),
                'commission_summary' => $commissionSummary,
                'recruitment_stats' => $recruitmentStats,
                'recent_commissions' => $agent->commissions()
                    ->latest()
                    ->limit(20)
                    ->get(),
            ]
        ]);
    }

    /**
     * Create new MLM agent
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:mlm_agents,user_id',
            'parent_id' => 'nullable|exists:mlm_agents,id',
            'territory_type' => 'required|in:country,region,city',
            'territory_code' => 'required|string|max:50',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'phone' => 'nullable|string|max:30',
        ]);

        DB::beginTransaction();

        try {
            $user = User::find($validated['user_id']);
            
            // Update user type
            $user->user_type = 'mlm_agent';
            $user->save();

            // Calculate level based on parent
            $level = 1;
            if ($validated['parent_id']) {
                $parent = MlmAgent::find($validated['parent_id']);
                $level = $parent->level + 1;
            }

            $agent = MlmAgent::create([
                'user_id' => $validated['user_id'],
                'parent_id' => $validated['parent_id'] ?? null,
                'level' => $level,
                'territory_type' => $validated['territory_type'],
                'territory_code' => $validated['territory_code'],
                'commission_rate' => $validated['commission_rate'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'active',
                'kyc_status' => 'pending',
            ]);

            // Assign MLM agent role
            $user->assignRole('mlm_agent');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'MLM agent created successfully',
                'data' => $agent->load(['user', 'parent'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create MLM agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update MLM agent
     */
    public function update(Request $request, $id)
    {
        $agent = MlmAgent::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'nullable|exists:mlm_agents,id',
            'commission_rate' => 'sometimes|numeric|min:0|max:100',
            'territory_type' => 'sometimes|in:country,region,city',
            'territory_code' => 'sometimes|string|max:50',
            'phone' => 'nullable|string|max:30',
            'status' => 'sometimes|in:active,inactive,suspended',
            'kyc_status' => 'sometimes|in:pending,verified,rejected',
        ]);

        DB::beginTransaction();

        try {
            // Update level if parent changed
            if (isset($validated['parent_id']) && $validated['parent_id'] !== $agent->parent_id) {
                $parent = MlmAgent::find($validated['parent_id']);
                $validated['level'] = $parent ? $parent->level + 1 : 1;
                
                // Update all downline levels
                $this->mlmService->updateDownlineLevels($agent, $validated['level']);
            }

            $agent->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'MLM agent updated successfully',
                'data' => $agent->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update MLM agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete MLM agent
     */
    public function destroy($id)
    {
        $agent = MlmAgent::findOrFail($id);

        // Check if has downline
        if ($agent->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete agent with downline members. Reassign or suspend instead.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = $agent->user;
            $user->user_type = 'customer';
            $user->save();

            $agent->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'MLM agent deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete MLM agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get MLM commissions with filters
     */
    public function commissions(Request $request)
    {
        $query = MlmCommission::with(['agent', 'agent.user', 'settlement']);

        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('source_type')) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $commissions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Add summary statistics
        $summary = [
            'total_pending' => MlmCommission::where('status', 'pending')->sum('amount'),
            'total_approved' => MlmCommission::where('status', 'approved')->sum('amount'),
            'total_paid' => MlmCommission::where('status', 'paid')->sum('amount'),
            'total_commissions' => MlmCommission::sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $commissions,
            'summary' => $summary,
            'meta' => [
                'current_page' => $commissions->currentPage(),
                'last_page' => $commissions->lastPage(),
                'total' => $commissions->total(),
            ]
        ]);
    }

    /**
     * Process pending commissions
     */
    public function processCommissions(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ]);

        DB::beginTransaction();

        try {
            $result = $this->commissionCalculator->calculatePeriodCommissions(
                $request->period_start,
                $request->period_end
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Commissions processed successfully',
                'data' => [
                    'commissions_created' => $result['created'],
                    'total_amount' => $result['total_amount'],
                    'period_start' => $request->period_start,
                    'period_end' => $request->period_end,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to process commissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve commission
     */
    public function approveCommission($id)
    {
        $commission = MlmCommission::findOrFail($id);

        if ($commission->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Commission is not pending approval'
            ], 422);
        }

        $commission->approve();

        return response()->json([
            'success' => true,
            'message' => 'Commission approved',
            'data' => $commission
        ]);
    }

    /**
     * Reject commission
     */
    public function rejectCommission(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $commission = MlmCommission::findOrFail($id);

        if ($commission->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Commission is not pending approval'
            ], 422);
        }

        $commission->reject($request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Commission rejected',
            'data' => $commission
        ]);
    }

    /**
     * Get MLM hierarchy tree
     */
    public function hierarchy(Request $request)
    {
        $rootAgents = MlmAgent::whereNull('parent_id')
            ->with(['user', 'children'])
            ->get();

        $tree = [];
        foreach ($rootAgents as $root) {
            $tree[] = $this->buildHierarchyTree($root);
        }

        return response()->json([
            'success' => true,
            'data' => $tree
        ]);
    }

    /**
     * Get MLM statistics
     */
    public function statistics()
    {
        $stats = [
            'total_agents' => MlmAgent::count(),
            'active_agents' => MlmAgent::where('status', 'active')->count(),
            'inactive_agents' => MlmAgent::where('status', 'inactive')->count(),
            'suspended_agents' => MlmAgent::where('status', 'suspended')->count(),
            'kyc_pending' => MlmAgent::where('kyc_status', 'pending')->count(),
            'kyc_verified' => MlmAgent::where('kyc_status', 'verified')->count(),
            'kyc_rejected' => MlmAgent::where('kyc_status', 'rejected')->count(),
            'by_level' => MlmAgent::select('level', DB::raw('count(*) as count'))
                ->groupBy('level')
                ->orderBy('level')
                ->get(),
            'by_territory' => MlmAgent::select('territory_type', 'territory_code', DB::raw('count(*) as count'))
                ->groupBy('territory_type', 'territory_code')
                ->get(),
            'total_commissions' => MlmCommission::sum('amount'),
            'pending_commissions' => MlmCommission::where('status', 'pending')->sum('amount'),
            'paid_commissions' => MlmCommission::where('status', 'paid')->sum('amount'),
            'top_earners' => MlmAgent::orderBy('total_commissions_earned', 'desc')
                ->limit(10)
                ->with('user')
                ->get(),
            'top_recruiters' => MlmAgent::orderBy('total_vendors_recruited', 'desc')
                ->limit(10)
                ->with('user')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Build hierarchy tree recursively
     */
    private function buildHierarchyTree(MlmAgent $agent, $level = 1)
    {
        $node = [
            'id' => $agent->id,
            'name' => $agent->user->full_name,
            'email' => $agent->user->email,
            'level' => $agent->level,
            'status' => $agent->status,
            'total_commissions' => $agent->total_commissions_earned,
            'children' => [],
        ];

        foreach ($agent->children as $child) {
            $node['children'][] = $this->buildHierarchyTree($child, $level + 1);
        }

        return $node;
    }

    public function verify($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'MLM agent verification is not implemented yet.',
            'agent_id' => $id,
        ], 501);
    }

    public function payCommission($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'MLM commission payout is not implemented yet.',
            'commission_id' => $id,
        ], 501);
    }

    public function structure(Request $request)
    {
        return $this->hierarchy($request);
    }

    public function levels()
    {
        return response()->json([
            'success' => false,
            'message' => 'MLM level configuration is not implemented yet.',
        ], 501);
    }

    public function updateLevels(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'MLM level updates are not implemented yet.',
        ], 501);
    }
}
