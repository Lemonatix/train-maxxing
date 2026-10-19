#!/usr/bin/env bash
# App-Symbol und Startbild aus einer Vorlage erzeugen.
#
#   scripts/make-icons.sh                 (Vorlage: resources/icon.png)
#   scripts/make-icons.sh anderes.png
#
# Die Vorlage ist das runde MMR-Logo der Website (512 x 512). Daraus:
#   - ic_launcher / ic_launcher_round: das Logo, wie es ist (ältere Android-
#     Versionen und Launcher ohne adaptive Symbole)
#   - ic_launcher_foreground: das Logo verkleinert in der Mitte. Adaptive
#     Symbole schneiden je nach Hersteller rund, eckig oder als Tropfen zu;
#     sicher sichtbar sind nur die inneren 66 von 108 dp. Der Hintergrund
#     dahinter ist die Randfarbe des Logos (values/ic_launcher_background.xml).
#   - splash: das Logo mittig auf dem App-Hintergrund, in allen Größen, die
#     das Capacitor-Projekt erwartet.
#
# Braucht ImageMagick (magick). Danach einfach neu bauen.
set -euo pipefail

cd "$(dirname "$0")/.."
SRC="${1:-resources/icon.png}"
RES="android/app/src/main/res"
BG="#0a0e17"        # Hintergrund der App (manifest.webmanifest)
ICON_BG="#06080c"   # Randfarbe des Logos

[ -f "$SRC" ] || { echo "Vorlage fehlt: $SRC" >&2; exit 1; }
[ -d "$RES" ] || { echo "Kein Android-Projekt - erst: npx cap add android" >&2; exit 1; }

# Dichte -> Kantenlänge Symbol / Vordergrund (108 dp)
declare -A ICON=( [mdpi]=48 [hdpi]=72 [xhdpi]=96 [xxhdpi]=144 [xxxhdpi]=192 )
declare -A FG=(   [mdpi]=108 [hdpi]=162 [xhdpi]=216 [xxhdpi]=324 [xxxhdpi]=432 )

for d in "${!ICON[@]}"; do
  s=${ICON[$d]}
  magick "$SRC" -resize "${s}x${s}" "$RES/mipmap-$d/ic_launcher.png"
  magick "$SRC" -resize "${s}x${s}" "$RES/mipmap-$d/ic_launcher_round.png"
  f=${FG[$d]}
  # 70 von 108: knapp über der sicheren Zone - das Logo ist selbst rund und
  # hat einen dunklen Rand, der beim Zuschneiden nicht fehlt.
  inner=$(( f * 70 / 108 ))
  magick -size "${f}x${f}" xc:none \( "$SRC" -resize "${inner}x${inner}" \) \
    -gravity center -composite "$RES/mipmap-$d/ic_launcher_foreground.png"
done

cat > "$RES/values/ic_launcher_background.xml" <<XML
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">$ICON_BG</color>
</resources>
XML

# Startbild: Logo mittig, ein Viertel der kürzeren Seite.
splash() {
  local w=$1 h=$2 out=$3
  local s=$(( (w < h ? w : h) / 4 ))
  magick -size "${w}x${h}" "xc:$BG" \( "$SRC" -resize "${s}x${s}" \) \
    -gravity center -composite "$out"
}
splash 480 320 "$RES/drawable/splash.png"
for spec in mdpi:320:480 hdpi:480:800 xhdpi:720:1280 xxhdpi:960:1600 xxxhdpi:1280:1920; do
  IFS=: read -r d w h <<< "$spec"
  splash "$w" "$h" "$RES/drawable-port-$d/splash.png"
  splash "$h" "$w" "$RES/drawable-land-$d/splash.png"
done

# Die Vektorgrafiken der Vorlage (Capacitor-Logo) braucht niemand mehr -
# das adaptive Symbol zeigt auf die PNGs oben.
rm -f "$RES/drawable-v24/ic_launcher_foreground.xml" "$RES/drawable/ic_launcher_background.xml"

echo "Symbole und Startbilder erzeugt aus $SRC"
