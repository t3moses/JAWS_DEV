<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Test Data Seeder for CI/CD
 *
 * Populates the database with minimal test data for running API tests.
 * This seeder runs non-interactively and is designed for CI/CD pipelines.
 *
 * Seeds:
 * - Events (18 events from the full 2026 season schedule)
 * - Users (4 test accounts: 2 boat owners, 2 crew members)
 * - Boats (2 boats linked to user accounts)
 * - Crews (2 crew members linked to user accounts)
 * - Boat/crew availability for all events
 *
 * Test credentials: All users have password "password123"
 *
 * Usage:
 *   vendor/bin/phinx seed:run
 *   vendor/bin/phinx seed:run -s TestDataSeeder  # Run specific seeder
 *
 * Converted from: database/seed_test_data.php
 */
class TestDataSeeder extends AbstractSeed
{
    /**
     * Run Method.
     *
     * Seeds the database with test data for development and CI/CD testing.
     */
    public function run(): void
    {
        // ====================================================================
        // Clear existing test data (for idempotency)
        // ====================================================================
        $this->execute('DELETE FROM flotillas');
        $this->execute('DELETE FROM crew_whitelist');
        $this->execute('DELETE FROM crew_history');
        $this->execute('DELETE FROM boat_history');
        $this->execute('DELETE FROM crew_availability');
        $this->execute('DELETE FROM boat_availability');
        $this->execute('DELETE FROM crews');
        $this->execute('DELETE FROM boats');
        $this->execute('DELETE FROM users');
        $this->execute('DELETE FROM events');

        // ====================================================================
        // Seed events - Full 2026 Season (18 events)
        // ====================================================================
        // Based on: https://github.com/t3moses/JAWS/blob/5a739b53c4e9f7280bbab36b4d6841f6930c1c4b/Libraries/Season/data/config.json
        $events = [
            [
                'event_id' => 'Fri May 29',
                'event_date' => '2026-05-29',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jun 5',
                'event_date' => '2026-06-05',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jun 12',
                'event_date' => '2026-06-12',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jun 19',
                'event_date' => '2026-06-19',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Sat Jun 27',
                'event_date' => '2026-06-27',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jul 3',
                'event_date' => '2026-07-03',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jul 10',
                'event_date' => '2026-07-10',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Sat Jul 18',
                'event_date' => '2026-07-18',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jul 24',
                'event_date' => '2026-07-24',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Jul 31',
                'event_date' => '2026-07-31',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Sat Aug 8',
                'event_date' => '2026-08-08',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Aug 14',
                'event_date' => '2026-08-14',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Aug 21',
                'event_date' => '2026-08-21',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Sat Aug 29',
                'event_date' => '2026-08-29',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Sep 4',
                'event_date' => '2026-09-04',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Sep 11',
                'event_date' => '2026-09-11',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Sep 18',
                'event_date' => '2026-09-18',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
            [
                'event_id' => 'Fri Sep 25',
                'event_date' => '2026-09-25',
                'start_time' => '12:45:00',
                'finish_time' => '17:00:00',
                'status' => 'upcoming'
            ],
        ];

        $this->table('events')->insert($events)->saveData();

        // ====================================================================
        // Seed users
        // ====================================================================
        // Generate password hash for test password "password123"
        $passwordHash = password_hash('password123', PASSWORD_DEFAULT);

        $users = [
            [
                'email' => 'alice@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'bob@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'jane@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'mike@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
        ];

        $this->table('users')->insert($users)->saveData();

        // ====================================================================
        // Seed sample boats (with user links)
        // ====================================================================
        // Link boats to users by fetching user IDs from emails
        $this->execute("
            INSERT INTO boats (key, display_name, owner_first_name, owner_last_name, owner_mobile, min_berths, max_berths, assistance_required, social_preference, owner_user_id)
            VALUES
                ('sailaway', 'Sailaway', 'Alice', 'Johnson', '555-0001', 2, 4, 'No', 'Yes',
                 (SELECT id FROM users WHERE email = 'alice@example.com')),
                ('windchaser', 'Windchaser', 'Bob', 'Smith', '555-0002', 2, 3, 'Yes', 'No',
                 (SELECT id FROM users WHERE email = 'bob@example.com'))
        ");

        // ====================================================================
        // Seed sample crews (with user links)
        // ====================================================================
        // Link crews to users by fetching user IDs from emails
        $this->execute("
            INSERT INTO crews (key, display_name, first_name, last_name, mobile, skill, membership_number, user_id)
            VALUES
                ('jane_doe', 'Jane Doe', 'Jane', 'Doe', '555-1001', 1, 'NSC001',
                 (SELECT id FROM users WHERE email = 'jane@example.com')),
                ('mike_wilson', 'Mike Wilson', 'Mike', 'Wilson', '555-1002', 2, 'NSC002',
                 (SELECT id FROM users WHERE email = 'mike@example.com'))
        ");

        // ====================================================================
        // Seed boat availability (boats offering berths for all events)
        // ====================================================================
        $this->execute('
            INSERT INTO boat_availability (boat_id, event_id, berths)
            SELECT b.id, e.event_id, b.max_berths
            FROM boats b
            CROSS JOIN events e
        ');

        // ====================================================================
        // Seed crew availability (crews marked as available for all events)
        // ====================================================================
        $this->execute('
            INSERT INTO crew_availability (crew_id, event_id, status)
            SELECT c.id, e.event_id, 1
            FROM crews c
            CROSS JOIN events e
        ');
    }
}
