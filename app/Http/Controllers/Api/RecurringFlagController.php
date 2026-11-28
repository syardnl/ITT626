<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecurringFlagStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recurring\DecideRecurringFlagRequest;
use App\Http\Resources\RecurringFlagResource;
use App\Models\Complaint;
use App\Models\RecurringFlag;
use App\Services\Recurring\RecurringFlagDecisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Recurring Problems for the Manager: what needs a decision, then the history. */
class RecurringFlagController extends Controller
{
    private const HISTORY_SHOWN = 50;

    public function __construct(private readonly RecurringFlagDecisions $decisions) {}

    /**
     * `data`: current open flags, then acknowledged ones, active before inactive, most recent report
     * first. `history`: dismissed and superseded flags, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $with = ['location', 'category', 'decider'];
        $current = RecurringFlag::query()->current()->where('status', '!=', RecurringFlagStatus::Dismissed)->with($with)->get()
            ->sortBy([
                fn (RecurringFlag $a, RecurringFlag $b) => ($a->status === RecurringFlagStatus::Open ? 0 : 1) <=> ($b->status === RecurringFlagStatus::Open ? 0 : 1),
                fn (RecurringFlag $a, RecurringFlag $b) => $b->active <=> $a->active,
                fn (RecurringFlag $a, RecurringFlag $b) => $b->last_reported_at <=> $a->last_reported_at,
            ])->values();
        $history = RecurringFlag::query()
            ->where(fn ($query) => $query->whereNotNull('superseded_at')->orWhere('status', RecurringFlagStatus::Dismissed))
            ->with($with)->latest('updated_at')->latest('id')->limit(self::HISTORY_SHOWN)->get();
        $complaints = $this->complaintsOf($current->concat($history));

        return response()->json([
            'data' => $current->map(fn (RecurringFlag $flag) => (new RecurringFlagResource($flag))->withComplaints($complaints)->toArray($request))->all(),
            'history' => $history->map(fn (RecurringFlag $flag) => (new RecurringFlagResource($flag))->withComplaints($complaints)->toArray($request))->all(),
        ]);
    }

    public function acknowledge(DecideRecurringFlagRequest $request, int $flag): JsonResponse
    {
        return $this->decide($request, $flag, RecurringFlagStatus::Acknowledged);
    }

    public function dismiss(DecideRecurringFlagRequest $request, int $flag): JsonResponse
    {
        return $this->decide($request, $flag, RecurringFlagStatus::Dismissed);
    }

    private function decide(DecideRecurringFlagRequest $request, int $flag, RecurringFlagStatus $decision): JsonResponse
    {
        $decided = $this->decisions->decide($request->user(), $flag, $decision, $request->reason())->load(['location', 'category', 'decider']);

        return response()->json(['data' => (new RecurringFlagResource($decided))->withComplaints($this->complaintsOf(collect([$decided])))->toArray($request)]);
    }

    /** @return Collection<int, Complaint> keyed by id: every Complaint the flags list, in one query */
    private function complaintsOf(Collection $flags): Collection
    {
        $ids = $flags->flatMap(fn (RecurringFlag $flag) => $flag->complaint_ids)->unique()->values();

        return Complaint::query()->whereKey($ids)->get(['id', 'reference_no', 'title', 'status', 'created_at'])->keyBy('id');
    }
}
