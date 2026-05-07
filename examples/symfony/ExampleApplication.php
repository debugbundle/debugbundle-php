<?php

declare(strict_types=1);

namespace DebugBundle\Examples\Symfony;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\Symfony\DebugBundleEventSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExampleApplication implements HttpKernelInterface
{
    private EventDispatcher $dispatcher;
    private DebugBundleSdk $sdk;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->sdk = new DebugBundleSdk();
        $this->sdk->init($config);

        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new DebugBundleEventSubscriber($this->sdk));
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->dispatcher->dispatch(new RequestEvent($this, $request, $type), KernelEvents::REQUEST);

        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $error) {
            $this->dispatcher->dispatch(new ExceptionEvent($this, $request, $type, $error), KernelEvents::EXCEPTION);
            if (!$catch) {
                throw $error;
            }

            $response = new Response(
                json_encode(['error' => 'symfony example failure'], JSON_THROW_ON_ERROR),
                500,
                ['Content-Type' => 'application/json']
            );
        }

        $this->dispatcher->dispatch(new ResponseEvent($this, $request, $type, $response), KernelEvents::RESPONSE);
        $this->sdk->flush();

        return $response;
    }

    public function reset(): void
    {
        $this->sdk->reset();
    }

    private function dispatch(Request $request): Response
    {
        return match ($request->getPathInfo()) {
            '/log' => $this->logResponse(),
            '/exception' => throw new \RuntimeException('symfony example failure'),
            default => new Response(
                json_encode(['ok' => true, 'framework' => 'symfony'], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json']
            ),
        };
    }

    private function logResponse(): Response
    {
        $this->sdk->captureLog('symfony example log', 'error', ['framework' => 'symfony']);

        return new Response(
            json_encode(['ok' => true, 'logged' => true], JSON_THROW_ON_ERROR),
            202,
            ['Content-Type' => 'application/json']
        );
    }
}