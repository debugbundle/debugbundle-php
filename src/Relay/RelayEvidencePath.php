<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

use DebugBundle\EvidenceWire;

/** The pin asserts every configured downstream reader, including local spools. */
final class RelayEvidencePath
{
    /** @var list<string> */
    private array $features;
    public function __construct(mixed $pin = null) { $this->features = EvidenceWire::descriptor($pin)['features']; }
    /** @return array{version:int,features:list<string>} */
    public function descriptor(): array { return ['version' => 1, 'features' => $this->features]; }
    /** @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public function prepare(array $event): ?array
    {
        return ($event['event_type'] ?? null) === 'analytics_event' ? $event : EvidenceWire::supported($event, $this->features);
    }
    public function observe(mixed $body, bool $required, bool $valid = true): bool
    {
        $supplied = is_array($body) && array_key_exists('evidence_capabilities', $body);
        if (!$required && !$supplied) return false;
        $features = EvidenceWire::descriptor($supplied && $valid ? $body['evidence_capabilities'] : null)['features'];
        $lost = array_diff($this->features, $features) !== []; $this->features = $features;
        return $lost;
    }
    /** @param list<string> $features */
    public function restrict(array $features): void { $this->features = array_values(array_intersect($this->features, $features)); }
    /** @param array<string,string> $headers */
    public static function requested(?string $url, array $headers): bool
    {
        if (($headers['x-debugbundle-evidence'] ?? null) === '1') return true;
        if ($url === null) return false;
        $query = parse_url($url, PHP_URL_QUERY);
        return is_string($query) && preg_match('/(?:^|&)debugbundle_evidence=1(?:&|$)/D', $query) === 1;
    }
}
