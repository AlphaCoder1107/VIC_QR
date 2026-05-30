<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->string('enrollment_roll_number_pattern', 255)->nullable();
            $table->unsignedTinyInteger('enrollment_roll_number_prefix_digits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->dropColumn([
                'enrollment_roll_number_pattern',
                'enrollment_roll_number_prefix_digits',
            ]);
        });
    }
};