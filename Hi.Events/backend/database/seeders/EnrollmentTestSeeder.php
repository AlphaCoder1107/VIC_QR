<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a user if not exists
        $userId = DB::table('users')->where('email', 'admin@vic.college')->value('id');
        if (!$userId) {
            $userId = DB::table('users')->insertGetId([
                'email' => 'admin@vic.college',
                'password' => bcrypt('password'),
                'first_name' => 'Admin',
                'timezone' => 'Asia/Kolkata',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Create a default account if not exists
        $accountId = DB::table('accounts')->where('name', 'VIC College')->value('id');
        if (!$accountId) {
            $accountId = DB::table('accounts')->insertGetId([
                'name' => 'VIC College',
                'email' => 'admin@vic.college',
                'short_id' => 'vic_coll',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2.5. Link user to account if not exists
        $hasAccountUser = DB::table('account_users')
            ->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->exists();
        if (!$hasAccountUser) {
            DB::table('account_users')->insert([
                'account_id' => $accountId,
                'user_id' => $userId,
                'role' => 'ADMIN',
                'is_account_owner' => true,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Create a default organizer if not exists
        $organizerId = DB::table('organizers')->where('email', 'committee@codepode.in')->value('id');
        if (!$organizerId) {
            $organizerId = DB::table('organizers')->insertGetId([
                'account_id' => $accountId,
                'name' => 'VIC Committee',
                'email' => 'committee@codepode.in',
                'timezone' => 'Asia/Kolkata',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4. Create event if not exists
        $eventId = DB::table('events')->where('short_id', 'vic_farewell')->value('id');
        if (!$eventId) {
            $eventId = DB::table('events')->insertGetId([
                'account_id' => $accountId,
                'user_id' => $userId,
                'organizer_id' => $organizerId,
                'title' => 'VIC Farewell 2026',
                'short_id' => 'vic_farewell',
                'start_date' => now()->addDays(10),
                'end_date' => now()->addDays(10)->addHours(4),
                'timezone' => 'Asia/Kolkata',
                'currency' => 'INR',
                'status' => 'LIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Create event settings if not exists
        $hasSettings = DB::table('event_settings')->where('event_id', $eventId)->exists();
        if (!$hasSettings) {
            DB::table('event_settings')->insert([
                'event_id' => $eventId,
                'enrollment_roll_number_prefix_digits' => 3,
                'enrollment_roll_number_pattern' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5.4. Create a product category for the event if not exists
        $categoryId = DB::table('product_categories')->where('event_id', $eventId)->value('id');
        if (!$categoryId) {
            $categoryId = DB::table('product_categories')->insertGetId([
                'name' => 'Tickets',
                'event_id' => $eventId,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5.5. Create a product and product price for the event if not exists
        $productId = DB::table('products')->where('event_id', $eventId)->value('id');
        if (!$productId) {
            $productId = DB::table('products')->insertGetId([
                'title' => 'VIC Farewell Ticket',
                'event_id' => $eventId,
                'product_category_id' => $categoryId,
                'order' => 1,
                'type' => 'PAID',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('products')
                ->where('id', $productId)
                ->update(['product_category_id' => $categoryId]);
        }

        $hasPrice = DB::table('product_prices')->where('product_id', $productId)->exists();
        if (!$hasPrice) {
            DB::table('product_prices')->insert([
                'product_id' => $productId,
                'price' => 200.00,
                'label' => 'Standard Ticket',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Create student roster entries
        DB::table('student_rosters')->delete();
        DB::table('student_rosters')->insert([
            [
                'event_id' => $eventId,
                'enrollment_no' => '22045',
                'name' => 'Aditya Sharma',
                'email' => 'aditya@example.com',
                'metadata' => json_encode(['branch' => 'CSE', 'year' => '4']),
                'has_purchased' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $eventId,
                'enrollment_no' => '21033',
                'name' => 'Bhumika Patel',
                'email' => 'bhumika@example.com',
                'metadata' => json_encode(['branch' => 'ECE', 'year' => '3']),
                'has_purchased' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $eventId,
                'enrollment_no' => '23001',
                'name' => 'Rahul Verma',
                'email' => 'rahul@example.com',
                'metadata' => json_encode(['branch' => 'CSE', 'year' => '3']),
                'has_purchased' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 7. Create enrollment pricing rules
        DB::table('enrollment_pricing_rules')->delete();
        DB::table('enrollment_pricing_rules')->insert([
            [
                'event_id' => $eventId,
                'rule_name' => 'Senior',
                'range_start' => 220,
                'range_end' => 229,
                'is_free' => true,
                'price_paise' => 0,
                'priority' => 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $eventId,
                'rule_name' => 'Student Ticket',
                'range_start' => 200,
                'range_end' => 219,
                'is_free' => false,
                'price_paise' => 20000,
                'priority' => 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $eventId,
                'rule_name' => '3rd Year Ticket',
                'range_start' => 230,
                'range_end' => 239,
                'is_free' => false,
                'price_paise' => 20000,
                'priority' => 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
        
        DB::table('free_ticket_prefixes')->upsert([
            [
                'event_id' => $eventId,
                'prefix' => '220',
                'label' => 'Final Year 2022 Batch',
                'created_at' => now(),
            ]
        ], ['event_id', 'prefix'], ['label']);
        
        $this->command->info("Seeded successfully. Event ID: " . $eventId);
    }
}
