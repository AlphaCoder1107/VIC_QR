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

class DeletePricingRuleAction extends BaseAction
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

        DB::transaction(function () use ($rule, $eventId, $ruleId) {
            // Soft delete by setting active = false
            DB::table('enrollment_pricing_rules')
                ->where('id', $ruleId)
                ->update([
                    'active' => false,
                    'updated_at' => Carbon::now(),
                ]);

            $updatedRule = DB::table('enrollment_pricing_rules')->where('id', $ruleId)->first();

            // Log audit
            DB::table('pricing_rule_audit_log')->insert([
                'rule_id' => $ruleId,
                'event_id' => $eventId,
                'user_id' => Auth::id(),
                'action' => 'DELETE',
                'old_value' => json_encode($rule),
                'new_value' => json_encode($updatedRule),
                'created_at' => Carbon::now(),
            ]);
        });

        return $this->jsonResponse(['message' => 'Pricing rule deleted successfully.']);
    }
}
