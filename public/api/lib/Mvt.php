<?php
/**
 * Mapbox Vector Tiles lesen - so weit, wie die Baustellenkarte es braucht.
 *
 * strecken-info.de liefert ihr Schienennetz als Vektorkacheln (Protocol
 * Buffers). Gebraucht werden daraus nur Linien und ihre Eigenschaften;
 * Polygone, Füllregeln und Ähnliches bleiben außen vor. Eine Bibliothek
 * dafür wäre mehr als das ganze Projekt sonst an Abhängigkeiten hat.
 *
 * Aufbau (vector_tile.proto, Version 2): Kachel -> Layer (3) -> Feature (2),
 * Schlüssel (3), Werte (4), Auflösung (5). Feature: Tags (2) als Paare von
 * Schlüssel- und Wertindex, Typ (3), Geometrie (4) als Befehlsfolge
 * MoveTo/LineTo/ClosePath mit zickzack-kodierten Deltas.
 */
final class Mvt
{
    /**
     * Alle Features aller Layer, mit Linien in Kachelkoordinaten.
     *
     * @return array<int,array{layer:string,extent:int,type:int,props:array,lines:array}>
     */
    public static function features(string $bin): array
    {
        $out = [];
        foreach (self::fields($bin) as [$f, , $layer]) {
            if ($f !== 3) {
                continue;
            }
            $name = '';
            $keys = [];
            $vals = [];
            $roh = [];
            $extent = 4096;
            foreach (self::fields($layer) as [$g, , $w]) {
                match ($g) {
                    1 => $name = $w,
                    2 => $roh[] = $w,
                    3 => $keys[] = $w,
                    4 => $vals[] = self::value($w),
                    5 => $extent = $w,
                    default => null,
                };
            }
            foreach ($roh as $fb) {
                $tags = [];
                $typ = 0;
                $cmds = [];
                foreach (self::fields($fb) as [$g, , $w]) {
                    if ($g === 2) {
                        $tags = self::packed($w);
                    } elseif ($g === 3) {
                        $typ = $w;
                    } elseif ($g === 4) {
                        $cmds = self::packed($w);
                    }
                }
                $props = [];
                for ($i = 0; $i + 1 < count($tags); $i += 2) {
                    $props[$keys[$tags[$i]] ?? ''] = $vals[$tags[$i + 1]] ?? null;
                }
                $out[] = ['layer' => $name, 'extent' => (int) $extent, 'type' => $typ,
                    'props' => $props, 'lines' => self::geometry($cmds)];
            }
        }
        return $out;
    }

    /** Kachelkoordinate -> [Breite, Länge] (Web-Mercator, XYZ-Kachelschema). */
    public static function toLatLon(int $z, int $x, int $y, float $gx, float $gy, int $extent): array
    {
        $n = 2 ** $z;
        $px = ($x + $gx / $extent) / $n;
        $py = ($y + $gy / $extent) / $n;
        return [rad2deg(atan(sinh(M_PI * (1 - 2 * $py)))), $px * 360 - 180];
    }

    /** Die Kachel, in der ein Punkt liegt. */
    public static function tileOf(int $z, float $lat, float $lon): array
    {
        $n = 2 ** $z;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $r = deg2rad($lat);
        $y = (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n);
        return [$x, $y];
    }

    /** @return iterable<array{0:int,1:int,2:int|string}> Feldnummer, Typ, Wert */
    private static function fields(string $b): iterable
    {
        $i = 0;
        $n = strlen($b);
        while ($i < $n) {
            $k = self::varint($b, $i);
            $f = $k >> 3;
            $t = $k & 7;
            if ($t === 0) {
                $v = self::varint($b, $i);
            } elseif ($t === 2) {
                $l = self::varint($b, $i);
                $v = substr($b, $i, $l);
                $i += $l;
            } elseif ($t === 5) {
                $v = substr($b, $i, 4);
                $i += 4;
            } elseif ($t === 1) {
                $v = substr($b, $i, 8);
                $i += 8;
            } else {
                return; // unbekannter Typ: lieber aufhören als Unsinn lesen
            }
            yield [$f, $t, $v];
        }
    }

    private static function varint(string $b, int &$i): int
    {
        $r = 0;
        $s = 0;
        $n = strlen($b);
        while ($i < $n) {
            $c = ord($b[$i++]);
            $r |= ($c & 0x7f) << $s;
            $s += 7;
            if ($c < 0x80) {
                break;
            }
        }
        return $r;
    }

    /** @return int[] */
    private static function packed(string $b): array
    {
        $out = [];
        $i = 0;
        $n = strlen($b);
        while ($i < $n) {
            $out[] = self::varint($b, $i);
        }
        return $out;
    }

    private static function value(string $b): mixed
    {
        foreach (self::fields($b) as [$f, , $v]) {
            return match ($f) {
                1 => $v,
                2 => unpack('g', $v)[1],
                3 => unpack('e', $v)[1],
                4, 5 => $v,
                6 => ($v >> 1) ^ -($v & 1),
                7 => (bool) $v,
                default => null,
            };
        }
        return null;
    }

    /** @return array<int,array<int,array{0:int,1:int}>> Linien in Kachelkoordinaten */
    private static function geometry(array $cmds): array
    {
        $linien = [];
        $x = 0;
        $y = 0;
        $i = 0;
        $n = count($cmds);
        $akt = -1;
        while ($i < $n) {
            $c = $cmds[$i++];
            $id = $c & 7;
            $anz = $c >> 3;
            if ($id !== 1 && $id !== 2) {
                continue; // ClosePath
            }
            for ($k = 0; $k < $anz && $i + 1 < $n + 1; $k++) {
                $dx = $cmds[$i] ?? 0;
                $dy = $cmds[$i + 1] ?? 0;
                $i += 2;
                $x += ($dx >> 1) ^ -($dx & 1);
                $y += ($dy >> 1) ^ -($dy & 1);
                if ($id === 1) {
                    $linien[] = [[$x, $y]];
                    $akt = count($linien) - 1;
                } elseif ($akt >= 0) {
                    $linien[$akt][] = [$x, $y];
                }
            }
        }
        return $linien;
    }
}
