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

class UpdatePricingRuleAction extends BaseAction
{
    public function __invoke(Request $request, int $eventId, int $ruleId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $rule = DB::table('enrollment_pricing_rules')
            ->where('event_id', $eventId)
            ->where('id', $ruleId)
            ->first();

        if (!$rule) {
            return $this->errorResponse('Pricing rule not found.', 404);
        }

        $validated = $request->validate([
            'rule_name' => 'string|max:100',
            'range_start' => 'integer|min:1',
            'range_end' => 'nullable|integer|min:1',
            'price_paise' => 'integer|min:0',
            'is_free' => 'boolean',
            'priority' => 'integer',
            'active' => 'boolean',
        ]);

        $validated['updated_at'] = Carbon::now();

        DB::transaction(function () use ($validated, $rule, $eventId, $ruleId) {
            DB::table('enrollment_pricing_rules')
                ->where('id', $ruleId)
                ->update($validated);

            $updatedRule = DB::table('enrollment_pricing_rules')->where('id', $ruleId)->first();

            // Log audit
            DB::table('pricing_rule_audit_log')->insert([
                'rule_id' => $ruleId,
                'event_id' => $eventId,
                'user_id' => Auth::id(),
                'action' => 'UPDATE',
                'old_value' => json_encode($rule),
                'new_value' => json_encode($updatedRule),
                'created_at' => Carbon::now(),
            ]);
        });

        $updatedRule = DB::table('enrollment_pricing_rules')->where('id', $ruleId)->first();

        return $this->jsonResponse($updatedRule);
    }
}
