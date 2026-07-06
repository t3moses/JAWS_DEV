<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection;

use App\Domain\Collection\Squad;
use App\Domain\Entity\Crew;
use App\Domain\ValueObject\CrewKey;
use App\Domain\ValueObject\EventId;
use App\Domain\Enum\AvailabilityStatus;
use App\Domain\Enum\SkillLevel;
use PHPUnit\Framework\TestCase;

class SquadTest extends TestCase
{
    private function createCrew(string $key, string $firstName, string $lastName): Crew
    {
        $crew = new Crew(
            key: CrewKey::fromString($key),
            displayName: "$firstName $lastName",
            firstName: $firstName,
            lastName: $lastName,
            partnerKey: null,
            mobile: '555-1234',
            socialPreference: true,
            membershipNumber: '12345',
            skill: SkillLevel::INTERMEDIATE,
            experience: '5 years'
        );
        return $crew;
    }

    public function testAddCrewToSquad(): void
    {
        // Arrange
        $squad = new Squad();
        $crew = $this->createCrew('johndoe', 'John', 'Doe');

        // Act
        $squad->add($crew);

        // Assert
        $this->assertEquals(1, $squad->count());
        $this->assertTrue($squad->has(CrewKey::fromString('johndoe')));
    }

    public function testAddMultipleCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');

        // Act
        $squad->add($crew1);
        $squad->add($crew2);

