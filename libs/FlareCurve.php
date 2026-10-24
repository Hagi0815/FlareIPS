<?php

declare(strict_types=1);

/**
 * Reine Kurvenberechnung (ohne IP-Symcon-Abhängigkeiten, daher separat testbar).
 *
 * Phasen: 0 = Morgen, 1 = Tag, 2 = Abend, 3 = Nacht
 *
 * $cfg = [
 *   'phases' => [
 *      0 => ['tod' => Sekunden seit Mitternacht, 'bri' => %, 'ct' => Kelvin, 'fade' => Sekunden],
 *      1 => [...], 2 => ['bri','ct','fade'] (tod wird aus Sonnenuntergang berechnet), 3 => [...]
 *   ],
 *   'evening'  => ['earliest' => Sek. seit Mitternacht, 'latest' => Sek., 'offset' => Sek.],
 *   'dayDrift' => bool   // Tag-Phase wandert farblich langsam Richtung Abend
 * ]
 * $sunset = callable(int $midnightTs): int  -> Sonnenuntergang (Timestamp) an diesem Tag
 *
 * "fade" einer Phase = Dauer der Überblendung VOR ihrem Beginn, damit die Werte
 * genau zum Phasenbeginn erreicht sind.
 */
class FlareCurve
{
    public const MORNING = 0;
    public const DAY = 1;
    public const EVENING = 2;
    public const NIGHT = 3;

    public const NAMES = ['Morgen', 'Tag', 'Abend', 'Nacht'];

    public static function midnight(int $ts): int
    {
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    private static function atTod(int $midnight, int $tod): int
    {
        $tod = (($tod % 86400) + 86400) % 86400;
        return mktime(intdiv($tod, 3600), intdiv($tod % 3600, 60), $tod % 60,
            (int) date('n', $midnight), (int) date('j', $midnight), (int) date('Y', $midnight));
    }

    /** Phasenbeginne (Timestamps) für den Tag, der um $midnight beginnt. */
    public static function dayTimes(array $cfg, callable $sunset, int $midnight): array
    {
        $p = $cfg['phases'];
        $morning = self::atTod($midnight, $p[self::MORNING]['tod']);
        $day = self::atTod($midnight, $p[self::DAY]['tod']);
        if ($day <= $morning) {
            $day += 86400;
        }
        $night = self::atTod($midnight, $p[self::NIGHT]['tod']);
        if ($night <= $day) {
            $night += 86400; // Nacht beginnt nach Mitternacht
        }

        $earliest = self::atTod($midnight, $cfg['evening']['earliest']);
        $latest = self::atTod($midnight, $cfg['evening']['latest']);
        if ($latest < $earliest) {
            $latest += 86400;
        }
        $evening = $sunset($midnight) + (int) $cfg['evening']['offset'];
        $evening = max($earliest, min($latest, $evening));
        // Reihenfolge Tag < Abend < Nacht absichern
        $evening = max($evening, $day + 60);
        $evening = min($evening, $night - 60);

        return [self::MORNING => $morning, self::DAY => $day, self::EVENING => $evening, self::NIGHT => $night];
    }

    private static function lerp(float $a, float $b, float $f): float
    {
        return $a + ($b - $a) * $f;
    }

    /** Farbtemperatur in Mired interpolieren (wirkt gleichmäßiger als linear in Kelvin). */
    private static function lerpKelvin(float $a, float $b, float $f): float
    {
        return 1e6 / self::lerp(1e6 / $a, 1e6 / $b, $f);
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /**
     * Zielwerte zum Zeitpunkt $now.
     * @return array{phase:int, next:int, bri:float, ct:float, fading:bool, phaseStart:int, nextStart:int, evening:int}
     */
    public static function target(array $cfg, callable $sunset, int $now): array
    {
        $today = self::midnight($now);
        $bounds = [];
        $eveningToday = 0;
        foreach ([-1, 0, 1] as $offset) {
            $mid = self::midnight($today + $offset * 86400 + 43200); // DST-sicher
            $times = self::dayTimes($cfg, $sunset, $mid);
            if ($offset === 0) {
                $eveningToday = $times[self::EVENING];
            }
            foreach ($times as $phase => $ts) {
                $bounds[] = ['ts' => $ts, 'phase' => $phase];
            }
        }
        usort($bounds, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        $cur = $bounds[0];
        $nxt = $bounds[1];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            if ($bounds[$i]['ts'] <= $now && $now < $bounds[$i + 1]['ts']) {
                $cur = $bounds[$i];
                $nxt = $bounds[$i + 1];
                break;
            }
        }

        $p = $cfg['phases'];
        $segStart = $cur['ts'];
        $segEnd = $nxt['ts'];
        $fade = (int) min(max(0, (int) $p[$nxt['phase']]['fade']), $segEnd - $segStart);
        $fadeStart = $segEnd - $fade;

        $valueAt = function (int $t) use ($cur, $p, $cfg, $segStart, $fadeStart): array {
            $base = $p[$cur['phase']];
            $bri = (float) $base['bri'];
            $ct = (float) $base['ct'];
            if ($cur['phase'] === self::DAY && !empty($cfg['dayDrift'])) {
                $prog = self::clamp01(($t - $segStart) / max(1, $fadeStart - $segStart));
                $ct = self::lerpKelvin($ct, (float) $p[self::EVENING]['ct'], $prog);
            }
            return [$bri, $ct];
        };

        $fading = false;
        if ($fade > 0 && $now >= $fadeStart) {
            [$fromBri, $fromCt] = $valueAt($fadeStart);
            $f = self::clamp01(($now - $fadeStart) / $fade);
            $bri = self::lerp($fromBri, (float) $p[$nxt['phase']]['bri'], $f);
            $ct = self::lerpKelvin($fromCt, (float) $p[$nxt['phase']]['ct'], $f);
            $fading = true;
        } else {
            [$bri, $ct] = $valueAt($now);
        }

        return [
            'phase'      => $cur['phase'],
            'next'       => $nxt['phase'],
            'bri'        => round($bri, 1),
            'ct'         => round($ct),
            'fading'     => $fading,
            'phaseStart' => $segStart,
            'nextStart'  => $segEnd,
            'evening'    => $eveningToday,
        ];
    }
}
