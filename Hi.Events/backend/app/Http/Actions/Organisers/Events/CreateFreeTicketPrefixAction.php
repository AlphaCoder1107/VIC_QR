<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateFreeTicketPrefixAction extends BaseAction
{
    public function __invoke(Request $request, int $organiserId, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validate([
            'prefix' => ['required', 'string', 'min:3', 'max:10', 'regex:/^\d+$/'],
            'label'  => ['nullable', 'string', 'max:100'],
        ]);

        $prefix = trim($validated['prefix']);
        $label = $validated['label'] ?? null;

        $existing = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->pluck('prefix');

        foreach ($existing as $ep) {
            if (str_starts_with($prefix, (string) $ep) || str_starts_with((string) $ep, $prefix)) {
                return $this->errorResponse(
                    "Prefix '{$prefix}' overlaps with existing prefix '{$ep}'",
                    422
                );
            }
        }

        $id = DB::table('free_ticket_prefixes')->insertGetId([
            'event_id' => $eventId,
            'prefix' => $prefix,
            'label' => $label,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = DB::table('free_ticket_prefixes')->where('id', $id)->first();

        return $this->jsonResponse($created);
    }
}
