<?php

namespace Tests\Feature\Http\Actions;

use HiEvents\Http\ResponseCodes;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Tests\TestCase;

class VerifyRazorpayPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Account $account;
    private Organizer $organizer;
    private Event $event;
    private string $keySecret = 'test_secret_12345';

    protected function setUp(): void
    {
        parent::setUp();

        // Create account configuration
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => [
                'percentage' => 1.5,
                'fixed' => 0,
            ]
        ]);

        // Create user with account
        $this->user = User::factory()->withAccount()->create();
        $this->account = $this->user->accounts()->first();

        // Log in in test context
        auth()->login($this->user);

        // Create organizer
        $this->organizer = Organizer::create([
            'account_id' => $this->account->id,
            'name' => 'Test Organizer',
            'email' => 'organizer@test.com',
            'timezone' => 'UTC',
        ]);

        // Create event
        $this->event = Event::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'organizer_id' => $this->organizer->id,
            'title' => 'Test Event',
            'short_id' => 'test_event_' . uniqid(),
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(5)->addHours(2),
            'timezone' => 'UTC',
            'currency' => 'INR',
            'status' => 'ACTIVE',
        ]);

        auth()->logout();

        Config::set('services.razorpay.key_secret', $this->keySecret);
    }

    public function test_verify_payment_requires_fields(): void
    {
        $response = $this->postJson("/public/events/{$this->event->id}/enrollment/verify-payment", []);

        $response->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors([
                'razorpay_order_id',
                'razorpay_payment_id',
                'razorpay_signature',
                'enrollment_no'
            ]);
    }

    public function test_verify_payment_rejects_invalid_signature(): void
    {
        $response = $this->postJson("/public/events/{$this->event->id}/enrollment/verify-payment", [
            'razorpay_order_id' => 'order_123',
            'razorpay_payment_id' => 'pay_123',
            'razorpay_signature' => 'invalid_sig',
            'enrollment_no' => '22045',
        ]);

        $response->assertStatus(ResponseCodes::HTTP_BAD_REQUEST)
            ->assertJsonFragment([
                'message' => 'Invalid payment signature'
            ]);
    }

    public function test_verify_payment_returns_404_if_order_not_found(): void
    {
        $orderId = 'order_valid';
        $paymentId = 'pay_valid';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);

        $response = $this->postJson("/public/events/{$this->event->id}/enrollment/verify-payment", [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'enrollment_no' => '22045',
        ]);

        $response->assertStatus(ResponseCodes::HTTP_NOT_FOUND)
            ->assertJsonFragment([
                'message' => 'Order not found'
            ]);
    }

    public function test_verify_payment_returns_already_processed_if_verified(): void
    {
        $orderId = 'order_valid';
        $paymentId = 'pay_valid';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);

        DB::table('orders')->insert([
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'event_id' => $this->event->id,
            'status' => 'COMPLETED',
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'payment_verified_at' => now(),
            'enrollment_no' => '22045',
            'email' => 'student@test.com',
            'first_name' => 'Student',
            'last_name' => '',
            'locale' => 'en',
            'currency' => 'INR',
            'total_gross' => 200.00,
            'payment_provider' => 'RAZORPAY',
            'payment_gateway' => 'razorpay',
            'payment_status' => \HiEvents\DomainObjects\Status\OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/public/events/{$this->event->id}/enrollment/verify-payment", [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'enrollment_no' => '22045',
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'status' => 'already_processed'
            ]);
    }

    public function test_verify_payment_success_marks_paid_issues_ticket_and_fires_event(): void
    {
        LaravelEvent::fake();

        $orderId = 'order_valid';
        $paymentId = 'pay_valid';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        $enrollmentNo = '22045';

        // Seed roster
        DB::table('student_rosters')->insert([
            'event_id' => $this->event->id,
            'enrollment_no' => $enrollmentNo,
            'name' => 'Student Name',
            'email' => 'student@test.com',
            'has_purchased' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed order
        $orderTableId = DB::table('orders')->insertGetId([
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'event_id' => $this->event->id,
            'status' => 'PENDING',
            'razorpay_order_id' => $orderId,
            'enrollment_no' => $enrollmentNo,
            'email' => 'student@test.com',
            'first_name' => 'Student',
            'last_name' => '',
            'locale' => 'en',
            'currency' => 'INR',
            'total_gross' => 200.00,
            'payment_provider' => 'RAZORPAY',
            'payment_gateway' => 'razorpay',
            'payment_status' => \HiEvents\DomainObjects\Status\OrderPaymentStatus::AWAITING_PAYMENT->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/public/events/{$this->event->id}/enrollment/verify-payment", [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'enrollment_no' => $enrollmentNo,
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'status' => 'verified'
            ]);

        // Assert order is updated
        $this->assertDatabaseHas('orders', [
            'id' => $orderTableId,
            'status' => 'COMPLETED',
            'razorpay_payment_id' => $paymentId,
        ]);

        // Assert roster is updated
        $this->assertDatabaseHas('student_rosters', [
            'event_id' => $this->event->id,
            'enrollment_no' => $enrollmentNo,
            'has_purchased' => true,
        ]);

        // Assert event was fired
        LaravelEvent::assertDispatched(\HiEvents\Events\OrderStatusChangedEvent::class);
    }
}
