<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for Event endpoints
 */
class EventApiTest extends TestCase
{
    use ApiTestTrait;

    private string $baseUrl = 'http://localhost:8000/api';

    public function testGetAllEvents(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/events");

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('events', $response['body']['data']);
        $this->assertIsArray($response['body']['data']['events']);
    }

    public function testGetEventById(): void
    {
        $eventId = urlencode('Fri May 29');
        $response = $this->makeRequest('GET', "{$this->baseUrl}/events/{$eventId}");

        // May return 404 if event doesn't exist, which is valid
        $this->assertContains($response['status'], [200, 404]);
        $this->assertArrayHasKey('success', $response['body']);
    }

    public function testGetEventByIdIncludesFlotillaStructure(): void
    {
        $eventId = urlencode('Fri May 29');
        $response = $this->makeRequest('GET', "{$this->baseUrl}/events/{$eventId}");

        $this->assertContains($response['status'], [200, 404]);

        if ($response['status'] === 200) {
            $this->assertArrayHasKey('event', $response['body']['data']);

            // Verify flotilla structure if present
            if (isset($response['body']['data']['flotilla'])) {
                $flotilla = $response['body']['data']['flotilla'];

                $this->assertArrayHasKey('eventId', $flotilla);
                $this->assertArrayHasKey('crewedBoats', $flotilla);
                $this->assertArrayHasKey('waitlistBoats', $flotilla);
                $this->assertArrayHasKey('waitlistCrews', $flotilla);
                $this->assertIsArray($flotilla['crewedBoats']);
            }
        }
    }

    public function testGetAllFlotillas(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/flotillas");

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('flotillas', $response['body']['data']);
        $this->assertIsArray($response['body']['data']['flotillas']);

        // Verify basic structure - flotilla array exists
        // Structure may vary depending on implementation
        // Just verify it's an array and doesn't error
    }

    public function testGetEventWithInvalidId(): void
    {
        // Test SQL injection attempt
        $sqlInjection = urlencode("'; DROP TABLE events--");
        $response1 = $this->makeRequest('GET', "{$this->baseUrl}/events/{$sqlInjection}");
        $this->assertContains($response1['status'], [404, 400]);
        $this->assertNotEquals(500, $response1['status']); // Should not cause server error

        // Test XSS attempt
        $xssAttempt = urlencode('<script>alert("xss")</script>');
        $response2 = $this->makeRequest('GET', "{$this->baseUrl}/events/{$xssAttempt}");
        $this->assertContains($response2['status'], [404, 400]);
        $this->assertNotEquals(500, $response2['status']);

        // Test URL-unsafe characters
        $unsafeChars = urlencode('../../../etc/passwd');
        $response3 = $this->makeRequest('GET', "{$this->baseUrl}/events/{$unsafeChars}");
        $this->assertContains($response3['status'], [404, 400]);
        $this->assertNotEquals(500, $response3['status']);

        // Test very long event ID
        $longId = urlencode(str_repeat('A', 1000));
        $response4 = $this->makeRequest('GET', "{$this->baseUrl}/events/{$longId}");
        $this->assertContains($response4['status'], [404, 400]);
        $this->assertNotEquals(500, $response4['status']);

        // Test special characters
        $specialChars = urlencode('!@#$%^&*()[]{}|\\');
        $response5 = $this->makeRequest('GET', "{$this->baseUrl}/events/{$specialChars}");
        $this->assertContains($response5['status'], [404, 400]);
        $this->assertNotEquals(500, $response5['status']);
    }

    public function testNonExistentRouteReturns404(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/nonexistent");

        $this->assertEquals(404, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // ==================== GET /api/status ====================

    public function testGetStatusReturns200(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status");

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testGetStatusRequiresNoAuthentication(): void
    {
        // No Authorization header — must still succeed
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status", null, []);

        $this->assertEquals(200, $response['status']);
    }

    public function testGetStatusResponseContainsIsBlackout(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status");

        $this->assertArrayHasKey('isBlackout', $response['body']['data']);
        $this->assertIsBool($response['body']['data']['isBlackout']);
    }

    public function testGetStatusResponseContainsCurrentDate(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status");

        $data = $response['body']['data'];
        $this->assertArrayHasKey('currentDate', $data);
        // Should be a valid YYYY-MM-DD date
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $data['currentDate']
        );
    }

    public function testGetStatusResponseContainsCurrentTime(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status");

        $data = $response['body']['data'];
        $this->assertArrayHasKey('currentTime', $data);
        // Should be a valid HH:MM:SS time
        $this->assertMatchesRegularExpression(
            '/^\d{2}:\d{2}:\d{2}$/',
            $data['currentTime']
        );
    }

    public function testGetStatusResponseContainsTimeSource(): void
    {
        $response = $this->makeRequest('GET', "{$this->baseUrl}/status");

        $data = $response['body']['data'];
        $this->assertArrayHasKey('timeSource', $data);
        $this->assertContains($data['timeSource'], ['production', 'simulated']);
    }
}
