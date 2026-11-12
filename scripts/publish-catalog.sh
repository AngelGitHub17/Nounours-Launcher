#!/usr/bin/env bash
# Envoie les fichiers d'une release GitHub dans le catalogue NounoursLauncher.
# Variables attendues : GH_TOKEN, GITHUB_REPOSITORY, TAG, NL_API_URL, NL_CI_TOKEN, NL_SLUG
# Facultatif : NOTES (notes de version), CHANNEL (stable|beta, sinon déduit du tag)
set -euo pipefail

work=$(mktemp -d)
gh release download "$TAG" -R "$GITHUB_REPOSITORY" -D "$work"
cd "$work"

assets='[]'
add() { # add <plateforme> <type> <fichier> [signature]
  local url="https://github.com/$GITHUB_REPOSITORY/releases/download/$TAG/$3"
  assets=$(jq -c --arg p "$1" --arg k "$2" --arg u "$url" \
    --argjson s "$(stat -c%s "$3")" --arg h "$(sha256sum "$3" | cut -d' ' -f1)" --arg g "${4:-}" \
    '. + [{platform:$p, kind:$k, url:$u, size:$s, sha256:$h} + (if $g != "" then {signature:$g} else {} end)]' <<< "$assets")
}

for f in *; do
  sig=""
  if [ -f "$f.sig" ]; then sig="$(cat "$f.sig")"; fi
  case "$f" in
    *.sig|*.json) continue ;;
    # macOS : archive signée = mise à jour, .dmg = installation
    *aarch64*.app.tar.gz)                 add darwin-aarch64 updater "$f" "$sig" ;;
    *x64*.app.tar.gz|*x86_64*.app.tar.gz) add darwin-x86_64 updater "$f" "$sig" ;;
    *aarch64*.dmg|*arm64*.dmg)            add darwin-aarch64 dmg "$f" ;;
    *x64*.dmg|*x86_64*.dmg)               add darwin-x86_64 dmg "$f" ;;
    # Windows : l'installeur NSIS sert aussi de mise à jour
    *.exe)
      add windows-x86_64 installer "$f"
      if [ -n "$sig" ]; then add windows-x86_64 updater "$f" "$sig"; fi ;;
    *.msi)                                add windows-x86_64 msi "$f" ;;
    # Linux : l'AppImage signée sert aussi de mise à jour
    *.AppImage)
      add linux-x86_64 appimage "$f"
      if [ -n "$sig" ]; then add linux-x86_64 updater "$f" "$sig"; fi ;;
    *.deb)                                add linux-x86_64 deb "$f" ;;
    *.rpm)                                add linux-x86_64 rpm "$f" ;;
    *.apk)                                add android apk "$f" ;;
    *.jar)                                add server jar "$f" ;;
  esac
done

channel="${CHANNEL:-}"
if [ -z "$channel" ]; then case "$TAG" in *-*) channel=beta ;; *) channel=stable ;; esac; fi

body=$(jq -n --arg slug "$NL_SLUG" --arg v "$TAG" --arg c "$channel" --arg n "${NOTES:-}" --argjson a "$assets" \
  '{slug:$slug, version:$v, channel:$c, notes:$n, published:true, assets:$a}')
echo "Fichiers envoyés au catalogue :"; jq -r '.assets[] | "  \(.platform)  \(.kind)  \(.url | split("/") | last)"' <<< "$body"

resp=$(mktemp)
code=$(curl -sS -o "$resp" -w '%{http_code}' -X POST "$NL_API_URL?r=ci/release" \
  -H "Authorization: Bearer $NL_CI_TOKEN" -H "X-NL-Token: $NL_CI_TOKEN" \
  -H "Content-Type: application/json" -d "$body" || echo 000)
answer=$(head -c 600 "$resp" | tr '\n' ' ')
echo "Réponse du catalogue (HTTP $code) : $answer"
if [ "$code" != "200" ]; then
  echo "::error title=Catalogue NounoursLauncher::HTTP $code — $answer"
  exit 1
fi
echo "::notice title=Catalogue NounoursLauncher::$TAG inscrit — $answer"
