<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Mlm\MlmAgent;
use App\Models\Mlm\MlmCommission;
use App\Models\Mlm\MlmLevel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;



class AdminMLMController extends Controller
{
    // ─── Agents ───────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = MlmAgent::with(['user', 'parent.user'])
            ->withCount('children as downline_count');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('kyc_status')) {
            $query->where('kyc_status', $request->kyc_status);
        }
        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }
        if ($request->filled('territory_type')) {
            $query->where('territory_type', $request->territory_type);
        }
        if ($request->filled('territory_code')) {
            $query->where('territory_code', $request->territory_code);
        }
        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('full_name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $agents = $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $agents->items(),
            'meta'    => [
                'current_page' => $agents->currentPage(),
                'last_page'    => $agents->lastPage(),
                'total'        => $agents->total(),
                'per_page'     => $agents->perPage(),
                'from'         => $agents->firstItem(),
                'to'           => $agents->lastItem(),
            ],
        ]);
    }

    public function show($id)
    {
        $agent = MlmAgent::with([
            'user',
            'parent.user',
            'children.user',
        ])
        ->withCount('children as downline_count')
        ->findOrFail($id);

        // Active downline count
        $agent->active_downline = MlmAgent::where('parent_id', $id)
            ->where('status', 'active')
            ->count();

        // Recent commissions
        $commissions = MlmCommission::where('agent_id', $id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success'     => true,
            'data'        => array_merge($agent->toArray(), [
                'recent_commissions' => $commissions,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'         => 'required|exists:users,id',
            'parent_id'       => 'nullable|exists:mlm_agents,id',
            'level'           => 'required|integer|min:1|max:10',
            'territory_type'  => 'required|in:country,region,city',
            'territory_code'  => 'required|string|max:10',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'phone'           => 'nullable|string|max:20',
            'status'          => 'in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Prevent duplicate agent for same user
        if (MlmAgent::where('user_id', $request->user_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This user is already an MLM agent.',
            ], 422);
        }

        $agent = MlmAgent::create([
            'uuid'            => Str::uuid(),
            'user_id'         => $request->user_id,
            'parent_id'       => $request->parent_id,
            'level'           => $request->level,
            'territory_type'  => $request->territory_type,
            'territory_code'  => strtoupper($request->territory_code),
            'commission_rate' => $request->commission_rate,
            'phone'           => $request->phone,
            'status'          => $request->status ?? 'active',
            'kyc_status'      => 'pending',
        ]);

        $agent->load('user', 'parent.user');

        return response()->json([
            'success' => true,
            'message' => 'Agent created successfully.',
            'data'    => $agent,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $agent = MlmAgent::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'parent_id'       => 'nullable|exists:mlm_agents,id',
            'level'           => 'integer|min:1|max:10',
            'territory_type'  => 'in:country,region,city',
            'territory_code'  => 'string|max:10',
            'commission_rate' => 'numeric|min:0|max:100',
            'phone'           => 'nullable|string|max:20',
            'status'          => 'in:active,inactive,suspended',
            'kyc_status'      => 'in:pending,verified,rejected',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Prevent agent from being its own parent
        if ($request->filled('parent_id') && $request->parent_id == $id) {
            return response()->json([
                'success' => false,
                'message' => 'An agent cannot be its own parent.',
            ], 422);
        }

        $agent->update($request->only([
            'parent_id', 'level', 'territory_type',
            'territory_code', 'commission_rate', 'phone',
            'status', 'kyc_status',
        ]));

        $agent->load('user', 'parent.user');

        return response()->json([
            'success' => true,
            'message' => 'Agent updated successfully.',
            'data'    => $agent,
        ]);
    }

    public function destroy($id)
    {
        $agent = MlmAgent::withCount('children as downline_count')->findOrFail($id);

        if ($agent->downline_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete agent with downline members. Reassign or remove downline first.',
            ], 422);
        }

        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent deleted successfully.',
        ]);
    }

    public function verify($id)
    {
        $agent = MlmAgent::findOrFail($id);

        if ($agent->kyc_status === 'verified') {
            return response()->json([
                'success' => false,
                'message' => 'Agent KYC is already verified.',
            ], 422);
        }

        $agent->update(['kyc_status' => 'verified']);

        return response()->json([
            'success' => true,
            'message' => 'Agent KYC verified successfully.',
            'data'    => $agent->load('user'),
        ]);
    }

    // ─── Commissions ──────────────────────────────────────────────────────────

    public function commissions(Request $request)
    {
        $query = MlmCommission::with(['agent.user', 'settlement']);

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source_type')) {
            $query->where('source_type', $request->source_type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $commissions = $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        $summary = [
            'total_pending'     => MlmCommission::where('status', 'pending')->sum('amount'),
            'total_approved'    => MlmCommission::where('status', 'approved')->sum('amount'),
            'total_paid'        => MlmCommission::where('status', 'paid')->sum('amount'),
            'total_commissions' => MlmCommission::sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data'    => $commissions,
            'summary' => $summary,
            'meta'    => [
                'current_page' => $commissions->currentPage(),
                'last_page'    => $commissions->lastPage(),
                'total'        => $commissions->total(),
                'per_page'     => $commissions->perPage(),
                'from'         => $commissions->firstItem(),
                'to'           => $commissions->lastItem(),
            ],
        ]);
    }

    public function processCommissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // --- Business logic placeholder ---
        // Replace this block with your actual commission calculation logic.
        // Example: iterate sales in the period, find responsible agents,
        // apply commission_rate per level, and insert MlmCommission rows.

        $commissionsCreated = 0;
        $totalAmount        = 0.0;

        // Placeholder: you would query orders/sales here and create commissions
        // foreach ($sales as $sale) { ... MlmCommission::create([...]); }

        return response()->json([
            'success' => true,
            'message' => 'Commissions processed successfully.',
            'data'    => [
                'commissions_created' => $commissionsCreated,
                'total_amount'        => $totalAmount,
                'period_start'        => $request->period_start,
                'period_end'          => $request->period_end,
            ],
        ]);
    }

    public function approveCommission($id)
    {
        $commission = MlmCommission::findOrFail($id);

        if ($commission->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending commissions can be approved.',
            ], 422);
        }

        $commission->update(['status' => 'approved']);

        return response()->json([
            'success' => true,
            'message' => 'Commission approved.',
        ]);
    }

    public function rejectCommission(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $commission = MlmCommission::findOrFail($id);

        if (!in_array($commission->status, ['pending', 'approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or approved commissions can be rejected.',
            ], 422);
        }

        $commission->update([
            'status'      => 'rejected',
            'description' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Commission rejected.',
        ]);
    }

    public function payCommission($id)
    {
        $commission = MlmCommission::findOrFail($id);

        if ($commission->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved commissions can be paid.',
            ], 422);
        }

        DB::transaction(function () use ($commission) {
            $commission->update(['status' => 'paid']);

            // Update agent's total earnings
            MlmAgent::where('id', $commission->agent_id)
                ->increment('total_commissions_earned', $commission->amount);
        });

        return response()->json([
            'success' => true,
            'message' => 'Commission marked as paid.',
        ]);
    }

    // ─── Statistics ───────────────────────────────────────────────────────────

    public function statistics()
    {
        $byLevel = MlmAgent::select('level', DB::raw('count(*) as count'))
            ->groupBy('level')
            ->orderBy('level')
            ->get();

        $byTerritory = MlmAgent::select('territory_type', 'territory_code', DB::raw('count(*) as count'))
            ->groupBy('territory_type', 'territory_code')
            ->get();

        $topEarners = MlmAgent::with('user')
            ->orderByDesc('total_commissions_earned')
            ->limit(5)
            ->get();

        $topRecruiters = MlmAgent::with('user')
            ->orderByDesc('total_vendors_recruited')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_agents'      => MlmAgent::count(),
                'active_agents'     => MlmAgent::where('status', 'active')->count(),
                'inactive_agents'   => MlmAgent::where('status', 'inactive')->count(),
                'suspended_agents'  => MlmAgent::where('status', 'suspended')->count(),
                'kyc_pending'       => MlmAgent::where('kyc_status', 'pending')->count(),
                'kyc_verified'      => MlmAgent::where('kyc_status', 'verified')->count(),
                'kyc_rejected'      => MlmAgent::where('kyc_status', 'rejected')->count(),
                'by_level'          => $byLevel,
                'by_territory'      => $byTerritory,
                'total_commissions' => MlmCommission::sum('amount'),
                'pending_commissions' => MlmCommission::where('status', 'pending')->sum('amount'),
                'paid_commissions'  => MlmCommission::where('status', 'paid')->sum('amount'),
                'top_earners'       => $topEarners,
                'top_recruiters'    => $topRecruiters,
            ],
        ]);
    }

    // ─── Structure ────────────────────────────────────────────────────────────

    public function structure()
    {
        // Recursively load all agents as a tree (roots only, then eager-load children)
        $roots = MlmAgent::with($this->childrenRecursive())
            ->whereNull('parent_id')
            ->get()
            ->map(fn($a) => $this->formatNode($a));

        return response()->json([
            'success' => true,
            'data'    => $roots,
        ]);
    }

    private function childrenRecursive(): array
    {
        return ['children' => function ($q) {
            $q->with($this->childrenRecursive())->with('user');
        }, 'user'];
    }

    private function formatNode($agent): array
    {
        return [
            'id'               => $agent->id,
            'name'             => $agent->user?->full_name ?? "Agent #{$agent->id}",
            'email'            => $agent->user?->email,
            'level'            => $agent->level,
            'status'           => $agent->status,
            'kyc_status'       => $agent->kyc_status,
            'total_commissions'=> $agent->total_commissions_earned,
            'commission_rate'  => $agent->commission_rate,
            'territory_code'   => $agent->territory_code,
            'territory_type'   => $agent->territory_type,
            'children'         => $agent->children?->map(fn($c) => $this->formatNode($c))->toArray() ?? [],
        ];
    }

    // ─── Levels ───────────────────────────────────────────────────────────────

    public function levels()
    {
        // If you have a mlm_levels table, query it.
        // Otherwise return a sensible default.
        if (class_exists(MlmLevel::class)) {
            $levels = MlmLevel::orderBy('level')->get();
        } else {
            $levels = collect(range(1, 6))->map(fn($l) => [
                'level'              => $l,
                'commission_percentage' => round(10 / $l, 2),
                'required_downline'  => ($l - 1) * 5,
                'required_volume'    => ($l - 1) * 1000,
                'bonus_amount'       => ($l - 1) * 50,
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $levels,
        ]);
    }

    public function updateLevels(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'levels'                          => 'required|array|min:1',
            'levels.*.level'                  => 'required|integer|min:1',
            'levels.*.commission_percentage'  => 'required|numeric|min:0|max:100',
            'levels.*.required_downline'      => 'required|integer|min:0',
            'levels.*.required_volume'        => 'required|numeric|min:0',
            'levels.*.bonus_amount'           => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        if (class_exists(MlmLevel::class)) {
            DB::transaction(function () use ($request) {
                foreach ($request->levels as $levelData) {
                    MlmLevel::updateOrCreate(
                        ['level' => $levelData['level']],
                        [
                            'commission_percentage' => $levelData['commission_percentage'],
                            'required_downline'     => $levelData['required_downline'],
                            'required_volume'       => $levelData['required_volume'],
                            'bonus_amount'          => $levelData['bonus_amount'],
                        ]
                    );
                }
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'MLM levels updated successfully.',
        ]);
    }
}