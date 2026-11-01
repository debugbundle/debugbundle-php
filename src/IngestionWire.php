<?php

declare(strict_types=1);

namespace DebugBundle;

/** Actual PHP JSON bytes; optional SDK metadata cannot strand a fitting primary. */
final class IngestionWire
{
    public const MAX_BODY_BYTES = 262144;
    public const MAX_EVENT_BYTES = self::MAX_BODY_BYTES - 13;

    /** @param array<string, mixed> $event */
    public static function bytes(array $event): int { return strlen(json_encode(CanonicalEventSchema::wireEvent($event), JSON_THROW_ON_ERROR)); }

    /** @param list<array<string, mixed>> $events */
    public static function body(array $events): string
    {
        return json_encode(['events' => array_map(CanonicalEventSchema::wireEvent(...), $events)], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function fit(array $event): ?array
    {
        if (self::bytes($event) <= self::MAX_EVENT_BYTES) return $event;
        $reduced = EvidenceWire::withoutOptional($event);
        if ($reduced !== null) {
            $limits = ['version' => 1];
            foreach (['exception_evidence', 'execution', 'resource_observations'] as $field) if (isset($event['payload'][$field])) $limits[$field] = 'budget_exceeded';
            $marked = $reduced;
            $marked['context']['evidence_limits'] = $limits;
            if (self::bytes($marked) <= self::MAX_EVENT_BYTES) return $marked;
            if (self::bytes($reduced) <= self::MAX_EVENT_BYTES) return $reduced;
        } else $reduced = $event;
        if (is_array($reduced['context'] ?? null) && EvidenceWire::reference($reduced['context']['evidence_reference'] ?? null) !== null) {
            unset($reduced['context']['evidence_reference']);
            if (self::bytes($reduced) <= self::MAX_EVENT_BYTES) return $reduced;
        }
        return null;
    }

    /** @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function prefix(array $events): array
    {
        $size = 12; // {"events":[]}
        foreach ($events as $index => $event) {
            $bytes = self::bytes($event);
            if ($bytes > self::MAX_EVENT_BYTES) throw new \InvalidArgumentException('event exceeds ingestion limit');
            $size += $bytes + ($index > 0 ? 1 : 0);
            if ($size > self::MAX_BODY_BYTES) return array_slice($events, 0, $index);
        }
        return $events;
    }

    /** @param list<array<string, mixed>> $events
     * @return list<list<array<string, mixed>>>
     */
    public static function chunks(array $events): array
    {
        $result = [];
        while ($events !== []) {
            $prefix = self::prefix($events);
            $result[] = $prefix;
            $events = array_slice($events, count($prefix));
        }
        return $result;
    }
}
