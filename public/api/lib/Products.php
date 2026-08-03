<?php
/**
 * Verkehrsmittel-Gruppen.
 *
 * Eine Definition fuer alle drei Stellen, die sie brauchen: den HAFAS-Filter
 * (Bitmaske), den DB-Filter (Namensliste) und das Frontend (Auswahlliste).
 *
 * Die Bitwerte sind nicht geraten, sondern nachgemessen: fuer jede Gruppe
 * wurden Verbindungen abgefragt und die gelieferten prodCtx.catOut den
 * prodL.cls-Werten gegenuebergestellt.
 *
 *   Bit 0 (1)    ICE, RJ, RJX
 *   Bit 1 (2)    Schienenersatzverkehr
 *   Bit 2 (4)    EC, IC, IR
 *   Bit 3 (8)    NJ, EN, FLX
 *   Bit 4 (16)   RE, RB, R, REX
 *   Bit 5 (32)   S-Bahn
 *   Bit 6 (64)   Bus
 *   Bit 8 (256)  U-Bahn
 *   Bit 9 (512)  Tram
 *   Bit 12 (4096) WESTbahn
 *
 * Bit 7, 10 und 11 kamen in keiner Testantwort vor; sie liegen in 'other',
 * damit nichts unbeabsichtigt herausfaellt.
 */
final class Products
{
    private const GROUPS = [
        'highspeed' => [
            'label' => 'High-speed',
            'hint'  => 'ICE, railjet',
            'bits'  => 1,
            'db'    => ['ICE'],
        ],
        'longdistance' => [
            'label' => 'Fernverkehr',
            'hint'  => 'EC, IC, IR',
            'bits'  => 4,
            'db'    => ['EC_IC', 'IR'],
        ],
        'night' => [
            'label' => 'Nachtzug & FlixTrain',
            'hint'  => 'NJ, EN, FLX',
            'bits'  => 8,
            'db'    => [],
        ],
        'regional' => [
            'label' => 'Regionalverkehr',
            'hint'  => 'RE, RB, REX',
            'bits'  => 16,
            'db'    => ['REGIONAL'],
        ],
        'suburban' => [
            'label' => 'S-Bahn',
            'hint'  => '',
            'bits'  => 32,
            'db'    => ['SBAHN'],
        ],
        'subway' => [
            'label' => 'U-Bahn',
            'hint'  => '',
            'bits'  => 256,
            'db'    => ['UBAHN'],
        ],
        'tram' => [
            'label' => 'Tram',
            'hint'  => '',
            'bits'  => 512,
            'db'    => ['TRAM'],
        ],
        'bus' => [
            'label' => 'Bus',
            'hint'  => 'inkl. SEV',
            'bits'  => 64 | 2,
            'db'    => ['BUS'],
        ],
        'other' => [
            'label' => 'Sonstige',
            'hint'  => 'WESTbahn, Schiff, Rufbus',
            'bits'  => 4096 | 128 | 1024 | 2048,
            'db'    => ['SCHIFF', 'ANRUFPFLICHTIG'],
        ],
    ];

    /** Alle Gruppen aktiv. */
    public static function allIds(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * HAFAS-Bitmaske aus den gewaehlten Gruppen.
     * Leere Auswahl bedeutet "keine Einschraenkung".
     *
     * @param string[] $ids
     */
    public static function bitmask(array $ids): int
    {
        if ($ids === []) {
            $ids = self::allIds();
        }
        $mask = 0;
        foreach ($ids as $id) {
            if (isset(self::GROUPS[$id])) {
                $mask |= self::GROUPS[$id]['bits'];
            }
        }
        return $mask;
    }

    /**
     * Produktgattungen fuer die DB-Angebots-API.
     *
     * @param string[] $ids
     * @return string[]
     */
    public static function dbProducts(array $ids): array
    {
        if ($ids === []) {
            $ids = self::allIds();
        }
        $out = [];
        foreach ($ids as $id) {
            foreach (self::GROUPS[$id]['db'] ?? [] as $p) {
                $out[$p] = true;
            }
        }
        // Ganz ohne Gattung liefert die DB nichts - dann lieber alles anfragen
        // und die Filterung dem Fahrplan ueberlassen.
        if ($out === []) {
            return ['ICE', 'EC_IC', 'IR', 'REGIONAL', 'SBAHN', 'BUS', 'SCHIFF', 'UBAHN', 'TRAM', 'ANRUFPFLICHTIG'];
        }
        return array_keys($out);
    }

    /** Katalog fuer das Frontend. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::GROUPS as $id => $g) {
            $out[] = [
                'id'    => $id,
                'label' => $g['label'],
                'hint'  => $g['hint'],
            ];
        }
        return $out;
    }
}
