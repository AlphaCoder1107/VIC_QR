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
use Tests\TestCase;

class PricingRulesTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Account $account;
    private Organizer $organizer;
    private Event $event;
    private string $authToken;

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
        $password = 'password123';
        $this->user = User::factory()->password($password)->withAccount()->create();
        $this->account = $this->user->accounts()->first();

        // Log in in test context so that Event observer has an authenticated user
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
            'currency' => 'USD',
            'status' => 'ACTIVE',
        ]);

        // Log out so that we don't interfere with standard HTTP login
        auth()->logout();

        // Login to get JWT token
        $loginResponse = $this->postJson('/auth/login', [
            'email' => $this->user->email,
            'password' => $password,
        ]);
        
        $this->authToken = (string)$loginResponse->headers->get('X-Auth-Token');
    }

    public function test_can_list_pricing_rules(): void
    {
        // Insert a test rule directly
        $ruleId = DB::table('enrollment_pricing_rules')->insertGetId([
            'event_id' => $this->event->id,
            'rule_name' => 'Early Bird',
            'range_start' => 100,
            'range_end' => 199,
            'price_paise' => 15000,
            'is_free' => false,
            'priority' => 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/events/{$this->event->id}/pricing-rules", [
            'Authorization' => 'Bearer ' . $this->authToken,
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'id' => $ruleId,
                'rule_name' => 'Early Bird',
            ]);
    }

    public function test_can_create_pricing_rule_and_writes_audit_log(): void
    {
        $response = $this->postJson("/events/{$this->event->id}/pricing-rules", [
            'rule_name' => 'Super Saver',
            'range_start' => 1,
            'range_end' => 50,
            'price_paise' => 5000,
            'is_free' => false,
            'priority' => 5,
        ], [
            'Authorization' => 'Bearer ' . $this->authToken,
        ]);

        $response->assertStatus(ResponseCodes::HTTP_CREATED)
            ->assertJsonFragment([
                'rule_name' => 'Super Saver',
                'price_paise' => 5000,
            ]);

        $ruleId = $response->json('id');

        // Assert row is created in DB
        $this->assertTrue(DB::table('enrollment_pricing_rules')->where('id', $ruleId)->exists());

        // Assert audit log exists
        $this->assertTrue(DB::table('pricing_rule_audit_log')
            ->where('rule_id', $ruleId)
            ->where('action', 'CREATE')
            ->where('user_id', $this->user->id)
            ->exists());
    }

    public function test_can_update_pricing_rule_and_writes_audit_log(): void
    {
        $ruleId = DB::table('enrollment_pricing_rules')->insertGetId([
            'event_id' => $this->event->id,
            'rule_name' => 'Initial Name',
            'range_start' => 10,
            'range_end' => 20,
            'price_paise' => 10000,
            'is_free' => false,
            'priority' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->putJson("/events/{$this->event->id}/pricing-rules/{$ruleId}", [
            'rule_name' => 'Updated Name',
            'price_paise' => 12000,
        ], [
            'Authorization' => 'Bearer ' . $this->authToken,
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'rule_name' => 'Updated Name',
                'price_paise' => 12000,
            ]);

        // Assert audit log exists
        $this->assertTrue(DB::table('pricing_rule_audit_log')
            ->where('rule_id', $ruleId)
            ->where('action', 'UPDATE')
            ->where('user_id', $this->user->id)
            ->exists());
    }

    public function test_can_delete_pricing_rule_and_writes_audit_log(): void
    {
        $ruleId = DB::table('enrollment_pricing_rules')->insertGetId([
            'event_id' => $this->event->id,
            'rule_name' => 'To Delete',
            'range_start' => 10,
            'range_end' => 20,
            'price_paise' => 10000,
            'is_free' => false,
            'priority' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/events/{$this->event->id}/pricing-rules/{$ruleId}", [], [
            'Authorization' => 'Bearer ' . $this->authToken,
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK);

        // Assert rule active flag is false
        $this->assertEquals(0, DB::table('enrollment_pricing_rules')->where('id', $ruleId)->value('active'));

        // Assert audit log exists
        $this->assertTrue(DB::table('pricing_rule_audit_log')
            ->where('rule_id', $ruleId)
            ->where('action', 'DELETE')
            ->where('user_id', $this->user->id)
            ->exists());
    }
}
