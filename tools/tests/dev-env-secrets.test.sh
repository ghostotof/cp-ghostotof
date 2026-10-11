#!/usr/bin/env bash
#
# dev-env-secrets.test.sh
# ---------------------------------------------------------------------------
# Test hors ligne de docker/php/dev-env-secrets.sh (issue #481), sur des
# répertoires backend temporaires.
#
# Ce qui est pincé : un APP_SECRET aléatoire est posé, distinct, dans
# .env.local et dans .env.test.local s'il y manque ou s'il est vide ; un
# secret déjà là est gardé ; JWT_PASSPHRASE n'est jamais touché — une valeur
# vide y est légitime, la paire de clés locale étant générée sans passphrase
# (constaté en écrivant ce script : le traiter comme manquant cassait les
# tests JWT) ; un second passage ne change rien ; aucune valeur n'apparaît
# dans la sortie ; un .env.local absent est refusé.
#
# Usage :  tools/tests/dev-env-secrets.test.sh
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/docker/php/dev-env-secrets.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
pass()  { printf '  ok   %s\n' "$1"; }
fail()  { printf '  FAIL %s\n       %s\n' "$1" "$2"; failures=$((failures + 1)); }

value() { sed -n "s/^$2=//p" "$1" | tail -n 1; }

echo "dev-env-secrets.sh"

# --- Checkout neuf -----------------------------------------------------------
fresh="$TMP/fresh"; mkdir -p "$fresh"
printf 'APP_ENV=dev\nDATABASE_URL="postgresql://x"\n' > "$fresh/.env.local"
printf 'DATABASE_URL="postgresql://x"\nJWT_PASSPHRASE=\n' > "$fresh/.env.test.local"
if "$SCRIPT" "$fresh" > "$fresh/out" 2>&1; then pass "un checkout neuf est complété"; else fail "un checkout neuf est complété" "$(cat "$fresh/out")"; fi

dev_secret="$(value "$fresh/.env.local" APP_SECRET)"
test_secret="$(value "$fresh/.env.test.local" APP_SECRET)"
if [[ "$dev_secret" =~ ^[0-9a-f]{64}$ ]] && [[ "$test_secret" =~ ^[0-9a-f]{64}$ ]] && [ "$dev_secret" != "$test_secret" ]; then
  pass "APP_SECRET aléatoire de 32 octets, distinct en dev et en test"
else
  fail "APP_SECRET aléatoire de 32 octets, distinct en dev et en test" "dev=${#dev_secret} car., test=${#test_secret} car."
fi
if [ -z "$dev_secret" ] || [ -z "$test_secret" ]; then
  fail "aucune valeur dans la sortie" "un secret manque, rien à chercher"
elif grep -qF -e "$dev_secret" -e "$test_secret" "$fresh/out"; then
  fail "aucune valeur dans la sortie" "un secret généré est imprimé"
else
  pass "aucune valeur dans la sortie"
fi
if grep -q '^DATABASE_URL="postgresql://x"$' "$fresh/.env.local" && grep -qx 'JWT_PASSPHRASE=' "$fresh/.env.test.local" && ! grep -q '^JWT_PASSPHRASE' "$fresh/.env.local"; then
  pass "les autres lignes sont gardées, JWT_PASSPHRASE vide compris, et rien n'est ajouté"
else
  fail "les autres lignes sont gardées, JWT_PASSPHRASE vide compris, et rien n'est ajouté" "$(grep -n JWT "$fresh/.env.local" "$fresh/.env.test.local")"
fi

before="$(cat "$fresh/.env.local" "$fresh/.env.test.local" | sha256sum)"
"$SCRIPT" "$fresh" > "$fresh/out2" 2>&1
if [ "$before" = "$(cat "$fresh/.env.local" "$fresh/.env.test.local" | sha256sum)" ] && [ ! -s "$fresh/out2" ]; then
  pass "un second passage ne change rien et ne dit rien"
else
  fail "un second passage ne change rien et ne dit rien" "$(cat "$fresh/out2")"
fi

