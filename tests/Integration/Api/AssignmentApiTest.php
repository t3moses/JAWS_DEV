<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for Assignment endpoints
 */
class AssignmentApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testGetUserAssignments(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('GET', "{$this->baseUrl}/assignments", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if no assignments exist, which is acceptable
        $this->assertContains($response['status'], [200, 404]);
        $this->assertArrayHasKey('success', $response['body']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetUserAssignmentsWithData(): void
    {
        // Create a boat owner and crew
        $boatData = $this->createTestBoatOwner($this->baseUrl);
        $crewData = $this->createTestCrew($this->baseUrl);

        // Set availability for both (using same event)
        $eventId = 'Fri May 29';

        $boatAvailResponse = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => $eventId, 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$boatData['token']}",
        ]);

        $crewAvailResponse = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => $eventId, 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$crewData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($boatAvailResponse['status'], [200, 403, 404]);
        $this->assertContains($crewAvailResponse['status'], [200, 403, 404]);

        // If availability was set successfully, check assignments
        if ($boatAvailResponse['status'] === 200 && $crewAvailResponse['status'] === 200) {
            // Get boat owner's assignments
            $boatAssignResponse = $this->makeRequest('GET', "{$this->baseUrl}/assignments", null, [
                "Authorization: Bearer {$boatData['token']}",
            ]);

            // Get crew's assignments
            $crewAssignResponse = $this->makeRequest('GET', "{$this->baseUrl}/assignments", null, [
                "Authorization: Bearer {$crewData['token']}",
            ]);

            // Both should return 200 or 404 (may not be assigned yet)
            $this->assertContains($boatAssignResponse['status'], [200, 404]);
            $this->assertContains($crewAssignResponse['status'], [200, 404]);

            // If we have assignments, verify structure
            if ($boatAssignResponse['status'] === 200 && isset($boatAssignResponse['body']['data']['assignments'])) {
                $assignments = $boatAssignResponse['body']['data']['assignments'];
                $this->assertIsArray($assignments);

                foreach ($assignments as $assignment) {
                    // Verify assignment structure matches AssignmentResponse DTO
                    $this->assertArrayHasKey('eventId', $assignment);
                    $this->assertArrayHasKey('eventDate', $assignment);
                    $this->assertArrayHasKey('startTime', $assignment);
                    $this->assertArrayHasKey('finishTime', $assignment);
                    $this->assertArrayHasKey('availabilityStatus', $assignment);
                    $this->assertArrayHasKey('boatName', $assignment);
                    $this->assertArrayHasKey('boatKey', $assignment);
                    $this->assertArrayHasKey('crewmates', $assignment);
                }
            }

            if ($crewAssignResponse['status'] === 200 && isset($crewAssignResponse['body']['data']['assignments'])) {
                $assignments = $crewAssignResponse['body']['data']['assignments'];
                $this->assertIsArray($assignments);

                // Verify assignments array exists and is valid
                if (!empty($assignments)) {
                    foreach ($assignments as $assignment) {
                        // Verify assignment structure matches AssignmentResponse DTO
                        $this->assertArrayHasKey('eventId', $assignment);
                        $this->assertArrayHasKey('eventDate', $assignment);
                        $this->assertArrayHasKey('availabilityStatus', $assignment);
                        $this->assertArrayHasKey('boatName', $assignment);
                        $this->assertArrayHasKey('crewmates', $assignment);
                    }
                }
            }
        }

        // Cleanup both users
        $this->cleanupTestUser($boatData['userId']);
        $this->cleanupTestUser($crewData['userId']);
    }
}
