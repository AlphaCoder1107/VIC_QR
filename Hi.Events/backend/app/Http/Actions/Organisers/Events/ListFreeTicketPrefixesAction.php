<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListFreeTicketPrefixesAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $prefixes = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->orderBy('prefix', 'asc')
            ->get();

        $settings = DB::table('event_settings')
            ->where('event_id', $eventId)
            ->first();

        return $this->jsonResponse([
            'prefixes' => $prefixes,
            'ticket_price_paise' => $settings->ticket_price_paise ?? 20000,
        ]);
    }
}
