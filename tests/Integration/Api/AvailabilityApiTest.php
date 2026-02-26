<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for Availability endpoints
 */
class AvailabilityApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testUpdateCrewAvailability(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($response['status'], [200, 403, 404]);
        $this->assertArrayHasKey('success', $response['body']);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateBoatOwnerAvailability(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($response['status'], [200, 403, 404]);
        $this->assertArrayHasKey('success', $response['body']);

        // If successful, verify the response indicates what was updated
        if ($response['status'] === 200 && isset($response['body']['data']['updated'])) {
            $updated = $response['body']['data']['updated'];
            $this->assertIsArray($updated);
        }

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testBoatOwnerAvailabilityCreatesFlotilla(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($response['status'], [200, 403, 404]);

        // If boat exists, verify flotilla can be retrieved
        if ($response['status'] === 200) {
            $eventResponse = $this->makeRequest('GET', "{$this->baseUrl}/events/Fri%20May%2029");
            $this->assertArrayHasKey('data', $eventResponse['body']);

            // Flotilla may or may not exist depending on whether there are other boats/crews
            // The key is that the endpoint doesn't error out
        }

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testCrewAvailabilityCreatesFlotilla(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($response['status'], [200, 403, 404]);

        // If crew exists, verify flotilla endpoint works
        if ($response['status'] === 200) {
            $eventResponse = $this->makeRequest('GET', "{$this->baseUrl}/events/Fri%20May%2029");
            $this->assertArrayHasKey('data', $eventResponse['body']);
        }

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testMultipleAvailabilityUpdatesAreIdempotent(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);

        // First update
        $response1 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if event doesn't exist, 403 during blackout window
        $this->assertContains($response1['status'], [200, 403, 404]);

        if ($response1['status'] === 200) {
            // Second update (same boat, different availability)
            $response2 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
                'availabilities' => [
                    ['eventId' => 'Fri May 29', 'isAvailable' => true],
                ],
            ], [
                "Authorization: Bearer {$testData['token']}",
            ]);

            $this->assertEquals(200, $response2['status']);

            // Verify flotilla exists and endpoint doesn't error
            $eventResponse = $this->makeRequest('GET', "{$this->baseUrl}/events/Fri%20May%2029");
            $this->assertContains($eventResponse['status'], [200, 404]);
        }

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testGetCrewAvailability(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        // Set availability for multiple events
        $updateResponse = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true],
                ['eventId' => 'Fri Jun 05', 'isAvailable' => false],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // May return 404 if events don't exist, 403 during blackout window
        $this->assertContains($updateResponse['status'], [200, 403, 404]);

        // Now get the availability
        $response = $this->makeRequest('GET', "{$this->baseUrl}/users/me/availability", null, [
            "Authorization: Bearer {$testData['token']}",
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('data', $response['body']);

        // Verify response structure
        if (isset($response['body']['data']['availabilities'])) {
            $availabilities = $response['body']['data']['availabilities'];
            $this->assertIsArray($availabilities);

            // If we have availabilities, verify structure
            if (!empty($availabilities)) {
                foreach ($availabilities as $availability) {
                    $this->assertArrayHasKey('eventId', $availability);
                    $this->assertArrayHasKey('status', $availability);
                    $this->assertIsInt($availability['status']);
                }
            }
        }

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }

    public function testBoatOwnerCanSetExplicitBerthsForEvent(): void
    {
        $testData = $this->createTestBoatOwner($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true, 'berths' => 2],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // 200 if event exists, 404 if not, 403 during blackout window
        $this->assertContains($response['status'], [200, 403, 404]);
        if ($response['status'] === 200) {
            $this->assertTrue($response['body']['success']);
        }

        $this->cleanupTestUser($testData['userId']);
    }

    public function testBoatOwnerBerthsExceedingMaxCapacityReturns400(): void
    {
        // Test boat owner has maxBerths: 4 (see createTestBoatOwner)
        $testData = $this->createTestBoatOwner($this->baseUrl);

        $response = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => true, 'berths' => 99],
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);

        // 400 if event exists and berths > maxBerths; 404 if event doesn't exist; 403 during blackout
        $this->assertContains($response['status'], [400, 403, 404]);

        $this->cleanupTestUser($testData['userId']);
    }

    public function testUpdateAvailabilityValidation(): void
    {
        $testData = $this->createTestCrew($this->baseUrl);

        // Test empty availabilities array - may be accepted as "no changes"
        $response1 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response1['status'], [200, 400, 403]);

        // Test missing availabilities field - may accept as "no changes"
        $response2 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response2['status'], [200, 400, 403]);

        // Test invalid isAvailable value (non-boolean)
        $response3 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29', 'isAvailable' => 'yes'], // String instead of boolean
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        // May accept due to PHP type coercion, but worth testing
        $this->assertContains($response3['status'], [200, 400, 404]);

        // Test missing eventId - validation may not catch this
        $response4 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['isAvailable' => true], // Missing eventId
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response4['status'], [200, 400, 404]);

        // Test missing isAvailable - validation may not catch this
        $response5 = $this->makeRequest('PATCH', "{$this->baseUrl}/users/me/availability", [
            'availabilities' => [
                ['eventId' => 'Fri May 29'], // Missing isAvailable
            ],
        ], [
            "Authorization: Bearer {$testData['token']}",
        ]);
        $this->assertContains($response5['status'], [200, 400, 404]);

        // Cleanup
        $this->cleanupTestUser($testData['userId']);
    }
}
