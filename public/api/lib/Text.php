<?php
/**
 * Fremdtexte in lesbaren Klartext überführen.
 *
 * WOZU: Die Meldungstexte der Verkehrsunternehmen sind für deren eigene
 * Web-Oberflächen gedacht und kommen als HTML-Fragmente - die MVG liefert
 * in 235 von 346 Meldungen Absätze, Fettungen und teils sogar
 * Markdown-Links mit. Das Frontend setzt alles über textContent, damit
 * Fremdtexte kein Markup einschleusen können; ungefiltert stehen dann die
 * Tags wörtlich auf dem Bildschirm.
 *
 * Aufbereitet wird deshalb hier, an einer Stelle für alle Quellen.
 */
final class Text
{
    /**
     * HTML-Fragment als Klartext.
     *
     * Absätze und Zeilenumbrüche bleiben als echte Umbrüche erhalten -
     * die Meldungen sind oft dreiteilig ("Was ist los", "Grund", "Was heißt
     * das für dich"), und ohne Trennung wird daraus ein Textklumpen. Die
     * Anzeige stellt sie mit `white-space: pre-line` dar.
     */
    public static function plain(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $s = $html;

        // Absatz- und Zeilenenden zu echten Umbrüchen, bevor die Tags fallen.
        $s = preg_replace('#<\s*/\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $s) ?? $s;
        $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s) ?? $s;
        // Aufzählungen bekommen einen Punkt, sonst kleben sie aneinander.
        $s = preg_replace('#<\s*li[^>]*>#i', "• ", $s) ?? $s;

        $s = strip_tags($s);

        // Markdown-Links, die manche Quellen zusätzlich einstreuen:
        // [www.bahn.de](https://www.bahn.de) -> www.bahn.de
        $s = preg_replace('#\[([^\]]*)\]\([^)]*\)#', '$1', $s) ?? $s;

        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Geschützte Leerzeichen sind nach dem Dekodieren U+00A0.
        $s = str_replace("\xc2\xa0", ' ', $s);

        // Leerraum aufräumen: Zeilen einzeln trimmen, Leerzeilen zusammen-
        // fassen, mehrfache Leerzeichen auf eines.
        $lines = array_map(
            static fn($l) => trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l),
            explode("\n", $s)
        );
        $out = [];
        foreach ($lines as $l) {
            if ($l === '' && ($out === [] || end($out) === '')) {
                continue; // keine doppelten oder führenden Leerzeilen
            }
            $out[] = $l;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }
}
