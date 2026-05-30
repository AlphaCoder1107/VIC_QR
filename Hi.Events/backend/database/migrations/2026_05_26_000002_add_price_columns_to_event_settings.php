<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->integer('ticket_price_paise')->default(20000);
            $table->timestamp('price_updated_at')->nullable();
            $table->foreignId('price_updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', static function (Blueprint $table): void {
            $table->dropForeign(['price_updated_by']);
            $table->dropColumn([
                'ticket_price_paise',
                'price_updated_at',
                'price_updated_by',
            ]);
        });
    }
};
