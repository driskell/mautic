<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Controller;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Controller\AjaxController;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

final class AjaxControllerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var MockObject&Session
     */
    private MockObject $sessionMock;

    /**
     * @var MockObject&EmailModel
     */
    private MockObject $modelMock;

    /**
     * @var MockObject&Email
     */
    private MockObject $emailMock;

    private AjaxController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionMock      = $this->createMock(Session::class);
        $containerMock          = $this->createMock(Container::class);
        $this->modelMock        = $this->createMock(EmailModel::class);
        $this->emailMock        = $this->createMock(Email::class);
        $requestStack           = new RequestStack();

        $this->controller = new AjaxController(
            $this->createStub(ManagerRegistry::class),
            $this->createStub(ModelFactory::class),
            $this->createStub(UserHelper::class),
            $this->createStub(CoreParametersHelper::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(Translator::class),
            $this->createStub(FlashBag::class),
            $requestStack,
            $this->createStub(CorePermissions::class)
        );

        $this->controller->setContainer($containerMock);
        $this->controller->autowireEmailAjaxController($this->modelMock);

        $parameterBag = $this->createMock(ContainerBagInterface::class);
        $parameterBag->method('get')
            ->with('kernel.environment')
            ->willReturn('test');

        $containerMock->method('has')
            ->with('parameter_bag')
            ->willReturn(true);
        $containerMock->method('get')
            ->with('parameter_bag')
            ->willReturn($parameterBag);
    }

    public function testSendBatchActionWhenNoIdProvided(): void
    {
        $response = $this->controller->sendBatchAction(new Request([], []), new NullLogger());

        $this->assertSame('{"success":0}', $response->getContent());
    }

    public function testSendBatchActionWhenIdProvidedButEmailNotPublished(): void
    {
        $this->modelMock->expects($this->once())
            ->method('getEntity')
            ->with(5)
            ->willReturn($this->emailMock);

        $this->modelMock->expects($this->never())
            ->method('sendEmailToLists');
        $matcher = $this->exactly(3);

        $this->sessionMock->expects($matcher)
            ->method('get')->willReturnCallback(function (...$parameters) use ($matcher): array|false {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.progress', $parameters[0]);

                    return [0, 100];
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.stats', $parameters[0]);

                    return ['sent' => 0, 'failed' => 0, 'failedRecipients' => []];
                }
                if (3 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.active', $parameters[0]);

                    return false;
                }

                throw new Exception(sprintf('Method not be called for %dth time', $matcher->numberOfInvocations()));
            });

        $this->emailMock->expects($this->once())
            ->method('isPublished')
            ->willReturn(false);

        $request = new Request([], ['id' => 5, 'pending' => 100]);
        $request->setSession($this->sessionMock);
        $response = $this->controller->sendBatchAction($request, new NullLogger());
        $expected = '{"success":1,"percent":0,"progress":[0,100],"stats":{"sent":0,"failed":0,"failedRecipients":[]}}';
        $this->assertSame($expected, $response->getContent());
    }

    public function testSendBatchActionWhenIdProvidedAndEmailIsPublished(): void
    {
        $this->modelMock->expects($this->once())
            ->method('getEntity')
            ->with(5)
            ->willReturn($this->emailMock);

        $this->modelMock->expects($this->once())
            ->method('sendEmailToLists')
            ->with($this->emailMock, null, 50)
            ->willReturn([50, 0, []]);
        $matcher = $this->exactly(3);

        $this->sessionMock->expects($matcher)
            ->method('get')->willReturnCallback(function (...$parameters) use ($matcher): array|false {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.progress', $parameters[0]);

                    return [0, 100];
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.stats', $parameters[0]);

                    return ['sent' => 0, 'failed' => 0, 'failedRecipients' => []];
                }
                if (3 === $matcher->numberOfInvocations()) {
                    $this->assertSame('mautic.email.send.active', $parameters[0]);

                    return false;
                }

                throw new Exception(sprintf('Method not be called for %dth time', $matcher->numberOfInvocations()));
            });

        $this->emailMock->expects($this->once())
            ->method('isPublished')
            ->willReturn(true);

        $request = new Request([], ['id' => 5, 'pending' => 100, 'batchlimit' => 50]);
        $request->setSession($this->sessionMock);
        $response = $this->controller->sendBatchAction($request, new NullLogger());
        $expected = '{"success":1,"percent":50,"progress":[50,100],"stats":{"sent":50,"failed":0,"failedRecipients":[]}}';
        $this->assertSame($expected, $response->getContent());
    }

    public function testSendBatchActionKeepsProgressWhenTheBatchHitsARetryableDatabaseError(): void
    {
        $this->modelMock->method('getEntity')->willReturn($this->emailMock);
        $this->emailMock->method('isPublished')->willReturn(true);

        $this->modelMock->expects($this->once())
            ->method('sendEmailToLists')
            ->willThrowException(new DeadlockException($this->createStub(DriverException::class), null));

        $session = $this->createSessionWithProgress([20, 100]);
        $session->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $name, mixed $value): void {
                $this->assertSame('mautic.email.send.active', $name);
            });

        $request = new Request([], ['id' => 5, 'pending' => 100, 'batchlimit' => 50]);
        $request->setSession($session);
        $response = $this->controller->sendBatchAction($request, new NullLogger());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            '{"success":1,"percent":20,"progress":[20,100],"stats":{"sent":0,"failed":0,"failedRecipients":[]}}',
            $response->getContent()
        );
    }

    public function testSendBatchActionClearsTheActiveFlagWhenTheBatchFails(): void
    {
        $this->modelMock->method('getEntity')->willReturn($this->emailMock);
        $this->emailMock->method('isPublished')->willReturn(true);

        $this->modelMock->method('sendEmailToLists')
            ->willThrowException(new \RuntimeException('Transport down'));

        $activeValues = [];
        $session      = $this->createSessionWithProgress([0, 100]);
        $session->method('set')
            ->willReturnCallback(function (string $name, mixed $value) use (&$activeValues): void {
                if ('mautic.email.send.active' === $name) {
                    $activeValues[] = $value;
                }
            });

        $request = new Request([], ['id' => 5, 'pending' => 100]);
        $request->setSession($session);

        try {
            $this->controller->sendBatchAction($request, new NullLogger());
            $this->fail('The batch failure should surface.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([true, false], $activeValues);
    }

    public function testSendBatchActionCompletesWhenNoContactsArePending(): void
    {
        $this->modelMock->method('getEntity')->willReturn($this->emailMock);
        $this->emailMock->method('isPublished')->willReturn(true);

        $this->modelMock->method('sendEmailToLists')->willReturn([0, 0, []]);

        $request = new Request([], ['id' => 5, 'pending' => 100, 'batchlimit' => 50]);
        $request->setSession($this->createSessionWithProgress([20, 100]));
        $response = $this->controller->sendBatchAction($request, new NullLogger());

        $this->assertSame(
            '{"success":1,"percent":100,"progress":[100,100],"stats":{"sent":0,"failed":0,"failedRecipients":[]}}',
            $response->getContent()
        );
    }

    /**
     * @param array{int, int} $progress
     *
     * @return MockObject&Session
     */
    private function createSessionWithProgress(array $progress): MockObject
    {
        $this->sessionMock->method('get')
            ->willReturnMap([
                ['mautic.email.send.progress', [0, 100], $progress],
                ['mautic.email.send.stats', ['sent' => 0, 'failed' => 0, 'failedRecipients' => []], ['sent' => 0, 'failed' => 0, 'failedRecipients' => []]],
                ['mautic.email.send.active', false, false],
            ]);

        return $this->sessionMock;
    }
}