        // Assert
        $this->assertEquals(2, $squad->count());
    }

    public function testAddOverwritesExistingCrew(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew2 = $this->createCrew('johndoe', 'Johnny', 'Doe');

        // Act
        $squad->add($crew1);
        $squad->add($crew2);

        // Assert
        $this->assertEquals(1, $squad->count());
        $retrieved = $squad->get(CrewKey::fromString('johndoe'));
        $this->assertEquals('Johnny', $retrieved->getFirstName());
    }

    public function testRemoveCrew(): void
    {
        // Arrange
        $squad = new Squad();
        $crew = $this->createCrew('johndoe', 'John', 'Doe');

        $squad->add($crew);

        // Act
        $squad->remove(CrewKey::fromString('johndoe'));

        // Assert
        $this->assertEquals(0, $squad->count());
        $this->assertFalse($squad->has(CrewKey::fromString('johndoe')));
    }

    public function testRemoveNonExistentCrewDoesNotThrow(): void
    {
        // Arrange
        $squad = new Squad();

        // Act
        $squad->remove(CrewKey::fromString('nonexistent'));

        // Assert
        $this->assertEquals(0, $squad->count());
    }

    public function testGetCrew(): void
    {
        // Arrange
        $squad = new Squad();
        $crew = $this->createCrew('johndoe', 'John', 'Doe');

        $squad->add($crew);

        // Act
        $retrieved = $squad->get(CrewKey::fromString('johndoe'));

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('johndoe', $retrieved->getKey()->toString());
    }

    public function testGetNonExistentCrewReturnsNull(): void
    {
        // Arrange
        $squad = new Squad();

        // Act
        $retrieved = $squad->get(CrewKey::fromString('nonexistent'));

        // Assert
        $this->assertNull($retrieved);
    }

    public function testHasReturnsTrueWhenCrewExists(): void
    {
        // Arrange
        $squad = new Squad();
        $crew = $this->createCrew('johndoe', 'John', 'Doe');

        $squad->add($crew);

        // Assert
        $this->assertTrue($squad->has(CrewKey::fromString('johndoe')));
    }

    public function testHasReturnsFalseWhenCrewDoesNotExist(): void
    {
        // Arrange
        $squad = new Squad();

        // Assert
        $this->assertFalse($squad->has(CrewKey::fromString('nonexistent')));
    }

    public function testAllReturnsAllCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');

        $squad->add($crew1);
        $squad->add($crew2);

        // Act
        $crews = $squad->all();

        // Assert
        $this->assertCount(2, $crews);
        $this->assertContains($crew1, $crews);
        $this->assertContains($crew2, $crews);
    }

    public function testAllReturnsIndexedArray(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');

        $squad->add($crew1);
        $squad->add($crew2);

        // Act
        $crews = $squad->all();

        // Check that keys are 0, 1, 2, etc. (not associative)
        // Assert
        $this->assertEquals([0, 1], array_keys($crews));
    }

    public function testGetAvailableForReturnsOnlyAvailableCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $eventId = EventId::fromString('Fri May 29');

        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew1->setAvailability($eventId, AvailabilityStatus::NOT_SELECTED);

        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');
        $crew2->setAvailability($eventId, AvailabilityStatus::NOT_SELECTED);

        $crew3 = $this->createCrew('bobsmith', 'Bob', 'Smith');
        $crew3->setAvailability($eventId, AvailabilityStatus::SELECTED);

        $crew4 = $this->createCrew('alicesmith', 'Alice', 'Smith');
        // crew4 has no availability record

        $squad->add($crew1);
        $squad->add($crew2);
        $squad->add($crew3);
        $squad->add($crew4);

        // Act
        $available = $squad->getAvailableFor($eventId);

        // Assert — only crews with availability records (crew1, crew2, crew3) should be available
        $this->assertCount(3, $available);
        $this->assertContains($crew1, $available);
        $this->assertContains($crew2, $available);
        $this->assertContains($crew3, $available);
        $this->assertNotContains($crew4, $available);
    }

    public function testGetAvailableForReturnsEmptyArrayWhenNoneAvailable(): void
    {
        // Arrange
        $squad = new Squad();
        $eventId = EventId::fromString('Fri May 29');

        $crew = $this->createCrew('johndoe', 'John', 'Doe');
        $squad->add($crew);

        // Act
        $available = $squad->getAvailableFor($eventId);

        // Assert
        $this->assertEmpty($available);
    }

    public function testGetAssignedToReturnsOnlyAssignedCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $eventId = EventId::fromString('Fri May 29');

        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew1->setAvailability($eventId, AvailabilityStatus::NOT_SELECTED);

        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');
        $crew2->setAvailability($eventId, AvailabilityStatus::SELECTED);

        $crew3 = $this->createCrew('bobsmith', 'Bob', 'Smith');
        $crew3->setAvailability($eventId, AvailabilityStatus::SELECTED);

        $squad->add($crew1);
        $squad->add($crew2);
        $squad->add($crew3);

        // Act
        $assigned = $squad->getAssignedTo($eventId);

        // Assert
        $this->assertCount(2, $assigned);
        $this->assertContains($crew2, $assigned);
        $this->assertContains($crew3, $assigned);
        $this->assertNotContains($crew1, $assigned);
    }

    public function testGetAssignedToReturnsEmptyArrayWhenNoneAssigned(): void
    {
        // Arrange
        $squad = new Squad();
        $eventId = EventId::fromString('Fri May 29');

        $crew = $this->createCrew('johndoe', 'John', 'Doe');
        $crew->setAvailability($eventId, AvailabilityStatus::NOT_SELECTED);
        $squad->add($crew);

        // Act
        $assigned = $squad->getAssignedTo($eventId);

        // Assert
        $this->assertEmpty($assigned);
    }

    public function testCountReturnsZeroForEmptySquad(): void
    {
        // Arrange
        $squad = new Squad();

        // Assert
        $this->assertEquals(0, $squad->count());
    }

    public function testCountReturnsCorrectNumber(): void
    {
        // Arrange
        $squad = new Squad();
        $squad->add($this->createCrew('johndoe', 'John', 'Doe'));
        $squad->add($this->createCrew('janedoe', 'Jane', 'Doe'));
        $squad->add($this->createCrew('bobsmith', 'Bob', 'Smith'));

        // Assert
        $this->assertEquals(3, $squad->count());
    }

    public function testIsEmptyReturnsTrueForNewSquad(): void
    {
        // Arrange
        $squad = new Squad();

        // Assert
        $this->assertTrue($squad->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenCrewsExist(): void
    {
        // Arrange
        $squad = new Squad();
        $squad->add($this->createCrew('johndoe', 'John', 'Doe'));

        // Assert
        $this->assertFalse($squad->isEmpty());
    }

    public function testClearRemovesAllCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $squad->add($this->createCrew('johndoe', 'John', 'Doe'));
        $squad->add($this->createCrew('janedoe', 'Jane', 'Doe'));

        // Act
        $squad->clear();

        // Assert
        $this->assertTrue($squad->isEmpty());
        $this->assertEquals(0, $squad->count());
    }

    public function testFilterReturnsMatchingCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew1->setSkill(SkillLevel::ADVANCED);

        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');
        $crew2->setSkill(SkillLevel::NOVICE);

        $squad->add($crew1);
        $squad->add($crew2);

        // Act
        $filtered = $squad->filter(fn(Crew $c) => $c->getSkill()->isHigh());

        // Assert
        $this->assertCount(1, $filtered);
        $this->assertContains($crew1, $filtered);
    }

    public function testMapTransformsCrews(): void
    {
        // Arrange
        $squad = new Squad();
        $squad->add($this->createCrew('johndoe', 'John', 'Doe'));
        $squad->add($this->createCrew('janedoe', 'Jane', 'Doe'));

        // Act
        $names = $squad->map(fn(Crew $c) => $c->getDisplayName());

        // Assert
        $this->assertEquals(['John Doe', 'Jane Doe'], $names);
    }

    public function testGetIteratorAllowsForeachLoop(): void
    {
        // Arrange
        $squad = new Squad();
        $crew1 = $this->createCrew('johndoe', 'John', 'Doe');
        $crew2 = $this->createCrew('janedoe', 'Jane', 'Doe');

        $squad->add($crew1);
        $squad->add($crew2);

        // Act & Assert
        $count = 0;
        foreach ($squad->getIterator() as $crew) {
            $this->assertInstanceOf(Crew::class, $crew);
            $count++;
        }

        $this->assertEquals(2, $count);
    }
}
