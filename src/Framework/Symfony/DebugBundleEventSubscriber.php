<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Symfony;

use DebugBundle\DebugBundleSdk;
use DebugBundle\Framework\HttpRateLimitContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class DebugBundleEventSubscriber implements EventSubscriberInterface
{
    private const START_TIME_ATTRIBUTE = '_debugbundle_started_at';
    private const RATE_LIMIT_CAPTURED_ATTRIBUTE = '_debugbundle_429_captured';

    /** @var \Closure(): float */
    private \Closure $timeProvider;

    public function __construct(DebugBundleSdk $sdk, ?callable $timeProvider = null)
    {
        $this->sdk = $sdk;
        $this->timeProvider = $timeProvider instanceof \Closure
            ? $timeProvider
            : \Closure::fromCallable($timeProvider ?? static fn (): float => microtime(true));
    }

    private DebugBundleSdk $sdk;

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $request->attributes->set(self::START_TIME_ATTRIBUTE, $this->now());
        $this->sdk->beginRequest($this->normalizeRequest($request), $request);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();
        if ($statusCode === 429 && $request->attributes->get(self::RATE_LIMIT_CAPTURED_ATTRIBUTE) === true) {
            $this->sdk->endRequest($request);
            return;
        }

        $startedAt = $request->attributes->get(self::START_TIME_ATTRIBUTE, $this->now());
        $startedAtValue = is_numeric($startedAt) ? (float) $startedAt : $this->now();
        $responseHeaders = HttpRateLimitContext::extractAllowedResponseHeaders($response->headers->all());

        $this->sdk->captureRequest(
            $this->normalizeRequest($request),
            [
                'status_code' => $statusCode,
                'duration_ms' => (int) round(($this->now() - $startedAtValue) * 1000),
                ...($responseHeaders !== [] ? ['response_headers' => $responseHeaders] : []),
            ]
        );
        $this->sdk->endRequest($request);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $error = $event->getThrowable();
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 429) {
            $startedAt = $request->attributes->get(self::START_TIME_ATTRIBUTE, $this->now());
            $startedAtValue = is_numeric($startedAt) ? (float) $startedAt : $this->now();
            $responseHeaders = HttpRateLimitContext::extractAllowedResponseHeaders($error->getHeaders());
            $this->sdk->captureRequest(
                $this->normalizeRequest($request),
                [
                    'status_code' => 429,
                    'duration_ms' => (int) round(($this->now() - $startedAtValue) * 1000),
                    ...($responseHeaders !== [] ? ['response_headers' => $responseHeaders] : []),
                ]
            );
            $request->attributes->set(self::RATE_LIMIT_CAPTURED_ATTRIBUTE, true);
            $this->sdk->endRequest($request);
            return;
        }

        $this->sdk->captureException($error, [
            'request' => $this->normalizeRequest($request),
        ]);
        $this->sdk->endRequest($request);
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(Request $request): array
    {
        $routeTemplate = HttpRateLimitContext::extractSymfonyRouteTemplate($request);
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'headers' => $request->headers->all(),
            ...($routeTemplate !== null ? ['route_template' => $routeTemplate] : []),
        ];
    }

    private function now(): float
    {
        $provider = $this->timeProvider;
        return $provider();
    }
}
