<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_rosters', static function (Blueprint $table): void {
            $table->id();

            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->string('enrollment_no', 50);
            $table->string('name', 200)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 20)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('has_purchased')->default(false);
            $table->timestamp('purchased_at')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'enrollment_no']);
            $table->index(['event_id', 'has_purchased']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_rosters');
    }
};