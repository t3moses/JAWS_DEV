<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection;

use App\Domain\Collection\Fleet;
use App\Domain\Entity\Boat;
use App\Domain\ValueObject\BoatKey;
use App\Domain\ValueObject\EventId;
use PHPUnit\Framework\TestCase;

class FleetTest extends TestCase
{
    private function createBoat(string $key, string $name): Boat
    {
        $boat = new Boat(
            key: BoatKey::fromString($key),
            displayName: $name,
            ownerFirstName: 'John',
            ownerLastName: 'Doe',
            ownerMobile: '555-1234',
            minBerths: 1,
            maxBerths: 3,
            assistanceRequired: false,
            socialPreference: true
        );
        return $boat;
    }

    public function testAddBoatToFleet(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat = $this->createBoat('sailaway', 'Sail Away');

        // Act
        $fleet->add($boat);

        // Assert
        $this->assertEquals(1, $fleet->count());
        $this->assertTrue($fleet->has(BoatKey::fromString('sailaway')));
    }

    public function testAddMultipleBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');

        // Act
        $fleet->add($boat1);
        $fleet->add($boat2);

        // Assert
        $this->assertEquals(2, $fleet->count());
    }

    public function testAddOverwritesExistingBoat(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat2 = $this->createBoat('sailaway', 'New Name');

        // Act
        $fleet->add($boat1);
        $fleet->add($boat2);

        // Assert
        $this->assertEquals(1, $fleet->count());
        $retrieved = $fleet->get(BoatKey::fromString('sailaway'));
        $this->assertEquals('New Name', $retrieved->getDisplayName());
    }

    public function testRemoveBoat(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat = $this->createBoat('sailaway', 'Sail Away');

        $fleet->add($boat);

        // Act
        $fleet->remove(BoatKey::fromString('sailaway'));

        // Assert
        $this->assertEquals(0, $fleet->count());
        $this->assertFalse($fleet->has(BoatKey::fromString('sailaway')));
    }

    public function testRemoveNonExistentBoatDoesNotThrow(): void
    {
        // Arrange
        $fleet = new Fleet();

        // Act
        $fleet->remove(BoatKey::fromString('nonexistent'));

        // Assert
        $this->assertEquals(0, $fleet->count());
    }

    public function testGetBoat(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat = $this->createBoat('sailaway', 'Sail Away');

        $fleet->add($boat);

        // Act
        $retrieved = $fleet->get(BoatKey::fromString('sailaway'));

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('sailaway', $retrieved->getKey()->toString());
    }

    public function testGetNonExistentBoatReturnsNull(): void
    {
        // Arrange
        $fleet = new Fleet();

        // Act
        $retrieved = $fleet->get(BoatKey::fromString('nonexistent'));

        // Assert
        $this->assertNull($retrieved);
    }

    public function testHasReturnsTrueWhenBoatExists(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat = $this->createBoat('sailaway', 'Sail Away');

        $fleet->add($boat);

        // Assert
        $this->assertTrue($fleet->has(BoatKey::fromString('sailaway')));
    }

    public function testHasReturnsFalseWhenBoatDoesNotExist(): void
    {
        // Arrange
        $fleet = new Fleet();

        // Assert
        $this->assertFalse($fleet->has(BoatKey::fromString('nonexistent')));
    }

    public function testAllReturnsAllBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');

        $fleet->add($boat1);
        $fleet->add($boat2);

        // Act
        $boats = $fleet->all();

        // Assert
        $this->assertCount(2, $boats);
        $this->assertContains($boat1, $boats);
        $this->assertContains($boat2, $boats);
    }

    public function testAllReturnsIndexedArray(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');

        $fleet->add($boat1);
        $fleet->add($boat2);

        // Act
        $boats = $fleet->all();

        // Assert
        // Check that keys are 0, 1, 2, etc. (not associative)
        $this->assertEquals([0, 1], array_keys($boats));
    }

    public function testGetAvailableForReturnsOnlyAvailableBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $eventId = EventId::fromString('Fri May 29');

        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat1->setBerths($eventId, 2);

        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');
        $boat2->setBerths($eventId, 0);

        $boat3 = $this->createBoat('oceandream', 'Ocean Dream');
        $boat3->setBerths($eventId, 1);

        $fleet->add($boat1);
        $fleet->add($boat2);
        $fleet->add($boat3);

        // Act
        $available = $fleet->getAvailableFor($eventId);

        // Assert
        $this->assertCount(2, $available);
        $this->assertContains($boat1, $available);
        $this->assertContains($boat3, $available);
        $this->assertNotContains($boat2, $available);
    }

    public function testGetAvailableForReturnsEmptyArrayWhenNoneAvailable(): void
    {
        // Arrange
        $fleet = new Fleet();
        $eventId = EventId::fromString('Fri May 29');

        $boat = $this->createBoat('sailaway', 'Sail Away');
        $fleet->add($boat);

        // Act
        $available = $fleet->getAvailableFor($eventId);

        // Assert
        $this->assertEmpty($available);
    }

    public function testCountReturnsZeroForEmptyFleet(): void
    {
        // Arrange
        $fleet = new Fleet();

        // Assert
        $this->assertEquals(0, $fleet->count());
    }

    public function testCountReturnsCorrectNumber(): void
    {
        // Arrange
        $fleet = new Fleet();
        $fleet->add($this->createBoat('sailaway', 'Sail Away'));
        $fleet->add($this->createBoat('seabreeze', 'Sea Breeze'));
        $fleet->add($this->createBoat('oceandream', 'Ocean Dream'));

        // Assert
        $this->assertEquals(3, $fleet->count());
    }

    public function testIsEmptyReturnsTrueForNewFleet(): void
    {
        // Arrange
        $fleet = new Fleet();

        // Assert
        $this->assertTrue($fleet->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenBoatsExist(): void
    {
        // Arrange
        $fleet = new Fleet();
        $fleet->add($this->createBoat('sailaway', 'Sail Away'));

        // Assert
        $this->assertFalse($fleet->isEmpty());
    }

    public function testClearRemovesAllBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $fleet->add($this->createBoat('sailaway', 'Sail Away'));
        $fleet->add($this->createBoat('seabreeze', 'Sea Breeze'));

        // Act
        $fleet->clear();

        // Assert
        $this->assertTrue($fleet->isEmpty());
        $this->assertEquals(0, $fleet->count());
    }

    public function testFilterReturnsMatchingBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat1->setAssistanceRequired(true);

        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');
        $boat2->setAssistanceRequired(false);

        $fleet->add($boat1);
        $fleet->add($boat2);

        // Act
        $filtered = $fleet->filter(fn(Boat $b) => $b->requiresAssistance());

        // Assert
        $this->assertCount(1, $filtered);
        $this->assertContains($boat1, $filtered);
    }

    public function testMapTransformsBoats(): void
    {
        // Arrange
        $fleet = new Fleet();
        $fleet->add($this->createBoat('sailaway', 'Sail Away'));
        $fleet->add($this->createBoat('seabreeze', 'Sea Breeze'));

        // Act
        $names = $fleet->map(fn(Boat $b) => $b->getDisplayName());

        // Assert
        $this->assertEquals(['Sail Away', 'Sea Breeze'], $names);
    }

    public function testGetIteratorAllowsForeachLoop(): void
    {
        // Arrange
        $fleet = new Fleet();
        $boat1 = $this->createBoat('sailaway', 'Sail Away');
        $boat2 = $this->createBoat('seabreeze', 'Sea Breeze');

        $fleet->add($boat1);
        $fleet->add($boat2);

        // Act & Assert
        $count = 0;
        foreach ($fleet->getIterator() as $boat) {
            $this->assertInstanceOf(Boat::class, $boat);
            $count++;
        }

        $this->assertEquals(2, $count);
    }
}
