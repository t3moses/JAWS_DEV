<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * User Entity
 *
 * Represents a user account in the system with authentication credentials.
 * Users can be crew members, boat owners, or both (via separate profile records).
 *
 * Note: This entity is separate from Crew and Boat entities.
 * A user authenticates with email/password, then links to crew/boat profiles.
 */
class User
{
    private ?int $id = null;

    public function __construct(
        private string $email,
        private string $passwordHash,
        private string $accountType,
        private bool $isAdmin = false,
        private ?\DateTimeImmutable $lastLogin = null,
        private ?\DateTimeImmutable $lastLogout = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
        private ?\DateTimeImmutable $disabledAt = null,
    ) {
        $this->validateEmail($email);
        $this->validateAccountType($accountType);

        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
        if ($this->updatedAt === null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Get user ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set user ID (called by repository after insert)
     */
    public function setId(int $id): void
    {
        if ($this->id !== null) {
            throw new \RuntimeException('User ID is already set');
        }
        $this->id = $id;
    }

    /**
     * Get email address
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Set email address
     */
    public function setEmail(string $email): void
    {
        $this->validateEmail($email);
        $this->email = $email;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Get password hash
     */
    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * Set password hash
     */
    public function setPasswordHash(string $passwordHash): void
    {
        if (empty($passwordHash)) {
            throw new \InvalidArgumentException('Password hash cannot be empty');
        }
        $this->passwordHash = $passwordHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Get account type
     */
    public function getAccountType(): string
    {
        return $this->accountType;
    }

    /**
     * Set account type
     */
    public function setAccountType(string $accountType): void
    {
        $this->validateAccountType($accountType);
        $this->accountType = $accountType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    /**
     * Set admin status
     */
    public function setIsAdmin(bool $isAdmin): void
    {
        $this->isAdmin = $isAdmin;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Check if user can manage crew profile
     */
    public function canManageCrew(): bool
    {
        return $this->accountType === 'crew';
    }

    /**
     * Check if user can manage boat profile
     */
    public function canManageBoat(): bool
    {
        return $this->accountType === 'boat_owner';
    }

    /**
     * Get last login timestamp
     */
    public function getLastLogin(): ?\DateTimeImmutable
    {
        return $this->lastLogin;
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(\DateTimeImmutable $time): void
    {
        $this->lastLogin = $time;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Get last logout timestamp
     */
    public function getLastLogout(): ?\DateTimeImmutable
    {
        return $this->lastLogout;
    }

    /**
     * Update last logout timestamp
     */
    public function updateLastLogout(\DateTimeImmutable $time): void
    {
        $this->lastLogout = $time;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Get the timestamp the account was disabled (null if active)
     */
    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    /**
     * Check if the account is currently disabled (suspended)
     */
    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    /**
     * Disable (suspend) the account
     *
     * Idempotent: re-disabling an already-disabled account preserves the
     * original disabled_at timestamp.
     */
    public function disable(\DateTimeImmutable $time): void
    {
        if ($this->disabledAt !== null) {
            return;
        }
        $this->disabledAt = $time;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Reactivate (un-suspend) the account
     */
    public function reactivate(): void
    {
        if ($this->disabledAt === null) {
            return;
        }
        $this->disabledAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Get created at timestamp
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Get updated at timestamp
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'account_type' => $this->accountType,
            'is_admin' => $this->isAdmin,
            'last_login' => $this->lastLogin?->format('Y-m-d H:i:s'),
            'last_logout' => $this->lastLogout?->format('Y-m-d H:i:s'),
            'disabled_at' => $this->disabledAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Validate email format
     */
    private function validateEmail(string $email): void
    {
        if (empty($email)) {
            throw new \InvalidArgumentException('Email cannot be empty');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
    }

    /**
     * Validate account type
     */
    private function validateAccountType(string $accountType): void
    {
        $validTypes = ['crew', 'boat_owner'];

        if (!in_array($accountType, $validTypes, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid account type: %s. Must be one of: %s', $accountType, implode(', ', $validTypes))
            );
        }
    }
}
