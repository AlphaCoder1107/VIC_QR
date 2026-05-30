<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\Pricing;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListPricingRulesAction extends BaseAction
{
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $rules = DB::table('enrollment_pricing_rules')
            ->where('event_id', $eventId)
            ->where('active', true)
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        return $this->jsonResponse($rules);
    }
}