# --- Checkout existant -------------------------------------------------------
existing="$TMP/existing"; mkdir -p "$existing"
printf 'APP_ENV=dev\nAPP_SECRET=\n' > "$existing/.env.local"
printf 'APP_SECRET=deja-la-test\n' > "$existing/.env.test.local"
"$SCRIPT" "$existing" > "$existing/out" 2>&1
if [[ "$(value "$existing/.env.local" APP_SECRET)" =~ ^[0-9a-f]{64}$ ]] && [ "$(grep -c '^APP_SECRET=' "$existing/.env.local")" = 1 ]; then
  pass "un APP_SECRET vide est remplacé, sans doublon"
else
  fail "un APP_SECRET vide est remplacé, sans doublon" "$(grep -c '^APP_SECRET=' "$existing/.env.local") ligne(s)"
fi
if [ "$(value "$existing/.env.test.local" APP_SECRET)" = "deja-la-test" ]; then pass "un secret déjà là est gardé"; else fail "un secret déjà là est gardé" "remplacé"; fi

# --- Une valeur vide écrite autrement que `APP_SECRET=` ----------------------
# Dotenv lit `""`, `''` et des espaces comme vides : le script aussi
# (relecture de #481). Une ligne `export` compte, et n'est pas doublée.
for form in 'APP_SECRET=""' "APP_SECRET=''" 'APP_SECRET=   ' 'export APP_SECRET=""'; do
  quoted="$TMP/quoted-$RANDOM"; mkdir -p "$quoted"
  printf 'APP_ENV=dev\n%s\n' "$form" > "$quoted/.env.local"
  printf 'APP_SECRET=deja-la-test\n' > "$quoted/.env.test.local"
  "$SCRIPT" "$quoted" > "$quoted/out" 2>&1
  if [[ "$(value "$quoted/.env.local" APP_SECRET)" =~ ^[0-9a-f]{64}$ ]] && [ "$(grep -c 'APP_SECRET=' "$quoted/.env.local")" = 1 ]; then
    pass "« $form » est lu comme vide, et remplacé par une seule ligne"
  else
    fail "« $form » est lu comme vide, et remplacé par une seule ligne" "$(grep -c 'APP_SECRET=' "$quoted/.env.local") ligne(s)"
  fi
done

# --- Un secret entre guillemets est gardé tel quel ---------------------------
kept="$TMP/kept"; mkdir -p "$kept"
printf 'APP_SECRET="un-secret-local"\n' > "$kept/.env.local"
printf 'APP_SECRET=deja-la-test\n' > "$kept/.env.test.local"
"$SCRIPT" "$kept" > "$kept/out" 2>&1
if grep -qx 'APP_SECRET="un-secret-local"' "$kept/.env.local" && [ ! -s "$kept/out" ]; then pass "un secret entre guillemets est gardé"; else fail "un secret entre guillemets est gardé" "$(cat "$kept/out")"; fi

# --- Aucun fichier temporaire dans le répertoire du backend ------------------
if find "$fresh" "$existing" "$kept" -mindepth 1 ! -name '.env.local' ! -name '.env.test.local' ! -name 'out*' | grep -q .; then
  fail "aucun fichier temporaire laissé à côté des .env" "$(ls -A "$fresh" "$existing" "$kept")"
else
  pass "aucun fichier temporaire laissé à côté des .env"
fi

# --- .env.test.local absent : init d'abord, plutôt qu'un fichier incomplet ---
notest="$TMP/notest"; mkdir -p "$notest"
printf 'APP_ENV=dev\n' > "$notest/.env.local"
if "$SCRIPT" "$notest" > "$notest/out" 2>&1 || [ -e "$notest/.env.test.local" ]; then
  fail ".env.test.local absent est refusé, sans être créé" "$(cat "$notest/out")"
else
  pass ".env.test.local absent est refusé, sans être créé"
fi

# --- Pas de .env.local : init d'abord ----------------------------------------
missing="$TMP/missing"; mkdir -p "$missing"
if "$SCRIPT" "$missing" > "$missing/out" 2>&1; then fail "un .env.local absent est refusé" "sorti en 0"; else pass "un .env.local absent est refusé"; fi

if [ "$failures" -ne 0 ]; then
  echo "$failures échec(s)."
  exit 1
fi
echo "Tout est vert."
