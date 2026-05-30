<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('razorpay_order_id', 100)->nullable()->unique();
            $table->string('razorpay_payment_id', 100)->nullable()->index();
            $table->timestamp('payment_verified_at')->nullable();
            $table->string('enrollment_no', 50)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->dropColumn([
                'razorpay_order_id',
                'razorpay_payment_id',
                'payment_verified_at',
                'enrollment_no',
            ]);
        });
    }
};