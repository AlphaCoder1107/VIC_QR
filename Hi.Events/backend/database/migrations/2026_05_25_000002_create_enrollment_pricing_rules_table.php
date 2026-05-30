<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enrollment_pricing_rules', static function (Blueprint $table): void {
            $table->id();

            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->string('rule_name', 100);
            $table->unsignedInteger('range_start');
            $table->unsignedInteger('range_end')->nullable();
            $table->unsignedInteger('price_paise');
            $table->boolean('is_free')->default(false);
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['event_id', 'rule_name']);
            $table->index(['event_id', 'active', 'priority']);
            $table->index(['event_id', 'range_start', 'range_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_pricing_rules');
    }
};