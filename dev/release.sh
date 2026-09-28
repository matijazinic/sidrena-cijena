#!/usr/bin/env bash
# Objava nove verzije na GitHubu; instalirani pluginovi je vide kao ažuriranje u WP adminu.
#
# Prije pokretanja: podigni verziju na 3 mjesta (zaglavlje Version, SCJ_VERSION, readme Stable tag),
# upiši changelog u readme.txt i commitaj.
#
#   dev/release.sh
set -euo pipefail
cd "$(dirname "$0")/.."

[[ -z "$(git status --porcelain)" ]] || { echo "GREŠKA: ima necommitanih promjena."; exit 1; }
php tests/test-core.php | tail -1 | grep -q "SVE PROŠLO" || { echo "GREŠKA: testovi ne prolaze."; exit 1; }

php dev/build.php
VERSION=$(php -r 'echo json_decode(file_get_contents("build/info.json"))->version;')
TAG="v$VERSION"

git rev-parse "$TAG" >/dev/null 2>&1 && { echo "GREŠKA: oznaka $TAG već postoji."; exit 1; }

# Bilješke izdanja = changelog te verzije iz readme.txt.
NOTES=$(awk -v v="= $VERSION =" '$0==v{f=1;next} /^= /{f=0} f' readme.txt)

git tag -a "$TAG" -m "Verzija $VERSION"
git push origin HEAD "$TAG"
gh release create "$TAG" build/sidrena-cijena.zip build/info.json --title "$VERSION" --notes "$NOTES"
echo "Objavljeno: $TAG. Stranice će ažuriranje vidjeti unutar 12 sati (ili odmah: Nadzorna ploča -> Ažuriranja -> Provjeri ponovno)."
