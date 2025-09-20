<?php
namespace Game;

class Util {
    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    public static function dice(int $sides): int { return random_int(1, max(1,$sides)); }

    public static function clamp(int $v, int $min, int $max): int {
        return max($min, min($max, $v));
    }

    public static function slug(string $t): string {
        $t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $t));
        return trim($t, '-');
    }

    public static function now(): string { return gmdate('c'); }

    public static function pick(array $arr) {
        return $arr[array_rand($arr)];
    }

    public static function str_has_ci(string $hay, string $needle): bool {
        return mb_stripos($hay, $needle) !== false;
    }

    public static function wrapAscii(string $text, int $width = 76): string {
        $out = '';
        $words = preg_split('/\s+/', $text);
        $line = '';
        foreach ($words as $w) {
            if (mb_strlen($line . ' ' . $w) > $width) {
                $out .= rtrim($line) . PHP_EOL;
                $line = $w . ' ';
            } else {
                $line .= $w . ' ';
            }
        }
        return $out . rtrim($line);
    }
}
