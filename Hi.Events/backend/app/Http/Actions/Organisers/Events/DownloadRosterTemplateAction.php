<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\Exports\RosterTemplateExport;
use HiEvents\Http\Actions\BaseAction;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadRosterTemplateAction extends BaseAction
{
    public function __invoke(int $organiserId, int $eventId): BinaryFileResponse
    {
        return Excel::download(new RosterTemplateExport(), 'student_roster_template.xlsx');
    }
}
