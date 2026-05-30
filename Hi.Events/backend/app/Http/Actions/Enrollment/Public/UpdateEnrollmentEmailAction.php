<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UpdateEnrollmentEmailAction extends BaseAction
{
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_no' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:200'],
        ]);

        $enrollmentNo = trim($validated['enrollment_no']);
        $email = trim(strtolower($validated['email']));

        $student = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->first();

        if (!$student) {
            return $this->errorResponse('Enrollment number not found.', Response::HTTP_NOT_FOUND);
        }

        if ((bool) $student->has_purchased) {
            return $this->errorResponse('Email cannot be updated after ticket purchase.', Response::HTTP_CONFLICT);
        }

        DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->update([
                'email' => $email,
                'updated_at' => now(),
            ]);

        return $this->jsonResponse([
            'status' => 'success',
            'message' => 'Email updated successfully',
            'email' => $email,
        ]);
    }
}
