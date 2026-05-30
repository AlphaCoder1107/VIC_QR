<?php

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\CheckInList\CreateAttendeeCheckInPublicRequest;
use HiEvents\Resources\CheckInList\AttendeeCheckInPublicResource;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CreateAttendeeCheckInPublicAction extends BaseAction
{
    public function __construct(
        private readonly CreateAttendeeCheckInPublicHandler $createAttendeeCheckInPublicHandler,
    )
    {
    }

    public function __invoke(
        string                             $checkInListUuid,
        CreateAttendeeCheckInPublicRequest $request,
    ): JsonResponse
    {
        $attendees = $request->validated('attendees');
        foreach ($attendees as $item) {
            $attendeePublicId = $item['public_id'];
            $attendee = \Illuminate\Support\Facades\DB::table('attendees')
                ->where('public_id', $attendeePublicId)
                ->first();
            if ($attendee) {
                $dbSettings = \Illuminate\Support\Facades\DB::table('event_settings')
                    ->where('event_id', $attendee->event_id)
                    ->first();
                $maxScans = $dbSettings->max_scans_per_ticket ?? 1;

                $scanCount = \Illuminate\Support\Facades\DB::table('attendee_check_ins')
                    ->where('attendee_id', $attendee->id)
                    ->whereNull('deleted_at')
                    ->count();

                if ($scanCount >= $maxScans) {
                    return $this->jsonResponse([
                        'status' => 'exhausted',
                        'scanned' => $scanCount,
                        'max' => $maxScans,
                        'message' => 'All scans used for this ticket',
                    ], Response::HTTP_CONFLICT);
                }
            }
        }

        try {
            $checkIns = $this->createAttendeeCheckInPublicHandler->handle(CreateAttendeeCheckInPublicDTO::from([
                'checkInListUuid' => $checkInListUuid,
                'checkInUserIpAddress' => $request->ip(),
                'attendeesAndActions' => $request->validated('attendees'),
            ]));
        } catch (CannotCheckInException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: Response::HTTP_CONFLICT,
            );
        }

        return $this->resourceResponse(
            resource: AttendeeCheckInPublicResource::class,
            data: $checkIns->attendeeCheckIns,
            errors: $checkIns->errors->toArray()
        );
    }
}
