<?php

declare(strict_types=1);

namespace DebugBundle\Framework\Symfony;

use DebugBundle\DebugBundleSdk;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class DebugBundleEventSubscriber implements EventSubscriberInterface
{
    private const START_TIME_ATTRIBUTE = '_debugbundle_started_at';

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
        $this->sdk->beginRequest($this->normalizeRequest($request));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $startedAt = $request->attributes->get(self::START_TIME_ATTRIBUTE, $this->now());
        $startedAtValue = is_numeric($startedAt) ? (float) $startedAt : $this->now();

        $this->sdk->captureRequest(
            $this->normalizeRequest($request),
            [
                'status_code' => $event->getResponse()->getStatusCode(),
                'duration_ms' => (int) round(($this->now() - $startedAtValue) * 1000),
            ]
        );
        $this->sdk->endRequest();
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->sdk->captureException($event->getThrowable(), [
            'request' => $this->normalizeRequest($event->getRequest()),
        ]);
        $this->sdk->endRequest();
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'headers' => $request->headers->all(),
        ];
    }

    private function now(): float
    {
        $provider = $this->timeProvider;
        return $provider();
    }
}