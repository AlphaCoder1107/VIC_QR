<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Order\SendOrderDetailsEmailJob;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResendEnrollmentTicketAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_no' => ['required', 'string', 'max:50'],
        ]);

        $enrollmentNo = trim($validated['enrollment_no']);

        $student = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->first();

        if (!$student) {
            return $this->errorResponse('Enrollment number not found.', Response::HTTP_NOT_FOUND);
        }

        if (!(bool) $student->has_purchased) {
            return $this->errorResponse('No ticket has been purchased/claimed for this enrollment number.', Response::HTTP_BAD_REQUEST);
        }

        $orderRow = DB::table('orders')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->where('status', 'COMPLETED')
            ->first();

        if (!$orderRow) {
            return $this->errorResponse('No completed order found for this enrollment number.', Response::HTTP_NOT_FOUND);
        }

        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::ID => $orderRow->id,
        ]);

        if ($order !== null) {
            SendOrderDetailsEmailJob::dispatch($order);
        }

        return $this->jsonResponse([
            'status' => 'resent',
            'message' => 'Ticket email has been resent successfully.',
        ]);
    }
}
