<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\PaymentVerifications;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RejectPaymentSubmissionAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId, int $id): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $notes = $request->input('notes');

        $submission = DB::table('payment_verifications')
            ->where('event_id', $eventId)
            ->where('id', $id)
            ->first();

        if (!$submission) {
            return $this->errorResponse('Submission not found.', 404);
        }

        if ($submission->status === 'VERIFIED') {
            return $this->errorResponse('Cannot reject an already verified submission.', 422);
        }

        DB::table('payment_verifications')
            ->where('id', $id)
            ->update([
                'status' => 'REJECTED',
                'notes' => $notes ?: 'Rejected manually via verification dashboard.',
                'updated_at' => Carbon::now(),
            ]);

        return $this->jsonResponse([
            'status' => 'REJECTED',
            'message' => 'Submission has been rejected.',
        ]);
    }
}
