<?php

namespace ManagerCore\Services\ESI;

/**
 * How old an ESI notification may be when Manager Core stores it, and when it
 * goes to a plugin's handler.
 *
 * ESI sometimes serves a notification hours late: a moon extraction's start
 * once came eleven hours after CCP sent it. Manager Core keeps anything up to
 * two weeks old, and each handler says how old it takes them, so a plugin that
 * can still use late news gets it, and an alert that would only confuse
 * people that late isn't sent.
 *
 * Those ages apply to notifications sent after the install started keeping
 * them ($since). Older ones go out as they did before: stored when no more
 * than DEFAULT_HOURS old, handed out within twice that.
 *
 * Times are Unix seconds, so none of this needs Laravel or Carbon.
 */
final class NotificationAge
{
    /** How old a handler takes notifications when it names no age. */
    public const DEFAULT_HOURS = 2;

    /** Nothing older is stored, so no handler can take more than this. */
    public const INTAKE_HOURS = 336;

    /**
     * Types whose handlers take more than the default unless they ask for
     * something else. An extraction runs for days, so word that it started is
     * still worth sending a day or two late.
     */
    public const BUILT_IN_HOURS = [
        'MoonminingExtractionStarted' => 72,
    ];

    /**
     * The age a handler takes for this type: what it asked for when it
     * registered (one number for all its types, or one per type), else the
     * type's built-in age, else the default. Never more than the intake.
     *
     * @param int|float|string|array|null $asked
     */
    public static function hoursFor(string $type, $asked = null): float
    {
        if (is_array($asked)) {
            $asked = $asked[$type] ?? null;
        }

        if (is_numeric($asked) && (float) $asked > 0) {
            return min((float) $asked, (float) self::INTAKE_HOURS);
        }

        return (float) (self::BUILT_IN_HOURS[$type] ?? self::DEFAULT_HOURS);
    }

    /**
     * The oldest CCP timestamp to store at $now. Until $since nothing older
     * than the default was stored, and the window opens out from there rather
     * than reaching back past it on the first poll.
     */
    public static function intakeCutoff(int $now, int $since): int
    {
        return min($now - self::DEFAULT_HOURS * 3600, max($since, $now - self::INTAKE_HOURS * 3600));
    }

    /**
     * Whether a handler that takes $hours gets a notification CCP sent at
     * $sentAt and Manager Core stored at $storedAt. It has to have arrived
     * within that age, and go out within twice it: one that arrived nearly that
     * old still has as long again to go out, but a queue that stalled for days
     * doesn't send it days late.
     */
    public static function isDue(int $sentAt, int $storedAt, int $now, int $since, float $hours): bool
    {
        $limit = self::limit($sentAt, $since, $hours);

        return $storedAt - $sentAt <= $limit && $now - $sentAt <= 2 * $limit;
    }

    /**
     * Why the handler doesn't get it, worded for the delivery record, or null
     * when it does.
     */
    public static function whyNotDue(int $sentAt, int $storedAt, int $now, int $since, float $hours): ?string
    {
        if (self::isDue($sentAt, $storedAt, $now, $since, $hours)) {
            return null;
        }

        $limit = self::limit($sentAt, $since, $hours);
        $why = $storedAt - $sentAt > $limit
            ? sprintf('Reached Manager Core %s h after CCP sent it, and the handler takes up to %s h.', self::hours($storedAt - $sentAt), self::hours($limit))
            : sprintf('Not handed out by %s h after CCP sent it, and the handler takes it within %s h.', self::hours($now - $sentAt), self::hours(2 * $limit));

        if ($sentAt < $since && $hours > self::DEFAULT_HOURS) {
            $why .= ' It was sent before Manager Core kept two weeks of notifications, so the ' . self::DEFAULT_HOURS . ' h default applied.';
        }

        return $why;
    }

    /** In seconds. Before $since nothing older than the default went out, and nothing does now. */
    private static function limit(int $sentAt, int $since, float $hours): int
    {
        if ($sentAt < $since) {
            $hours = min($hours, (float) self::DEFAULT_HOURS);
        }

        return (int) round($hours * 3600);
    }

    private static function hours(int $seconds): string
    {
        return rtrim(rtrim(number_format($seconds / 3600, 1, '.', ''), '0'), '.');
    }

    /**
     * The oldest CCP timestamp any handler can still be due at $now, when the
     * longest age a handler takes is $longestHours.
     */
    public static function dispatchCutoff(int $now, int $since, float $longestHours): int
    {
        return min(
            $now - 2 * self::DEFAULT_HOURS * 3600,
            max($since, $now - (int) round(2 * $longestHours * 3600))
        );
    }
}
