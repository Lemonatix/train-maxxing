#!/usr/bin/env bash
# App-Symbol und Startbild aus den beiden Vorlagen erzeugen.
#
#   scripts/make-icons.sh
#
# Vorlagen (108 x 108, wie ein adaptives Android-Symbol):
#   resources/icon-foreground.svg   die Zugfront auf Schienen, transparent
#   resources/icon-background.svg   der dunkle Grund mit leichtem Schein
#
# Daraus:
#   - ic_launcher_foreground / ic_launcher_background: die beiden Ebenen des
#     adaptiven Symbols (ab Android 8). Der Launcher schneidet sie rund,
#     eckig oder als Tropfen zu und darf sie dabei leicht verschieben - das
#     Motiv liegt deshalb im sicheren Kreis der Vorlage.
#   - ic_launcher / ic_launcher_round: beide Ebenen übereinander, als
#     abgerundetes Quadrat bzw. Kreis - für Android 7 (minSdk 24).
#   - splash: das Motiv mittig auf dem App-Hintergrund, in allen Größen, die
#     das Capacitor-Projekt erwartet.
#   - resources/icon-512.png: das fertige Symbol zum Verlinken, z. B. neben
#     dem Download auf der Website.
#
# Braucht rsvg-convert und ImageMagick (magick). Danach neu bauen.
set -euo pipefail

cd "$(dirname "$0")/.."
FG_SVG="resources/icon-foreground.svg"
BG_SVG="resources/icon-background.svg"
RES="android/app/src/main/res"
APP_BG="#0a0e17"    # Hintergrund der App (manifest.webmanifest)

