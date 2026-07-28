<?php

declare(strict_types=1);

namespace DebugBundle\Transport;

final class IngestionAcknowledgementDecision
{
    /** @var list<int> */
    public readonly array $retryableIndices;

    /** @var list<array{index: int, reason: string}> */
    public readonly array $terminalErrors;

    /**
     * @param list<int>                                $retryableIndices
     * @param list<array{index: int, reason: string}> $terminalErrors
     */
    private function __construct(
        public readonly string $kind,
        public readonly int $accepted = 0,
        array $retryableIndices = [],
        array $terminalErrors = [],
        public readonly ?string $reason = null,
    ) {
        $this->retryableIndices = $retryableIndices;
        $this->terminalErrors = $terminalErrors;
    }

    public static function decide(mixed $body, int $batchLength): self
    {
        if (!is_array($body) || !self::hasAcknowledgementFields($body)) {
            return new self('legacy');
        }

        $accepted = $body['accepted'] ?? null;
        $rejected = $body['rejected'] ?? null;
        $errors = $body['errors'] ?? null;
        if (
            !self::isCount($accepted)
            || !self::isCount($rejected)
            || !is_array($errors)
            || $accepted + $rejected !== $batchLength
            || count($errors) !== $rejected
        ) {
            return new self('protocol_failure', reason: 'inconsistent_counts');
        }

        $seen = [];
        $retryableIndices = [];
        $terminalErrors = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                return new self('protocol_failure', reason: 'invalid_error_index');
            }
            $index = $error['index'] ?? null;
            $reason = $error['reason'] ?? null;
            if (
                !is_int($index)
                || $index < 0
                || $index >= $batchLength
                || isset($seen[$index])
                || !is_string($reason)
                || $reason === ''
            ) {
                return new self('protocol_failure', reason: 'invalid_error_index');
            }
            $seen[$index] = true;
            if (in_array($reason, self::RETRYABLE_REASONS, true)) {
                $retryableIndices[] = $index;
            } else {
                $terminalErrors[] = ['index' => $index, 'reason' => $reason];
            }
        }

        return new self('acknowledged', $accepted, $retryableIndices, $terminalErrors);
    }

    /** @param array<mixed> $body */
    private static function hasAcknowledgementFields(array $body): bool
    {
        return array_key_exists('accepted', $body)
            || array_key_exists('rejected', $body)
            || array_key_exists('errors', $body);
    }

    private static function isCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /** @var list<string> */
    private const RETRYABLE_REASONS = [
        'rate_limited',
        'monthly_quota_exceeded',
        'analytics_quota_exceeded',
    ];
}
