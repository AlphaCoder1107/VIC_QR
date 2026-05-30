<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportStudentRosterAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $request->validate([
            'file' => 'required|file|max:51200',
        ]);

        $file = $request->file('file');
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
            'enrollment_no' => null,
            'name' => null,
            'email' => null,
            'phone' => null,
        ];

        foreach ($headerRow as $letter => $val) {
            $valClean = strtolower(str_replace(['_', ' '], '', trim((string)$val)));
            if ($valClean === 'enrollmentno' || $valClean === 'enrollment' || $valClean === 'rollno' || $valClean === 'rollnumber') {
                $colLetter['enrollment_no'] = $letter;
            } else if ($valClean === 'name' || $valClean === 'studentname') {
                $colLetter['name'] = $letter;
            } else if ($valClean === 'email' || $valClean === 'emailaddress') {
                $colLetter['email'] = $letter;
            } else if ($valClean === 'phone' || $valClean === 'phoneno' || $valClean === 'phonenumber' || $valClean === 'mobile') {
                $colLetter['phone'] = $letter;
            }
        }

        if (!$colLetter['enrollment_no']) $colLetter['enrollment_no'] = 'A';
        if (!$colLetter['name']) $colLetter['name'] = 'B';
        if (!$colLetter['email']) $colLetter['email'] = 'C';
        if (!$colLetter['phone']) $colLetter['phone'] = 'D';

        $existingRosters = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->select(['enrollment_no', 'has_purchased'])
            ->get();

        $purchasedMap = [];
        $existingMap = [];
        foreach ($existingRosters as $roster) {
            if ($roster->has_purchased) {
                $purchasedMap[$roster->enrollment_no] = true;
            } else {
                $existingMap[$roster->enrollment_no] = true;
            }
        }

        $imported = 0;
        $updated = 0;
        $skipped_purchased = 0;
        $errors = [];
        $validRows = [];

        $rowNum = 1; // 1 represents the header row
        foreach ($rows as $row) {
            $rowNum++;

            // If the entire row is empty, skip it without logging an error
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

            $enrollmentNo = trim((string)($row[$colLetter['enrollment_no']] ?? ''));
            $name = trim((string)($row[$colLetter['name']] ?? ''));
            $email = trim((string)($row[$colLetter['email']] ?? ''));
            $phone = trim((string)($row[$colLetter['phone']] ?? ''));

            if ($enrollmentNo === '') {
                $errors[] = [
                    'row' => $rowNum,
                    'enrollment_no' => '',
                    'reason' => 'Enrollment number is empty.'
                ];
                continue;
            }

            if (isset($purchasedMap[$enrollmentNo])) {
                $skipped_purchased++;
                continue;
            }

            if (isset($existingMap[$enrollmentNo])) {
                $updated++;
            } else {
                $imported++;
            }

            $validRows[] = [
                'event_id' => $eventId,
                'enrollment_no' => $enrollmentNo,
                'name' => $name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'has_purchased' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        if (!empty($validRows)) {
            $chunks = array_chunk($validRows, 500);
            foreach ($chunks as $chunk) {
                DB::table('student_rosters')->upsert(
                    $chunk,
                    ['event_id', 'enrollment_no'],
                    ['name', 'email', 'phone', 'updated_at']
                );
            }
        }

        return $this->jsonResponse([
            'imported' => $imported,
            'updated' => $updated,
            'skipped_purchased' => $skipped_purchased,
            'errors' => $errors,
        ]);
    }
}
