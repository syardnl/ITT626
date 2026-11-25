<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSlaRuleRequest;
use App\Http\Resources\SlaRuleResource;
use App\Models\SlaRule;
use App\Services\Reference\SlaRuleRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The four SLA Targets, one per Priority: list and edit only. */
class SlaRuleController extends Controller
{
    public function __construct(private readonly SlaRuleRegistry $rules) {}

    /** Ordered Critical → Low. */
    public function index(): AnonymousResourceCollection
    {
        return SlaRuleResource::collection(SlaRule::all()->sortBy(fn (SlaRule $rule) => $rule->priority->rank())->values());
    }

    public function update(UpdateSlaRuleRequest $request, SlaRule $slaRule): SlaRuleResource
    {
        return new SlaRuleResource($this->rules->update($slaRule, $request->validated()));
    }
}
