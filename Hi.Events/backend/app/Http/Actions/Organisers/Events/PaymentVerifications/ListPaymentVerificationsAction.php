<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\PaymentVerifications;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListPaymentVerificationsAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $source = $request->input('source');
        $status = $request->input('status');
        $search = $request->input('search');
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 50);

        $query = DB::table('payment_verifications')
            ->where('event_id', $eventId);

        if ($source) {
            $query->where('source', $source);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('enrollment_no', 'like', '%' . $search . '%')
                  ->orWhere('transaction_id', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $total = $query->count();
        $records = $query->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        // Cross-validate each record
        $formatted = [];
        foreach ($records as $record) {
            $student = DB::table('student_rosters')
                ->where('event_id', $eventId)
                ->where('enrollment_no', $record->enrollment_no)
                ->first();

            $enrollmentValid = $student ? '✅ VALID' : '❌ NOT IN ROSTER';
            
            $nameMatch = '⚠️ CHECK NAME';
            if ($student) {
                $studentFirstWord = strtolower(explode(' ', trim($student->name))[0]);
                $recordFirstWord = strtolower(explode(' ', trim($record->name))[0]);
                if (str_contains($studentFirstWord, $recordFirstWord) || str_contains($recordFirstWord, $studentFirstWord)) {
                    $nameMatch = '✅ MATCHES';
                }
            }

            $alreadyPurchased = $student ? (bool)$student->has_purchased : false;

            $duplicateTxn = false;
            $txnId = trim((string)$record->transaction_id);
            $normalizedTxn = strtolower($txnId);
            $commonPlaceholders = ['n/a', 'na', 'nil', 'none', 'cash', 'upi', '-', '', 'null'];
            if ($txnId !== '' && !in_array($normalizedTxn, $commonPlaceholders, true)) {
                $duplicateTxn = DB::table('payment_verifications')
                    ->where('event_id', $eventId)
                    ->where('id', '!=', $record->id)
                    ->whereRaw('LOWER(TRIM(transaction_id)) = ?', [$normalizedTxn])
                    ->whereIn('status', ['PENDING', 'VERIFIED'])
                    ->exists();
            }

            $formatted[] = [
                'id' => $record->id,
                'source' => $record->source,
                'name' => $record->name,
                'enrollment_no' => $record->enrollment_no,
                'email' => $record->email,
                'phone' => $record->phone,
                'transaction_id' => $record->transaction_id,
                'screenshot_url' => $record->screenshot_url,
                'payment_method' => $record->payment_method,
                'status' => $record->status,
                'notes' => $record->notes,
                'created_at' => $record->created_at,
                'enrollment_valid' => $enrollmentValid,
                'name_match' => $nameMatch,
                'already_purchased' => $alreadyPurchased,
                'duplicate_txn' => $duplicateTxn,
            ];
        }

        return $this->jsonResponse([
            'data' => $formatted,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'last_page' => ceil($total / $limit),
            ],
        ]);
    }
}
