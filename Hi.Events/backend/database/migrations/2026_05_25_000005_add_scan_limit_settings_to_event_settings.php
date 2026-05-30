<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_scans_per_ticket')->default(1);
            $table->jsonb('scan_slot_labels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->dropColumn([
                'max_scans_per_ticket',
                'scan_slot_labels',
            ]);
        });
    }
};