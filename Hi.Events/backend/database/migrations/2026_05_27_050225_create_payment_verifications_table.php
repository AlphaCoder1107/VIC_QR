<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->integer('event_id')->index();
            $table->string('source'); // 'btech' or 'bca'
            $table->string('name');
            $table->string('enrollment_no');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('screenshot_url')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status')->default('PENDING'); // PENDING, VERIFIED, REJECTED
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_verifications');
    }
};
