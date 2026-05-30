<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendee_check_ins', static function (Blueprint $table): void {
            $table->integer('scan_slot')->default(1);
            $table->string('slot_label', 100)->default('Entry');
        });
    }

    public function down(): void
    {
        Schema::table('attendee_check_ins', static function (Blueprint $table): void {
            $table->dropColumn([
                'scan_slot',
                'slot_label',
            ]);
        });
    }
};
