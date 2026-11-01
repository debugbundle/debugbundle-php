<?php

declare(strict_types=1);

namespace DebugBundle;

final class BeforeSend
{
    /** @var \WeakMap<\Closure, bool>|null */
    private static ?\WeakMap $activeHooks = null;
    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function apply(array $event, ?\Closure $hook): ?array
    {
        if ($hook === null || isset(self::$activeHooks[$hook])) {
            return $event;
        }

        self::$activeHooks ??= new \WeakMap();
        self::$activeHooks[$hook] = true;
        try {
            $result = $hook(OwnedJson::copy($event));
        } catch (\Throwable) {
            return $event;
        } finally {
            unset(self::$activeHooks[$hook]);
        }

        if ($result === null) {
            return null;
        }

        if (!is_array($result)) return $event;
        try {
            $owned = OwnedJson::copy($result);
            if (!CanonicalEventSchema::checked($owned)) return $event;
            $owned = CanonicalEventSchema::project($owned);
            if (!is_array($owned)) return $event;
            foreach (['context', 'correlation'] as $key) if (($owned[$key] ?? null) instanceof \stdClass) $owned[$key] = (array) $owned[$key];
            if (!EvidenceWire::optionalEvidence($owned)) return $event;
            if (($owned['event_type'] ?? null) === 'frontend_exception') {
                $mobile = in_array($owned['sdk_name'], ['@debugbundle/sdk-android', '@debugbundle/sdk-swift', '@debugbundle/sdk-react-native'], true);
                $field = $mobile ? 'device' : 'browser';
                if (!isset($owned['payload'][$field]) || (!is_array($owned['payload'][$field]) && !$owned['payload'][$field] instanceof \stdClass)) return $event;
            }
            return $owned;
        } catch (ValidationBudgetExceeded) {
            return null;
        } catch (\Throwable) {
            return $event;
        }
    }
}
