<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Enforcement Test
 *
 * Ensures Clean Architecture dependency rules are not violated.
 * Walks all .php files in each layer and asserts no forbidden cross-layer imports exist.
 *
 * Dependency direction: Presentation → Application → Domain
 * Infrastructure is a plugin (plugs into Application ports).
 *
 * Forbidden imports by layer:
 *   Domain       → must NOT import Application, Infrastructure, or Presentation
 *   Application  → must NOT import Infrastructure or Presentation
 *   Infrastructure → must NOT import Presentation
 *   Presentation → must NOT import Infrastructure or Domain
 */
class ArchitectureTest extends TestCase
{
    private string $srcRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcRoot = dirname(__DIR__, 3) . '/src';
    }

    // -----------------------------------------------------------------------
    // Domain layer
    // -----------------------------------------------------------------------

    public function testDomainDoesNotDependOnApplication(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Domain',
            forbiddenPrefix: 'App\\Application'
        );

        $this->assertNoViolations('Domain layer must not depend on Application layer', $violations);
    }

    public function testDomainDoesNotDependOnInfrastructure(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Domain',
            forbiddenPrefix: 'App\\Infrastructure'
        );

        $this->assertNoViolations('Domain layer must not depend on Infrastructure layer', $violations);
    }

    public function testDomainDoesNotDependOnPresentation(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Domain',
            forbiddenPrefix: 'App\\Presentation'
        );

        $this->assertNoViolations('Domain layer must not depend on Presentation layer', $violations);
    }

    // -----------------------------------------------------------------------
    // Application layer
    // -----------------------------------------------------------------------

    public function testApplicationDoesNotDependOnInfrastructure(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Application',
            forbiddenPrefix: 'App\\Infrastructure'
        );

        $this->assertNoViolations('Application layer must not depend on Infrastructure layer', $violations);
    }

    public function testApplicationDoesNotDependOnPresentation(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Application',
            forbiddenPrefix: 'App\\Presentation'
        );

        $this->assertNoViolations('Application layer must not depend on Presentation layer', $violations);
    }

    // -----------------------------------------------------------------------
    // Infrastructure layer
    // -----------------------------------------------------------------------

    public function testInfrastructureDoesNotDependOnPresentation(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Infrastructure',
            forbiddenPrefix: 'App\\Presentation'
        );

        $this->assertNoViolations('Infrastructure layer must not depend on Presentation layer', $violations);
    }

    // -----------------------------------------------------------------------
    // Presentation layer
    // -----------------------------------------------------------------------

    public function testPresentationDoesNotDependOnInfrastructure(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Presentation',
            forbiddenPrefix: 'App\\Infrastructure'
        );

        $this->assertNoViolations('Presentation layer must not depend on Infrastructure layer', $violations);
    }

    public function testPresentationDoesNotDependOnDomain(): void
    {
        $violations = $this->findViolations(
            layerDir: $this->srcRoot . '/Presentation',
            forbiddenPrefix: 'App\\Domain'
        );

        $this->assertNoViolations('Presentation layer must not depend on Domain layer', $violations);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Scan all .php files under $layerDir for `use` statements importing $forbiddenPrefix.
     *
     * @return array<string> Human-readable violation messages, one per offending import.
     */
    private function findViolations(string $layerDir, string $forbiddenPrefix): array
    {
        if (!is_dir($layerDir)) {
            return [];
        }

        $violations = [];
        $iterator   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($layerDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $lines        = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            $relativePath = str_replace($this->srcRoot . DIRECTORY_SEPARATOR, 'src/', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            foreach ($lines as $lineNumber => $line) {
                if (preg_match('/^\s*use\s+(' . preg_quote($forbiddenPrefix, '/') . '[\\\\;])/', $line)) {
                    $violations[] = sprintf(
                        '  %s:%d imports %s',
                        $relativePath,
                        $lineNumber + 1,
                        trim(preg_replace('/^\s*use\s+/', '', $line), " \t;")
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * @param string   $message    Human-readable rule description
     * @param string[] $violations List of violation messages
     */
    private function assertNoViolations(string $message, array $violations): void
    {
        $this->assertEmpty(
            $violations,
            $message . ":\n" . implode("\n", $violations)
        );
    }
}
