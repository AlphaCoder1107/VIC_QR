<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateTicketPriceAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validate([
            'price' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $rupees = (int) $validated['price'];

        DB::table('event_settings')
            ->where('event_id', $eventId)
            ->update([
                'ticket_price_paise' => $rupees * 100,
                'price_updated_at'   => now(),
                'price_updated_by'   => auth()->id(),
            ]);

        return $this->jsonResponse([
            'ticket_price_paise' => $rupees * 100,
            'message' => 'Price updated successfully.',
        ]);
    }
}
