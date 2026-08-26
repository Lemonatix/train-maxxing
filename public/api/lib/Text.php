<?php
/**
 * Fremdtexte in lesbaren Klartext ueberfuehren.
 *
 * WOZU: Die Meldungstexte der Verkehrsunternehmen sind fuer deren eigene
 * Web-Oberflaechen gedacht und kommen als HTML-Fragmente - die MVG liefert
 * in 235 von 346 Meldungen Absaetze, Fettungen und teils sogar
 * Markdown-Links mit. Das Frontend setzt alles ueber textContent, damit
 * Fremdtexte kein Markup einschleusen koennen; ungefiltert stehen dann die
 * Tags woertlich auf dem Bildschirm.
 *
 * Aufbereitet wird deshalb hier, an einer Stelle fuer alle Quellen.
 */
final class Text
{
    /**
     * HTML-Fragment als Klartext.
     *
     * Absaetze und Zeilenumbrueche bleiben als echte Umbrueche erhalten -
     * die Meldungen sind oft dreiteilig ("Was ist los", "Grund", "Was heisst
     * das fuer dich"), und ohne Trennung wird daraus ein Textklumpen. Die
     * Anzeige stellt sie mit `white-space: pre-line` dar.
     */
    public static function plain(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $s = $html;

        // Absatz- und Zeilenenden zu echten Umbruechen, bevor die Tags fallen.
        $s = preg_replace('#<\s*/\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $s) ?? $s;
        $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s) ?? $s;
        // Aufzaehlungen bekommen einen Punkt, sonst kleben sie aneinander.
        $s = preg_replace('#<\s*li[^>]*>#i', "• ", $s) ?? $s;

        $s = strip_tags($s);

        // Markdown-Links, die manche Quellen zusaetzlich einstreuen:
        // [www.bahn.de](https://www.bahn.de) -> www.bahn.de
        $s = preg_replace('#\[([^\]]*)\]\([^)]*\)#', '$1', $s) ?? $s;

        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Geschuetzte Leerzeichen sind nach dem Dekodieren U+00A0.
        $s = str_replace("\xc2\xa0", ' ', $s);

        // Leerraum aufraeumen: Zeilen einzeln trimmen, Leerzeilen zusammen-
        // fassen, mehrfache Leerzeichen auf eines.
        $lines = array_map(
            static fn($l) => trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l),
            explode("\n", $s)
        );
        $out = [];
        foreach ($lines as $l) {
            if ($l === '' && ($out === [] || end($out) === '')) {
                continue; // keine doppelten oder fuehrenden Leerzeilen
            }
            $out[] = $l;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }
}
