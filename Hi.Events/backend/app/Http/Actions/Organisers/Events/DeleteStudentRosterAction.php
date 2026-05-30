<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteStudentRosterAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId, int $rosterId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $deleted = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('id', $rosterId)
            ->delete();

        if (!$deleted) {
            return $this->jsonResponse([
                'message' => 'Roster entry not found.'
            ], 404);
        }

        return $this->jsonResponse([
            'message' => 'Roster entry deleted successfully.'
        ]);
    }
}
