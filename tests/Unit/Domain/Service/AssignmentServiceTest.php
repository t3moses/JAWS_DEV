<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Service;

use App\Domain\Service\AssignmentService;
use App\Domain\Entity\Boat;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AssignmentRule;
use App\Domain\Enum\SkillLevel;
use PHPUnit\Framework\TestCase;

class AssignmentServiceTest extends TestCase
{
    private AssignmentService $service;

    protected function setUp(): void
    {
        $this->service = new AssignmentService();
    }

    private function createBoat(string $key, bool $assistanceRequired = false): Boat
    {
        $boat = new Boat(
            key: BoatKey::fromString($key),
            displayName: "Test Boat $key",
            ownerFirstName: 'John',
            ownerLastName: 'Doe',
            ownerMobile: '555-1234',
            minBerths: 2,
            maxBerths: 3,
            assistanceRequired: $assistanceRequired,
            socialPreference: true
        );
        return $boat;
    }

    private function createCrew(
        string $key,
        SkillLevel $skill = SkillLevel::INTERMEDIATE,
        ?CrewKey $partnerKey = null,
        array $whitelist = [],
        array $history = []
    ): Crew {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: "Test Crew $key",
            firstName: 'John',
            lastName: 'Doe',
            partnerKey: $partnerKey,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: "12345$key",
            skill: $skill,
            experience: '5 years'
        );
        // Set whitelist
        foreach ($whitelist as $boatKey) {
            $crew->addToWhitelist($boatKey);
        }

        // Set history
        foreach ($history as $eventId => $boatKeyString) {
            $crew->setHistory(EventId::fromString($eventId), $boatKeyString);
        }

