<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Application\Exception\ValidationException;
use App\Application\UseCase\Admin\AddToCrewWhitelistUseCase;
use App\Application\UseCase\Admin\GetAllBoatsUseCase;
use App\Application\UseCase\Admin\GetAllCrewsUseCase;
use App\Application\UseCase\Admin\GetAllUsersUseCase;
use App\Application\UseCase\Admin\GetConfigUseCase;
use App\Application\UseCase\Admin\GetMatchingDataUseCase;
use App\Application\UseCase\Admin\GetParticipantEmailsUseCase;
use App\Application\UseCase\Admin\GetUserDetailUseCase;
use App\Application\UseCase\Admin\RemoveFromCrewWhitelistUseCase;
use App\Application\UseCase\Admin\SendCustomNotificationUseCase;
use App\Application\UseCase\Admin\SetCrewCommitmentRankUseCase;
use App\Application\UseCase\Admin\SetUserAdminUseCase;
use App\Application\UseCase\Admin\SetUserStatusUseCase;
use App\Application\UseCase\Admin\UpdateCrewProfileUseCase;
use App\Application\UseCase\Season\ProcessSeasonUpdateUseCase;
use App\Application\UseCase\Season\UpdateConfigUseCase;
use App\Presentation\Controller\AdminController;
use App\Presentation\Response\JsonResponse;
use PHPUnit\Framework\TestCase;

class AdminControllerTest extends TestCase
{
    private array $adminAuth = ['is_admin' => true, 'user_id' => 1];

    private function makeController(
        UpdateConfigUseCase $updateConfigUseCase,
        ProcessSeasonUpdateUseCase $processSeasonUpdateUseCase,
    ): AdminController {
        return new AdminController(
            $this->createStub(GetMatchingDataUseCase::class),
            $this->createStub(GetParticipantEmailsUseCase::class),
            $this->createStub(SendCustomNotificationUseCase::class),
            $this->createStub(GetConfigUseCase::class),
            $updateConfigUseCase,
            $processSeasonUpdateUseCase,
            $this->createStub(GetAllUsersUseCase::class),
            $this->createStub(SetUserAdminUseCase::class),
            $this->createStub(SetUserStatusUseCase::class),
            $this->createStub(GetUserDetailUseCase::class),
            $this->createStub(GetAllCrewsUseCase::class),
            $this->createStub(GetAllBoatsUseCase::class),
            $this->createStub(UpdateCrewProfileUseCase::class),
            $this->createStub(AddToCrewWhitelistUseCase::class),
            $this->createStub(RemoveFromCrewWhitelistUseCase::class),
            $this->createStub(SetCrewCommitmentRankUseCase::class),
        );
    }

    private function getResponseData(JsonResponse $response): array
    {
        return (new \ReflectionClass($response))->getProperty('data')->getValue($response);
    }

    private function getResponseStatusCode(JsonResponse $response): int
    {
        return (new \ReflectionClass($response))->getProperty('statusCode')->getValue($response);
    }

    public function testUpdateConfigCallsProcessSeasonUpdateAndIncludesResultInResponse(): void
    {
        $updateConfigUseCase = $this->createMock(UpdateConfigUseCase::class);
        $updateConfigUseCase->method('execute')
            ->willReturn(['success' => true, 'message' => 'Season configuration updated successfully']);

        $processSeasonUpdateUseCase = $this->createMock(ProcessSeasonUpdateUseCase::class);
        $processSeasonUpdateUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(['success' => true, 'events_processed' => 3, 'flotillas_generated' => 3]);

        $controller = $this->makeController($updateConfigUseCase, $processSeasonUpdateUseCase);
        $response   = $controller->updateConfig([], $this->adminAuth);

        $this->assertEquals(200, $this->getResponseStatusCode($response));

        $data = $this->getResponseData($response);
        $this->assertTrue($data['data']['recalculation']['success']);
        $this->assertEquals(3, $data['data']['recalculation']['events_processed']);
        $this->assertEquals(3, $data['data']['recalculation']['flotillas_generated']);
    }

    public function testUpdateConfigReturns200WithRecalculationErrorWhenProcessSeasonUpdateThrows(): void
    {
        $updateConfigUseCase = $this->createMock(UpdateConfigUseCase::class);
        $updateConfigUseCase->method('execute')
            ->willReturn(['success' => true, 'message' => 'Season configuration updated successfully']);

        $processSeasonUpdateUseCase = $this->createMock(ProcessSeasonUpdateUseCase::class);
        $processSeasonUpdateUseCase->method('execute')
            ->willThrowException(new \RuntimeException('Database locked'));

        $controller = $this->makeController($updateConfigUseCase, $processSeasonUpdateUseCase);
        $response   = $controller->updateConfig([], $this->adminAuth);

        // Config was saved — must still be 200
        $this->assertEquals(200, $this->getResponseStatusCode($response));

        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertEquals('Database locked', $data['data']['recalculation']['error']);
    }

    public function testUpdateConfigDoesNotCallProcessSeasonUpdateWhenValidationFails(): void
    {
        $updateConfigUseCase = $this->createMock(UpdateConfigUseCase::class);
        $updateConfigUseCase->method('execute')
            ->willThrowException(new ValidationException(['source' => 'Invalid value']));

        $processSeasonUpdateUseCase = $this->createMock(ProcessSeasonUpdateUseCase::class);
        $processSeasonUpdateUseCase->expects($this->never())->method('execute');

        $controller = $this->makeController($updateConfigUseCase, $processSeasonUpdateUseCase);
        $response   = $controller->updateConfig(['source' => 'invalid'], $this->adminAuth);

        $this->assertEquals(400, $this->getResponseStatusCode($response));
    }
}
