<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DeleteFreeTicketPrefixAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId, int $prefixId): Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $deleted = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->where('id', $prefixId)
            ->delete();

        if (!$deleted) {
            return $this->errorResponse('Free prefix not found or unauthorized.', 404);
        }

        return $this->deletedResponse();
    }
}