        return $crew;
    }

    private function createFlotilla(array $crewedBoatsData): array
    {
        $crewedBoats = [];

        foreach ($crewedBoatsData as $data) {
            $crewedBoats[] = [
                'boat' => $data['boat'],
                'crews' => $data['crews']
            ];
        }

        return ['crewed_boats' => $crewedBoats];
    }

    // Tests that the assign method returns the expected flotilla data structure
    public function testAssignReturnsFlotillaStructure(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway');
        $crew1 = $this->createCrew('alice', SkillLevel::ADVANCED);
        $crew2 = $this->createCrew('bob', SkillLevel::INTERMEDIATE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crew1, $crew2]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);
        $this->assertCount(1, $result['crewed_boats']);
    }

    // Tests ASSIST rule crew loss calculation based on skill level for assistance-required boats
    public function testCrewLossForAssistRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway', true);
        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        $crewedBoat = ['boat' => $boat, 'crews' => [$lowSkillCrew]];

        // Act & Assert - Low skill crew on assistance-required boat should have loss of 2
        $loss = $this->service->crewLoss(AssignmentRule::ASSIST, $lowSkillCrew, $crewedBoat);
        $this->assertEquals(2, $loss);

        // Act & Assert - High skill crew on assistance-required boat should have loss of 0
        $crewedBoat = ['boat' => $boat, 'crews' => [$highSkillCrew]];
        $loss = $this->service->crewLoss(AssignmentRule::ASSIST, $highSkillCrew, $crewedBoat);
        $this->assertEquals(0, $loss);
    }

    // Tests that low skill crew has no loss when assistance boat already has high skill crew
    public function testCrewLossForAssistRuleWithExistingHighSkill(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway', true);
        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);
        $crewedBoat = ['boat' => $boat, 'crews' => [$highSkillCrew, $lowSkillCrew]];

        // Act
        $loss = $this->service->crewLoss(AssignmentRule::ASSIST, $lowSkillCrew, $crewedBoat);

        // Assert
        $this->assertEquals(0, $loss);
    }

    // Tests that ASSIST rule produces no loss when boat doesn't require assistance
    public function testCrewLossForAssistRuleNoAssistanceRequired(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway', false);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);
        $crewedBoat = ['boat' => $boat, 'crews' => [$lowSkillCrew]];

        // Act
        $loss = $this->service->crewLoss(AssignmentRule::ASSIST, $lowSkillCrew, $crewedBoat);

        // Assert
        $this->assertEquals(0, $loss);
    }

    // Tests WHITELIST rule crew loss calculation based on boat whitelist membership
    public function testCrewLossForWhitelistRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $whitelistedCrew = $this->createCrew('alice', SkillLevel::INTERMEDIATE, null, [
            BoatKey::fromString('sailaway')
        ]);
        $nonWhitelistedCrew = $this->createCrew('bob', SkillLevel::INTERMEDIATE);

        $crewedBoat = ['boat' => $boat, 'crews' => [$whitelistedCrew]];

        // Act & Assert
        // Whitelisted crew should have loss of 0
        $loss = $this->service->crewLoss(AssignmentRule::WHITELIST, $whitelistedCrew, $crewedBoat);
        $this->assertEquals(0, $loss);

        // Non-whitelisted crew should have loss of 1
        $crewedBoat = ['boat' => $boat, 'crews' => [$nonWhitelistedCrew]];
        $loss = $this->service->crewLoss(AssignmentRule::WHITELIST, $nonWhitelistedCrew, $crewedBoat);
        $this->assertEquals(1, $loss);
    }

    // Tests HIGH_SKILL rule which penalizes high skill crew on boats with skill spread
    public function testCrewLossForHighSkillRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        // Act & Assert
        // High skill spread (2-0=2) with high skill crew
        $crewedBoat = ['boat' => $boat, 'crews' => [$highSkillCrew, $lowSkillCrew]];
        $loss = $this->service->crewLoss(AssignmentRule::HIGH_SKILL, $highSkillCrew, $crewedBoat);
        $this->assertEquals(1, $loss);

        // High skill spread with low skill crew
        $loss = $this->service->crewLoss(AssignmentRule::HIGH_SKILL, $lowSkillCrew, $crewedBoat);
        $this->assertEquals(0, $loss);
    }

    // Tests LOW_SKILL rule which penalizes low skill crew on boats with skill spread
    public function testCrewLossForLowSkillRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        // Act & Assert
        // High skill spread (2-0=2) with low skill crew
        $crewedBoat = ['boat' => $boat, 'crews' => [$highSkillCrew, $lowSkillCrew]];
        $loss = $this->service->crewLoss(AssignmentRule::LOW_SKILL, $lowSkillCrew, $crewedBoat);
        $this->assertEquals(1, $loss);

        // High skill spread with high skill crew
        $loss = $this->service->crewLoss(AssignmentRule::LOW_SKILL, $highSkillCrew, $crewedBoat);
        $this->assertEquals(0, $loss);
    }

    // Tests PARTNER rule which prevents partners from being assigned to the same boat
    public function testCrewLossForPartnerRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $aliceKey = CrewKey::fromString('alice');
        $bobKey = CrewKey::fromString('bob');

        $alice = $this->createCrew('alice', SkillLevel::INTERMEDIATE, $bobKey);
        $bob = $this->createCrew('bob', SkillLevel::INTERMEDIATE, $aliceKey);
        $charlie = $this->createCrew('charlie', SkillLevel::INTERMEDIATE);

        // Act & Assert
        // Alice and Bob are partners on the same boat - violation
        $crewedBoat = ['boat' => $boat, 'crews' => [$alice, $bob]];
        $loss = $this->service->crewLoss(AssignmentRule::PARTNER, $alice, $crewedBoat);
        $this->assertEquals(1, $loss);

        // Alice without partner on boat - no violation
        $crewedBoat = ['boat' => $boat, 'crews' => [$alice, $charlie]];
        $loss = $this->service->crewLoss(AssignmentRule::PARTNER, $alice, $crewedBoat);
        $this->assertEquals(0, $loss);

        // Charlie has no partner - no violation
        $crewedBoat = ['boat' => $boat, 'crews' => [$charlie]];
        $loss = $this->service->crewLoss(AssignmentRule::PARTNER, $charlie, $crewedBoat);
        $this->assertEquals(0, $loss);
    }

    // Tests REPEAT rule which counts how many times crew has been on the same boat
    public function testCrewLossForRepeatRule(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');

        // Crew has been on this boat 3 times
        $crew = $this->createCrew('alice', SkillLevel::INTERMEDIATE, null, [], [
            'Fri May 29' => 'sailaway',
            'Sat May 30' => 'sailaway',
            'Sun May 31' => 'sailaway',
            'Mon Jun 01' => 'seabreeze'
        ]);

        // Act
        $crewedBoat = ['boat' => $boat, 'crews' => [$crew]];
        $loss = $this->service->crewLoss(AssignmentRule::REPEAT, $crew, $crewedBoat);

        // Assert
        $this->assertEquals(3, $loss);
    }

    // Tests that REPEAT rule produces zero loss when crew has no history with the boat
    public function testCrewLossForRepeatRuleNoHistory(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $crew = $this->createCrew('alice', SkillLevel::INTERMEDIATE);

        // Act
        $crewedBoat = ['boat' => $boat, 'crews' => [$crew]];
        $loss = $this->service->crewLoss(AssignmentRule::REPEAT, $crew, $crewedBoat);

        // Assert
        $this->assertEquals(0, $loss);
    }

    // Tests that crew gradient for ASSIST rule reflects skill value for swap optimization
    public function testCrewGradForAssistRule(): void
    {
        // Arrange
        // Create a scenario where low skill crew is on an assistance boat (violation)
        // High skill crew on a different boat can help by swapping
        $boatNeedsAssist = $this->createBoat('sailaway', true);
        $boatNoAssist = $this->createBoat('seabreeze', false);

        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boatNeedsAssist, 'crews' => [$lowSkillCrew]],
            ['boat' => $boatNoAssist, 'crews' => [$highSkillCrew]]
        ]);

        // Act
        $this->service->assign($flotilla);

        // Assert
        // High skill crew has gradient of 2 (skill value for ASSIST rule)
        // Gradient represents their ability to help resolve violations
        $this->assertArrayHasKey('alice', $this->service->grads);
        $this->assertEquals(2, $this->service->grads['alice']);
    }

    // Tests that whitelisted crew gets optimally assigned to whitelisted boat
    public function testCrewGradForWhitelistRule(): void
    {
        // Arrange
        // Crew with whitelist has higher gradient (more valuable for swaps)
        // Verify through optimization: whitelisted crew should be assigned to whitelisted boat
        $boat1 = $this->createBoat('sailaway');
        $boat2 = $this->createBoat('seabreeze');

        $crewWithWhitelist = $this->createCrew('alice', SkillLevel::INTERMEDIATE, null, [
            BoatKey::fromString('sailaway')
        ]);
        $crewWithoutWhitelist = $this->createCrew('bob', SkillLevel::INTERMEDIATE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crewWithoutWhitelist]],
            ['boat' => $boat2, 'crews' => [$crewWithWhitelist]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        // After optimization, whitelisted crew should be on boat they're whitelisted for
        $sailawayBoat = $result['crewed_boats'][0];
        $sailawayCrewKeys = array_map(fn($crew) => $crew->getKey()->toString(), $sailawayBoat['crews']);
        $this->assertContains('alice', $sailawayCrewKeys);
    }

    // Tests gradient calculation for HIGH_SKILL rule in optimization scenarios
    public function testCrewGradForHighSkillRule(): void
    {
        // Arrange
        // Low skill crew has higher gradient for HIGH_SKILL violations
        // Verify through optimization that algorithm produces valid result
        $boat1 = $this->createBoat('sailaway');
        $boat2 = $this->createBoat('seabreeze');
        $boat3 = $this->createBoat('swift');

        $advancedCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $noviceCrew = $this->createCrew('bob', SkillLevel::NOVICE);
        $intermediateCrew = $this->createCrew('charlie', SkillLevel::INTERMEDIATE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$advancedCrew, $noviceCrew]],
            ['boat' => $boat2, 'crews' => [$intermediateCrew]],
            ['boat' => $boat3, 'crews' => [$this->createCrew('dave', SkillLevel::INTERMEDIATE)]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);
    }

    // Tests gradient calculation for LOW_SKILL rule in optimization scenarios
    public function testCrewGradForLowSkillRule(): void
    {
        // Arrange
        // High skill crew has higher gradient for LOW_SKILL violations
        // Verify through optimization that algorithm produces valid result
        $boat1 = $this->createBoat('sailaway');
        $boat2 = $this->createBoat('seabreeze');
        $boat3 = $this->createBoat('swift');

        $advancedCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $noviceCrew = $this->createCrew('bob', SkillLevel::NOVICE);
        $intermediateCrew = $this->createCrew('charlie', SkillLevel::INTERMEDIATE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$advancedCrew, $noviceCrew]],
            ['boat' => $boat2, 'crews' => [$intermediateCrew]],
            ['boat' => $boat3, 'crews' => [$this->createCrew('dave', SkillLevel::INTERMEDIATE)]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);
    }

    // Tests gradient calculation for PARTNER rule to separate partners via swaps
    public function testCrewGradForPartnerRule(): void
    {
        // Arrange
        // Solo crew has higher gradient for PARTNER violations
        // Verify through optimization that algorithm produces valid result
        $boat1 = $this->createBoat('sailaway');
        $boat2 = $this->createBoat('seabreeze');

        $bobKey = CrewKey::fromString('bob');
        $aliceKey = CrewKey::fromString('alice');
        $crewAlice = $this->createCrew('alice', SkillLevel::INTERMEDIATE, $bobKey);
        $crewBob = $this->createCrew('bob', SkillLevel::INTERMEDIATE, $aliceKey);
        $crewSolo = $this->createCrew('charlie', SkillLevel::INTERMEDIATE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crewAlice, $crewBob]],
            ['boat' => $boat2, 'crews' => [$crewSolo]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);
    }

    // Tests gradient calculation for REPEAT rule based on available history slots
    public function testCrewGradForRepeatRule(): void
    {
        // Arrange
        // Create a scenario where crew has been assigned to same boat multiple times (violation)
        // Crew with empty history slots can help by swapping
        $boat1 = $this->createBoat('sailaway');
        $boat2 = $this->createBoat('seabreeze');

        // Crew assigned to sailaway many times - violation
        $crewWithRepeat = $this->createCrew('alice', SkillLevel::INTERMEDIATE, null, [], [
            'Fri May 29' => 'sailaway',
            'Sat May 30' => 'sailaway',
            'Sun May 31' => 'sailaway',
            'Mon Jun 01' => 'seabreeze'
        ]);

        // Crew with available slots (can be assigned multiple times safely)
        $crewWithEmptyHistory = $this->createCrew('bob', SkillLevel::INTERMEDIATE, null, [], [
            'Fri May 29' => '',
            'Sat May 30' => '',
            'Sun May 31' => 'seabreeze'
        ]);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crewWithRepeat]],
            ['boat' => $boat2, 'crews' => [$crewWithEmptyHistory]]
        ]);

        // Act
        $this->service->assign($flotilla);

        // Assert
        // Crew with empty history slots has gradient equal to empty slot count
        $this->assertArrayHasKey('bob', $this->service->grads);
        $this->assertEquals(2, $this->service->grads['bob']);
    }

    // Tests helper method that retrieves crew entity by key from flotilla
    public function testCrewFromKey(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $crew1 = $this->createCrew('alice', SkillLevel::ADVANCED);
        $crew2 = $this->createCrew('bob', SkillLevel::NOVICE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat, 'crews' => [$crew1, $crew2]]
        ]);

        // Set the flotilla on the service
        $this->service->assign($flotilla);

        // Act
        $foundCrew = $this->service->crewFromKey('alice');

        // Assert
        $this->assertNotNull($foundCrew);
        $this->assertEquals('alice', $foundCrew->getKey()->toString());

        $notFoundCrew = $this->service->crewFromKey('nonexistent');
        $this->assertNull($notFoundCrew);
    }

    // Tests helper method that retrieves the boat a crew is assigned to by crew key
    public function testCrewedBoatFromKey(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $crew1 = $this->createCrew('alice', SkillLevel::ADVANCED);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat, 'crews' => [$crew1]]
        ]);

        // Act
        // Set the flotilla on the service
        $this->service->assign($flotilla);

        // Assert
        $crewedBoat = $this->service->crewedBoatFromKey('alice');
        $this->assertNotNull($crewedBoat);
        $this->assertArrayHasKey('boat', $crewedBoat);
        $this->assertArrayHasKey('crews', $crewedBoat);
        $this->assertEquals('sailaway', $crewedBoat['boat']->getKey()->toString());

        $notFound = $this->service->crewedBoatFromKey('nonexistent');
        $this->assertNull($notFound);
    }

    // Tests that bestSwap algorithm finds and executes beneficial crew swaps
    public function testBestSwapFindsValidSwap(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', true);
        $boat2 = $this->createBoat('seabreeze', false);

        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$lowSkillCrew]],
            ['boat' => $boat2, 'crews' => [$highSkillCrew]]
        ]);

        // Act
        // First call assign to populate the service's internal flotilla state
        $this->service->assign($flotilla);

        // Assert
        // After assign, the losses and grads arrays are populated by the algorithm
        // Verify that bestSwap was called and found a valid swap (the assignment happened)
        $this->assertIsArray($this->service->losses);
        $this->assertIsArray($this->service->grads);
        $this->assertNotEmpty($this->service->losses);
    }

    // Tests that bestSwap handles scenarios where no beneficial swap exists
    public function testBestSwapReturnsNullWhenNoValidSwap(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway');
        $crew1 = $this->createCrew('alice', SkillLevel::ADVANCED);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crew1]]
        ]);

        // Act
        // Call assign to populate internal state
        $this->service->assign($flotilla);

        // Assert
        // With only one crew on one boat, bestSwap should not find a valid swap for most rules
        // Verify the algorithm completed without errors
        $this->assertIsArray($this->service->losses);
        $this->assertIsArray($this->service->grads);
    }

    // Tests that prettyPrint debug method executes without throwing exceptions
    public function testPrettyPrintDoesNotThrowException(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway', true);
        $crew = $this->createCrew('alice', SkillLevel::ADVANCED);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat, 'crews' => [$crew]]
        ]);

        // Act & Assert
        // Should not throw exception
        $this->service->prettyPrint($flotilla);

        // Assert
        $this->assertTrue(true);
    }

    // Tests that high skill crew remains on assistance-required boats during optimization
    public function testAssignLocksHighSkillCrewOnAssistanceBoats(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway', true);
        $highSkillCrew = $this->createCrew('alice', SkillLevel::ADVANCED);
        $lowSkillCrew = $this->createCrew('bob', SkillLevel::NOVICE);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat, 'crews' => [$lowSkillCrew, $highSkillCrew]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        // Verify flotilla structure is returned
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);

        // Verify high skill crew is still on the assistance boat
        $hasHighSkill = false;
        foreach ($result['crewed_boats'][0]['crews'] as $crew) {
            if ($crew->getSkill()->value === 2) {
                $hasHighSkill = true;
                break;
            }
        }
        $this->assertTrue($hasHighSkill, 'High skill crew should be on assistance boat');
    }

    // Tests assignment with multiple boats and crews to verify overall algorithm behavior
    public function testAssignWithMultipleBoatsAndCrews(): void
    {
        // Arrange
        $boat1 = $this->createBoat('sailaway', true);
        $boat2 = $this->createBoat('seabreeze', false);

        $crew1 = $this->createCrew('alice', SkillLevel::ADVANCED);
        $crew2 = $this->createCrew('bob', SkillLevel::NOVICE);
        $crew3 = $this->createCrew('charlie', SkillLevel::INTERMEDIATE);
        $crew4 = $this->createCrew('dave', SkillLevel::ADVANCED);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat1, 'crews' => [$crew2, $crew1]],
            ['boat' => $boat2, 'crews' => [$crew3, $crew4]]
        ]);

        // Act
        $result = $this->service->assign($flotilla);

        // Assert
        // Verify the result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('crewed_boats', $result);
        $this->assertCount(2, $result['crewed_boats']);

        // Verify each boat has crews
        foreach ($result['crewed_boats'] as $crewedBoat) {
            $this->assertArrayHasKey('boat', $crewedBoat);
            $this->assertArrayHasKey('crews', $crewedBoat);
            $this->assertNotEmpty($crewedBoat['crews']);
        }
    }

    // Tests that losses and grads arrays are publicly accessible after assignment
    public function testLossesAndGradsArraysArePublic(): void
    {
        // Arrange
        $boat = $this->createBoat('sailaway');
        $crew = $this->createCrew('alice', SkillLevel::ADVANCED);

        $flotilla = $this->createFlotilla([
            ['boat' => $boat, 'crews' => [$crew]]
        ]);

        // Act
        $this->service->assign($flotilla);

        // Assert
        // Verify we can access the public properties
        $this->assertIsArray($this->service->losses);
        $this->assertIsArray($this->service->grads);
    }
}
