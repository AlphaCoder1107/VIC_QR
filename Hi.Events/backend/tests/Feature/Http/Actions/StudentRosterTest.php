<?php

namespace Tests\Feature\Http\Actions;

use HiEvents\Http\ResponseCodes;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentRosterTest extends TestCase
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
            'currency' => 'USD',
            'status' => 'ACTIVE',
        ]);

        auth()->logout();

        // Login to get JWT token
        $loginResponse = $this->postJson('/auth/login', [
            'email' => $this->user->email,
            'password' => $password,
        ]);
        
        $this->authToken = (string)$loginResponse->headers->get('X-Auth-Token');
    }

    public function test_can_import_student_roster(): void
    {
        // 1. Prepare CSV Content
        $csvContent = "enrollment_no,name,email,phone\n";
        $csvContent .= "23001,Rahul Verma,rahul@example.com,9876543210\n";
        $csvContent .= "22045,Aditya Sharma,aditya@example.com,9876543211\n";

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csvContent);

        // 2. Perform POST request
        $response = $this->postJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster/import",
            ['file' => $file],
            ['Authorization' => 'Bearer ' . $this->authToken]
        );

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'imported' => 2,
                'updated' => 0,
                'skipped_purchased' => 0,
            ]);

        // Verify records exist in database
        $this->assertDatabaseHas('student_rosters', [
            'event_id' => $this->event->id,
            'enrollment_no' => '23001',
            'name' => 'Rahul Verma',
        ]);

        $this->assertDatabaseHas('student_rosters', [
            'event_id' => $this->event->id,
            'enrollment_no' => '22045',
            'name' => 'Aditya Sharma',
        ]);
    }

    public function test_import_updates_unpurchased_and_skips_purchased_records(): void
    {
        // 1. Seed two student roster records
        DB::table('student_rosters')->insert([
            [
                'event_id' => $this->event->id,
                'enrollment_no' => '23001',
                'name' => 'Original Name 1',
                'email' => 'original1@example.com',
                'has_purchased' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $this->event->id,
                'enrollment_no' => '22045',
                'name' => 'Original Name 2',
                'email' => 'original2@example.com',
                'has_purchased' => true, // Already purchased, MUST NOT BE OVERWRITTEN
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 2. Upload CSV with updated values
        $csvContent = "enrollment_no,name,email,phone\n";
        $csvContent .= "23001,Updated Name 1,updated1@example.com,9876543210\n";
        $csvContent .= "22045,Updated Name 2,updated2@example.com,9876543211\n"; // Should be skipped
        $csvContent .= "21033,New Student,new@example.com,9876543212\n"; // New record

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csvContent);

        $response = $this->postJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster/import",
            ['file' => $file],
            ['Authorization' => 'Bearer ' . $this->authToken]
        );

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonFragment([
                'imported' => 1,
                'updated' => 1,
                'skipped_purchased' => 1,
            ]);

        // Assert unpurchased student is updated
        $this->assertDatabaseHas('student_rosters', [
            'enrollment_no' => '23001',
            'name' => 'Updated Name 1',
            'email' => 'updated1@example.com',
        ]);

        // Assert purchased student is NOT updated (retains original name/email)
        $this->assertDatabaseHas('student_rosters', [
            'enrollment_no' => '22045',
            'name' => 'Original Name 2',
            'email' => 'original2@example.com',
        ]);

        // Assert new student is inserted
        $this->assertDatabaseHas('student_rosters', [
            'enrollment_no' => '21033',
            'name' => 'New Student',
        ]);
    }

    public function test_can_list_and_search_student_roster(): void
    {
        // Seed some students
        DB::table('student_rosters')->insert([
            [
                'event_id' => $this->event->id,
                'enrollment_no' => '23001',
                'name' => 'Rahul Verma',
                'email' => 'rahul@example.com',
                'has_purchased' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $this->event->id,
                'enrollment_no' => '22045',
                'name' => 'Aditya Sharma',
                'email' => 'aditya@example.com',
                'has_purchased' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Get Roster List
        $response = $this->getJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster",
            ['Authorization' => 'Bearer ' . $this->authToken]
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertCount(2, $response->json('data'));

        // Search by name
        $responseSearch = $this->getJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster?search=Rahul",
            ['Authorization' => 'Bearer ' . $this->authToken]
        );
        $responseSearch->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertCount(1, $responseSearch->json('data'));
        $this->assertEquals('23001', $responseSearch->json('data.0.enrollment_no'));

        // Filter by purchased status
        $responseFilter = $this->getJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster?filter=purchased",
            ['Authorization' => 'Bearer ' . $this->authToken]
        );
        $responseFilter->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertCount(1, $responseFilter->json('data'));
        $this->assertEquals('23001', $responseFilter->json('data.0.enrollment_no'));
    }

    public function test_can_download_roster_template(): void
    {
        $response = $this->getJson(
            "/auth/organiser/{$this->organizer->id}/events/{$this->event->id}/roster/template",
            ['Authorization' => 'Bearer ' . $this->authToken]
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition', 'attachment; filename=student_roster_template.xlsx');
    }
}
