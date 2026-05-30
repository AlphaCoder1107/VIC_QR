<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\Pricing;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreatePricingRuleAction extends BaseAction
{
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validate([
            'rule_name' => 'required|string|max:100',
            'range_start' => 'required|integer|min:1',
            'range_end' => 'nullable|integer|min:1',
            'price_paise' => 'required|integer|min:0',
            'is_free' => 'boolean',
            'priority' => 'integer',
        ]);

        $validated['event_id'] = $eventId;
        $validated['active'] = true;
        $validated['created_at'] = Carbon::now();
        $validated['updated_at'] = Carbon::now();

        $ruleId = DB::transaction(function () use ($validated, $eventId) {
            $id = DB::table('enrollment_pricing_rules')->insertGetId($validated);

            // Log audit
            DB::table('pricing_rule_audit_log')->insert([
                'rule_id' => $id,
                'event_id' => $eventId,
                'user_id' => Auth::id(),
                'action' => 'CREATE',
                'old_value' => null,
                'new_value' => json_encode($validated),
                'created_at' => Carbon::now(),
            ]);

            return $id;
        });

        $rule = DB::table('enrollment_pricing_rules')->where('id', $ruleId)->first();

        return $this->jsonResponse($rule, 201);
    }
}
