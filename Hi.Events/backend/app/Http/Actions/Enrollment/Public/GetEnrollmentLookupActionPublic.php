<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GetEnrollmentLookupActionPublic extends BaseAction
{
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_no' => ['required', 'string', 'max:50'],
        ]);

        $enrollmentNo = preg_replace('/[^0-9]/', '', trim($validated['enrollment_no']));

        if (strlen($enrollmentNo) < 4 || strlen($enrollmentNo) > 20) {
            return $this->errorResponse('Enrollment number not found. Contact VIC.', 404);
        }

        $student = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->first();

        if (!$student) {
            return $this->errorResponse('Enrollment number not found. Contact VIC.', 404);
        }

        // LAYER 2 — Prefix check against free_ticket_prefixes
        $freePrefixes = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->pluck('prefix')
            ->toArray();

        $isFree = false;
        $matchedPrefix = null;
        foreach ($freePrefixes as $prefix) {
            if (str_starts_with((string) $enrollmentNo, (string) $prefix)) {
                $isFree = true;
                $matchedPrefix = $prefix;
                break;
            }
        }

        // Get paid ticket price from event_settings
        $settings = DB::table('event_settings')
            ->where('event_id', $eventId)
            ->first();
        
        $pricePaise = $settings->ticket_price_paise ?? 20000;

        $metadata = $this->normalizeMetadata($student->metadata ?? null);
        $email = $student->email ? trim((string) $student->email) : null;

        return $this->jsonResponse([
            'enrollment_no' => $student->enrollment_no,
            'student' => [
                'name' => $student->name,
                'branch' => $metadata['branch'] ?? null,
                'year' => $metadata['year'] ?? null,
                'email' => $this->maskEmail($email),
                'editable_email' => $email,
            ],
            'purchase_state' => [
                'has_purchased' => (bool) $student->has_purchased,
                'message' => $student->has_purchased
                    ? 'Ticket already purchased.'
                    : 'Enrollment number verified.',
            ],
            'pricing' => [
                'roll_number' => null,
                'prefix_digits' => 3,
                'rule_name' => $isFree ? ($matchedPrefix ? "Complimentary senior ($matchedPrefix)" : "Complimentary senior") : 'Paid Student Ticket',
                'price_paise' => $isFree ? 0 : $pricePaise,
                'is_free' => $isFree,
                'priority' => 1,
            ],
        ]);
    }

    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        if (!str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visibleLength = min(2, max(1, strlen($local)));
        $visible = substr($local, 0, $visibleLength);

        return $visible . str_repeat('*', max(0, strlen($local) - $visibleLength)) . '@' . $domain;
    }
}