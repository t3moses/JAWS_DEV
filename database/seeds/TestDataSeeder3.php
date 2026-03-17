<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Test Data Seeder for CI/CD
 *
 * Populates the database with representative test data for running API tests.
 * This seeder runs non-interactively and is designed for CI/CD pipelines.
 *
 * Seeds:
 * - Events (18 events from the full 2026 season schedule)
 * - Users (30 test accounts:  6 boat owners, 24 crew members)
 * - Boats (6 boats linked to user accounts)
 * - Crews (24 crew members linked to user accounts)
 * - Boat/crew availability for all events
 *
 * Test credentials: All users have password "password123"
 *
 * Usage:
 *   vendor/bin/phinx seed:run
 *   vendor/bin/phinx seed:run -s TestDataSeeder3  # Run specific seeder
 *
 * Converted from: database/seed_test_data.php
 * 
 * Boat and crew names reflect their profiles, such that policy violations can be
 * identified by inspection of the event schedule.
 * Note: a flexible value of 0 means the owner may act as crew.
 * 
 * Boat name conventions:
 * 
 * BAxBxFxnx; A=Assist[0,1], B=Berths[1,4], F=Flexible[0,1], n=Serial
 *
 * Crew name conventions:
 * 
 * CMxSxFxnx; M=Member{0,1}, S=Skill[0,2},F=Flexible[0,1], n=Serial
 * 
 */
class TestDataSeeder3 extends AbstractSeed
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
                'email' => 'ba0b3f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 1
            ],
            [
                'email' => 'ba0b3f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'ba0b4f0n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'ba0b5f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'ba1b2f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'ba1b4f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'boat_owner',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s0f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s0f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s1f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s1f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s2f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm0s2f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
               'email' => 'cm1s0f0n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s0f0n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s0f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s0f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s0f1n2@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s0f1n3@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n2@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n3@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n4@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s1f1n5@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [   'email' => 'cm1s2f1n0@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s2f1n1@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s2f1n2@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s2f1n3@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s2f1n4@example.com',
                'password_hash' => $passwordHash,
                'account_type' => 'crew',
                'is_admin' => 0
            ],
            [
                'email' => 'cm1s2f1n5@example.com',
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
                ('ba0b3f1n0', 'BA0B3F1N0', 'A', 'A', '555-0001', 1, 2, 'Yes', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba0b3f1n0@example.com')),
                ('ba0b3f1n1', 'BA0B3F1N1', 'B', 'A', '555-0001', 1, 3, 'No', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba0b3f1n1@example.com')),
                ('ba0b4f0n0', 'BA0B4F0N0', 'C', 'A', '555-0001', 1, 4, 'No', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba0b4f0n0@example.com')),
                ('ba0b5f1n0', 'BA0B5F1N0', 'D', 'A', '555-0001', 1, 4, 'Yes', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba0b5f1n0@example.com')),
                ('ba1b2f1n0', 'BA1B2F1N0', 'E', 'A', '555-0001', 1, 3, 'No', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba1b2f1n0@example.com')),
                ('ba1b4f1n0', 'BA1B4F1N0', 'F', 'A', '555-0001', 1, 5, 'No', 'Yes',
                 (SELECT id FROM users WHERE email = 'ba1b4f1n0@example.com'))
        ");

        // ====================================================================
        // Seed sample crews (with user links)
        // ====================================================================
        // Link crews to users by fetching user IDs from emails
        $this->execute("
            INSERT INTO crews (key, display_name, first_name, last_name, mobile, skill, membership_number, user_id)
            VALUES
                ('cm0s0f1n0', 'CM0S0F1N0', 'C', 'M0S0F1N0', '613-555-1212', 0, '',
                 (SELECT id FROM users WHERE email = 'cm0s0f1n0@example.com')),
                ('cm0s0f1n1', 'CM0S0F1N1', 'C', 'M0S0F1N1', '613-555-1212', 1, '',
                 (SELECT id FROM users WHERE email = 'cm0s0f1n1@example.com')),
                ('cm0s1f1n0', 'CM0S1F1N0', 'C', 'M0S1F1N0', '613-555-1212', 2, '',
                 (SELECT id FROM users WHERE email = 'cm0s1f1n0@example.com')),
                ('cm0s1f1n1', 'CM0S1F1N1', 'C', 'M0S1F1N1', '613-555-1212', 0, '',
                 (SELECT id FROM users WHERE email = 'cm0s1f1n1@example.com')),
                ('cm0s2f1n0', 'CM0S2F1N0', 'C', 'M0S2F1N0', '613-555-1212', 2, '',
                 (SELECT id FROM users WHERE email = 'cm0s2f1n0@example.com')),
                ('cm0s2f1n1', 'CM0S2F1N1', 'C', 'M0S2F1N1', '613-555-1212', 2, '',
                 (SELECT id FROM users WHERE email = 'cm0s2f1n1@example.com')),
                ('cm1s0f0n0', 'CM1S0F0N0', 'C', 'M1S0F0N0', '613-555-1212', 0, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f0n0@example.com')),
                ('cm1s0f0n1', 'CM1S0F0N1', 'C', 'M1S0F0N1', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f0n1@example.com')),
                ('cm1s0f1n0', 'CM1S0F1N0', 'C', 'M1S0F1N0', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f1n0@example.com')),
                ('cm1s0f1n1', 'CM1S0F1N1', 'C', 'M1S0F1N1', '613-555-1212', 0, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f1n1@example.com')),
                ('cm1s0f1n2', 'CM1S0F1N2', 'C', 'M1S0F1N2', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f1n2@example.com')),
                ('cm1s0f1n3', 'CM1S0F1N3', 'C', 'M1S0F1N3', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s0f1n3@example.com')),
                ('cm1s1f1n0', 'CM1S1F1N0', 'C', 'M1S1F1N0', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n0@example.com')),
                ('cm1s1f1n1', 'CM1S1F1N1', 'C', 'M1S1F1N1', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n1@example.com')),
                ('cm1s1f1n2', 'CM1S1F1N2', 'C', 'M1S1F1N2', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n2@example.com')),
                ('cm1s1f1n3', 'CM1S1F1N3', 'C', 'M1S1F1N3', '613-555-1212', 0, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n3@example.com')),
                ('cm1s1f1n4', 'CM1S1F1N4', 'C', 'M1S1F1N4', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n4@example.com')),
                ('cm1s1f1n5', 'CM1S1F1N5', 'C', 'M1S1F1N5', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s1f1n5@example.com')),
                ('cm1s2f1n0', 'CM1S2F1N0', 'C', 'M1S2F1N0', '613-555-1212', 0, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n0@example.com')),
                ('cm1s2f1n1', 'CM1S2F1N1', 'C', 'M1S2F1N1', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n1@example.com')),
                ('cm1s2f1n2', 'CM1S2F1N2', 'C', 'M1S2F1N2', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n2@example.com')),
                ('cm1s2f1n3', 'CM1S2F1N3', 'C', 'M1S2F1N3', '613-555-1212', 0, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n3@example.com')),
                ('cm1s2f1n4', 'CM1S2F1N4', 'C', 'M1S2F1N4', '613-555-1212', 1, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n4@example.com')),
                ('cm1s2f1n5', 'CM1S2F1N5', 'C', 'M1S2F1N5', '613-555-1212', 2, '111111',
                 (SELECT id FROM users WHERE email = 'cm1s2f1n5@example.com'))
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
