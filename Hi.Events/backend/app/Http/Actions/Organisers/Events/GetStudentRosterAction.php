<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GetStudentRosterAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $query = DB::table('student_rosters as sr')
            ->where('sr.event_id', $eventId)
            ->select([
                'sr.id',
                'sr.enrollment_no',
                'sr.name',
                'sr.email',
                'sr.has_purchased',
                DB::raw('(
                    SELECT o.razorpay_payment_id
                    FROM orders o
                    WHERE o.enrollment_no = sr.enrollment_no 
                      AND o.event_id = sr.event_id
                    ORDER BY o.status = \'COMPLETED\' DESC, o.id DESC
                    LIMIT 1
                ) as razorpay_payment_id'),
                DB::raw('(
                    SELECT COUNT(*)
                    FROM attendee_check_ins aci
                    JOIN attendees a ON aci.attendee_id = a.id
                    JOIN orders o ON a.order_id = o.id
                    WHERE o.enrollment_no = sr.enrollment_no 
                      AND o.event_id = sr.event_id 
                      AND aci.deleted_at IS NULL
                ) as scan_count'),
                DB::raw('(
                    SELECT MAX(aci.created_at)
                    FROM attendee_check_ins aci
                    JOIN attendees a ON aci.attendee_id = a.id
                    JOIN orders o ON a.order_id = o.id
                    WHERE o.enrollment_no = sr.enrollment_no 
                      AND o.event_id = sr.event_id 
                      AND aci.deleted_at IS NULL
                ) as last_scanned_at')
            ]);

        if ($request->has('search')) {
            $search = trim((string)$request->query('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('sr.enrollment_no', 'like', "%{$search}%")
                      ->orWhere('sr.name', 'like', "%{$search}%");
                });
            }
        }

        if ($request->has('filter')) {
            $filter = $request->query('filter');
            if ($filter === 'purchased') {
                $query->where('sr.has_purchased', true);
            } elseif ($filter === 'not_purchased') {
                $query->where('sr.has_purchased', false);
            } elseif ($filter === 'checked_in') {
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('attendee_check_ins as aci')
                      ->join('attendees as a', 'aci.attendee_id', '=', 'a.id')
                      ->join('orders as o', 'a.order_id', '=', 'o.id')
                      ->whereColumn('o.enrollment_no', '=', 'sr.enrollment_no')
                      ->whereColumn('o.event_id', '=', 'sr.event_id')
                      ->whereNull('aci.deleted_at');
                });
            }
        }

        $paginated = $query->paginate(50);

        return $this->jsonResponse([
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
            ],
        ]);
    }
}
