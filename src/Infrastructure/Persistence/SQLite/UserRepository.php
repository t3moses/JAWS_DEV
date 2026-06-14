<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\SQLite;

use App\Application\Port\Repository\UserRepositoryInterface;
use App\Domain\Entity\User;
use PDO;

/**
 * User Repository
 *
 * SQLite implementation of UserRepositoryInterface.
 * Handles all user persistence operations using PDO prepared statements.
 */
class UserRepository implements UserRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    /**
     * Find user by ID
     *
     * @param int $id User ID
     * @return User|null User entity or null if not found
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM users WHERE id = :id LIMIT 1
        ');

        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Find user by email address
     *
     * @param string $email Email address
     * @return User|null User entity or null if not found
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM users WHERE email = :email LIMIT 1
        ');

        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Check if email already exists
     *
     * @param string $email Email address to check
     * @return bool True if email exists, false otherwise
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM users WHERE email = :email
        ');

        $stmt->execute(['email' => $email]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Save user (create or update)
     *
     * @param User $user User entity to save
     * @return void
     */
    public function save(User $user): void
    {
        if ($user->getId() === null) {
            $this->insert($user);
        } else {
            $this->update($user);
        }
    }

    /**
     * Delete user by ID
     *
     * @param int $id User ID to delete
     * @return void
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM users WHERE id = :id
        ');

        $stmt->execute(['id' => $id]);
    }

    /**
     * Get total user count
     *
     * @return int Number of users
     */
    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        return (int)$stmt->fetchColumn();
    }

    /**
     * Find all users ordered by email
     *
     * @return User[] Array of all user entities
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY email ASC');
        $rows = $stmt->fetchAll();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Insert new user
     *
     * @param User $user User entity to insert
     * @return void
     */
    private function insert(User $user): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO users (email, password_hash, account_type, is_admin, last_login, last_logout, disabled_at, created_at, updated_at)
            VALUES (:email, :password_hash, :account_type, :is_admin, :last_login, :last_logout, :disabled_at, :created_at, :updated_at)
        ');

        $stmt->execute([
            'email' => $user->getEmail(),
            'password_hash' => $user->getPasswordHash(),
            'account_type' => $user->getAccountType(),
            'is_admin' => $user->isAdmin() ? 1 : 0,
            'last_login' => $user->getLastLogin()?->format('Y-m-d H:i:s'),
            'last_logout' => $user->getLastLogout()?->format('Y-m-d H:i:s'),
            'disabled_at' => $user->getDisabledAt()?->format('Y-m-d H:i:s'),
            'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $user->getUpdatedAt()->format('Y-m-d H:i:s'),
        ]);

        // Set the auto-generated ID on the entity
        $user->setId((int)$this->pdo->lastInsertId());
    }

    /**
     * Update existing user
     *
     * @param User $user User entity to update
     * @return void
     */
    private function update(User $user): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE users
            SET email = :email,
                password_hash = :password_hash,
                account_type = :account_type,
                is_admin = :is_admin,
                last_login = :last_login,
                last_logout = :last_logout,
                disabled_at = :disabled_at,
                updated_at = :updated_at
            WHERE id = :id
        ');

        $stmt->execute([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'password_hash' => $user->getPasswordHash(),
            'account_type' => $user->getAccountType(),
            'is_admin' => $user->isAdmin() ? 1 : 0,
            'last_login' => $user->getLastLogin()?->format('Y-m-d H:i:s'),
            'last_logout' => $user->getLastLogout()?->format('Y-m-d H:i:s'),
            'disabled_at' => $user->getDisabledAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $user->getUpdatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Hydrate database row into User entity
     *
     * @param array $row Database row
     * @return User User entity
     */
    private function hydrate(array $row): User
    {
        $user = new User(
            email: $row['email'],
            passwordHash: $row['password_hash'],
            accountType: $row['account_type'],
            isAdmin: (bool)$row['is_admin'],
            lastLogin: $row['last_login'] ? new \DateTimeImmutable($row['last_login']) : null,
            lastLogout: $row['last_logout'] ? new \DateTimeImmutable($row['last_logout']) : null,
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
            disabledAt: !empty($row['disabled_at']) ? new \DateTimeImmutable($row['disabled_at']) : null,
        );

        $user->setId((int)$row['id']);

        return $user;
    }
}