for f in "$FG_SVG" "$BG_SVG"; do [ -f "$f" ] || { echo "Vorlage fehlt: $f" >&2; exit 1; }; done
[ -d "$RES" ] || { echo "Kein Android-Projekt - erst: npx cap add android" >&2; exit 1; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Ebenen in Größe s als PNG.
fg() { rsvg-convert -w "$1" -h "$1" "$FG_SVG" -o "$2"; }
bg() { rsvg-convert -w "$1" -h "$1" "$BG_SVG" -o "$2"; }

# Beide Ebenen übereinander, ausgeschnitten: $3 = round | square
voll() {
  local s=$1 out=$2 form=$3
  bg "$s" "$TMP/b.png"
  fg "$s" "$TMP/f.png"
  local maske
  if [ "$form" = round ]; then
    maske="circle $((s / 2)),$((s / 2)) $((s / 2)),0"
  else
    maske="roundrectangle 0,0 $((s - 1)),$((s - 1)) $((s * 22 / 100)),$((s * 22 / 100))"
  fi
  magick "$TMP/b.png" "$TMP/f.png" -composite \
    \( -size "${s}x${s}" xc:black -fill white -draw "$maske" \) \
    -alpha off -compose CopyOpacity -composite "$out"
}

# Dichte -> Kantenlänge Symbol (48 dp) / Ebenen (108 dp)
declare -A ICON=( [mdpi]=48 [hdpi]=72 [xhdpi]=96 [xxhdpi]=144 [xxxhdpi]=192 )
declare -A LAYER=( [mdpi]=108 [hdpi]=162 [xhdpi]=216 [xxhdpi]=324 [xxxhdpi]=432 )

for d in "${!ICON[@]}"; do
  voll "${ICON[$d]}" "$RES/mipmap-$d/ic_launcher.png" square
  voll "${ICON[$d]}" "$RES/mipmap-$d/ic_launcher_round.png" round
  fg "${LAYER[$d]}" "$RES/mipmap-$d/ic_launcher_foreground.png"
  bg "${LAYER[$d]}" "$RES/mipmap-$d/ic_launcher_background.png"
done

# Adaptives Symbol: Hintergrund als Bild statt als Farbe (wegen des Scheins).
for f in ic_launcher ic_launcher_round; do
  cat > "$RES/mipmap-anydpi-v26/$f.xml" <<XML
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@mipmap/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>
XML
done
rm -f "$RES/values/ic_launcher_background.xml"

# Startbild: das Motiv mittig. Die Ebene ist zwei Drittel der kürzeren
# Seite groß; der Zug darin nimmt davon gut die Hälfte ein.
splash() {
  local w=$1 h=$2 out=$3
  local s=$(( (w < h ? w : h) * 2 / 3 ))
  fg "$s" "$TMP/s.png"
  magick -size "${w}x${h}" "xc:$APP_BG" "$TMP/s.png" -gravity center -composite "$out"
}
splash 480 320 "$RES/drawable/splash.png"
for spec in mdpi:320:480 hdpi:480:800 xhdpi:720:1280 xxhdpi:960:1600 xxxhdpi:1280:1920; do
  IFS=: read -r d w h <<< "$spec"
  splash "$w" "$h" "$RES/drawable-port-$d/splash.png"
  splash "$h" "$w" "$RES/drawable-land-$d/splash.png"
done

# Vektorgrafiken der Capacitor-Vorlage, falls noch da - niemand braucht sie.
rm -f "$RES/drawable-v24/ic_launcher_foreground.xml" "$RES/drawable/ic_launcher_background.xml"

voll 512 resources/icon-512.png square

# --- Website (public/assets/icons/) -----------------------------------
# Dieselben zwei Vorlagen, damit Tab, Home-Bildschirm und App gleich
# aussehen. Relativ eingebunden (index.html, manifest.webmanifest, sw.js,
# live.js) - das Tool läuft in jedem Unterordner.
WEB="../public/assets/icons"
mkdir -p "$WEB"

# Tab-Symbol als SVG: beide Ebenen in einem abgerundeten Quadrat. Die
# Vorlagen ohne ihr äußeres <svg> ineinandergesetzt; die Verläufe heißen
# verschieden (glow, g), stören sich also nicht.
innen() { sed -e '1d' -e '$d' "$1"; }
{
  echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 108 108">'
  echo '  <!-- Erzeugt von app/scripts/make-icons.sh - nicht von Hand ändern. -->'
  echo '  <clipPath id="ecken"><rect width="108" height="108" rx="24"/></clipPath>'
  echo '  <g clip-path="url(#ecken)">'
  innen "$BG_SVG"
  innen "$FG_SVG"
  echo '  </g>'
  echo '</svg>'
} > "$WEB/favicon.svg"

voll 192 "$WEB/icon-192.png" square
voll 512 "$WEB/icon-512.png" square
voll 32 "$WEB/favicon-32.png" square

# "maskable" fürs Manifest: randlos - Android schneidet selbst zu.
bg 512 "$TMP/b.png"; fg 512 "$TMP/f.png"
magick "$TMP/b.png" "$TMP/f.png" -composite "$WEB/icon-maskable-512.png"

# iPhone-Home-Bildschirm: randlos und ohne Transparenz, iOS rundet selbst ab.
bg 180 "$TMP/b.png"; fg 180 "$TMP/f.png"
magick "$TMP/b.png" "$TMP/f.png" -composite -background "$APP_BG" -flatten "$WEB/apple-touch-icon.png"

# Benachrichtigung (Android-Statusleiste): nur der Umriss zählt, die Farbe
# setzt das System. Scheibe und Lichter müssen deshalb DURCHSICHTIG sein,
# sonst wird der Zug ein weißer Klecks.
fg 96 "$TMP/f.png"
magick "$TMP/f.png" -fuzz 12% -transparent "$APP_BG" -fill white -colorize 100 "$WEB/badge-96.png"

# Ohne Zeitstempel: ImageMagick schreibt sonst Erstell- und Änderungsdatum
# in jedes PNG, und Git sähe nach jedem Lauf Dutzende "geänderte" Bilder,
# obwohl kein Pixel anders ist.
# Alle PNGs unter res/ stammen aus diesem Skript.
{ find "$RES" -name '*.png'; printf '%s\n' resources/icon-512.png "$WEB"/*.png; } \
  | xargs -d '\n' magick mogrify -define png:exclude-chunks=date,time

echo "Symbole und Startbilder erzeugt aus $FG_SVG und $BG_SVG (App und Website)"
