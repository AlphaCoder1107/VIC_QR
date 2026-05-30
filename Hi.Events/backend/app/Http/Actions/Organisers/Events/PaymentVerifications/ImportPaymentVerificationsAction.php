<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\PaymentVerifications;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportPaymentVerificationsAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $request->validate([
            'file' => 'required|file|max:51200',
            'source' => 'required|string|in:btech,bca',
        ]);

        $file = $request->file('file');
        $source = $request->input('source');
        $path = $file->getRealPath();

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to parse file: ' . $e->getMessage(), 422);
        }

        if (empty($rows)) {
            return $this->errorResponse('The spreadsheet is empty.', 422);
        }

        $headerRow = array_shift($rows);
        $colLetter = [
            'name' => null,
            'enrollment_no' => null,
            'email' => null,
            'phone' => null,
            'transaction_id' => null,
            'payment_method' => null,
            'screenshot_url' => null,
        ];

        foreach ($headerRow as $letter => $val) {
            $valClean = strtolower(str_replace(['_', ' ', '/', '.', '-'], '', trim((string)$val)));
            
            // Match Name
            if (in_array($valClean, ['name', 'studentname', 'fullname', 'username'])) {
                $colLetter['name'] = $letter;
            }
            // Match Enrollment
            else if (in_array($valClean, ['enrollmentno', 'enrollment', 'rollno', 'rollnumber', 'enrollmentnumberrollnumber'])) {
                $colLetter['enrollment_no'] = $letter;
            }
            // Match Email
            else if (in_array($valClean, ['email', 'emailaddress', 'enteryouremailidticketstobereceived'])) {
                $colLetter['email'] = $letter;
            }
            // Match Phone
            else if (in_array($valClean, ['phone', 'phoneno', 'phonenumber', 'mobile', 'mobilenumber'])) {
                $colLetter['phone'] = $letter;
            }
            // Match Transaction ID
            else if (in_array($valClean, ['transactionid', 'transaction', 'txnid', 'enterthetransactionidonlycopypasteit'])) {
                $colLetter['transaction_id'] = $letter;
            }
            // Match Payment Method
            else if (in_array($valClean, ['paymentmethod', 'paidcash', 'payment', 'paidcashcontact', 'iconsenttogivee200rsforthefarewellcontribution'])) {
                $colLetter['payment_method'] = $letter;
            }
            // Match Screenshot
            else if (in_array($valClean, ['screenshot', 'screenshoturl', 'attachscreenshotwithtransactionid'])) {
                $colLetter['screenshot_url'] = $letter;
            }
        }

        // Fallbacks if not matched explicitly
        if (!$colLetter['name']) $colLetter['name'] = 'B';
        if (!$colLetter['enrollment_no']) $colLetter['enrollment_no'] = 'C';
        if (!$colLetter['email']) $colLetter['email'] = 'E';
        if (!$colLetter['phone']) $colLetter['phone'] = 'D';
        if (!$colLetter['transaction_id']) $colLetter['transaction_id'] = 'H';
        if (!$colLetter['screenshot_url']) $colLetter['screenshot_url'] = 'I';

        $imported = 0;
        $skipped = 0;
        $validRows = [];

        foreach ($rows as $row) {
            // Check if row is empty
            $isRowEmpty = true;
            foreach ($row as $val) {
                if (trim((string)$val) !== '') {
                    $isRowEmpty = false;
                    break;
                }
            }
            if ($isRowEmpty) {
                continue;
            }

            $name = trim((string)($row[$colLetter['name']] ?? ''));
            $enrollmentNo = preg_replace('/[^0-9]/', '', trim((string)($row[$colLetter['enrollment_no']] ?? '')));
            $email = trim((string)($row[$colLetter['email']] ?? ''));
            $phone = trim((string)($row[$colLetter['phone']] ?? ''));
            $transactionId = trim((string)($row[$colLetter['transaction_id']] ?? ''));
            $paymentMethod = trim((string)($row[$colLetter['payment_method']] ?? ''));
            $screenshotUrl = trim((string)($row[$colLetter['screenshot_url']] ?? ''));

            if ($enrollmentNo === '' || $name === '') {
                continue; // Skip invalid lines
            }

            // Check if already imported
            $exists = DB::table('payment_verifications')
                ->where('event_id', $eventId)
                ->where('source', $source)
                ->where('enrollment_no', $enrollmentNo)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $validRows[] = [
                'event_id' => $eventId,
                'source' => $source,
                'name' => $name,
                'enrollment_no' => $enrollmentNo,
                'email' => $email,
                'phone' => $phone ?: null,
                'transaction_id' => $transactionId ?: null,
                'screenshot_url' => $screenshotUrl ?: null,
                'payment_method' => $paymentMethod ?: 'Paid',
                'status' => 'PENDING',
                'notes' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
            $imported++;
        }

        if (!empty($validRows)) {
            $chunks = array_chunk($validRows, 500);
            foreach ($chunks as $chunk) {
                DB::table('payment_verifications')->insert($chunk);
            }
        }

        return $this->jsonResponse([
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }
}
