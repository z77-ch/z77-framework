#!/usr/bin/env bash
# z77 developer-environment verification — run in Git Bash from anywhere:
#   bash docs/01-handbook/verify-dev-env.sh
# Reports PASS / WARN / FAIL for every tool, PHP extension, SSH/gh access and
# generated artifacts. See dev-environment.md for the full checklist.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

pass=0; warn=0; fail=0
ok()  { printf "  \033[32mPASS\033[0m  %s\n" "$1"; pass=$((pass+1)); }
wn()  { printf "  \033[33mWARN\033[0m  %s\n" "$1"; warn=$((warn+1)); }
bad() { printf "  \033[31mFAIL\033[0m  %s\n" "$1"; fail=$((fail+1)); }
sec() { printf "\n\033[1m%s\033[0m\n" "$1"; }

have() { command -v "$1" >/dev/null 2>&1; }
ver()  { grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1; }   # first x.y.z from stdin
ge_ver() { printf '%s\n%s\n' "$2" "$1" | sort -V -C; }    # 0 if $1 >= $2

sec "Tools"
if have php; then
  v="$(php -r 'echo PHP_VERSION;' 2>/dev/null)"
  if ge_ver "$v" "8.2"; then ok "PHP $v (>= 8.2)"; else bad "PHP $v (< 8.2)"; fi
else bad "PHP not found on PATH"; fi

have composer && ok "Composer $(composer --version 2>/dev/null | ver)" || bad "Composer not found"

if have node; then
  v="$(node -v 2>/dev/null | tr -d 'v')"
  if ge_ver "$v" "20.0.0"; then ok "Node $v (>= 20)"; else bad "Node $v (< 20)"; fi
else bad "Node not found"; fi

have npm && ok "npm $(npm -v 2>/dev/null)" || bad "npm not found"
have git && ok "git $(git --version 2>/dev/null | ver)" || bad "git not found"
have gh  && ok "gh $(gh --version 2>/dev/null | ver)" || wn "gh not found (optional but recommended)"

sec "PHP extensions"
if have php; then
  loaded="$(php -m 2>/dev/null | tr 'A-Z' 'a-z')"
  for ext in curl apcu mbstring json openssl dom xml fileinfo gd; do
    if printf '%s\n' "$loaded" | grep -qx "$ext"; then ok "ext: $ext"; else bad "ext: $ext missing (enable in php.ini)"; fi
  done
  if printf '%s\n' "$loaded" | grep -qx apcu; then
    php -i 2>/dev/null | grep -q 'apc.enable_cli => On' && ok "apcu CLI enabled" || wn "apcu present but apc.enable_cli is Off"
  fi
else bad "PHP missing — cannot check extensions"; fi

sec "Sass (local dev-dependency)"
if [ -x "$REPO_ROOT/node_modules/.bin/sass" ]; then
  ok "sass in node_modules ($("$REPO_ROOT/node_modules/.bin/sass" --version 2>/dev/null | ver))"
else
  bad "node_modules/.bin/sass missing — run 'npm install' in $REPO_ROOT"
fi

sec "GitHub access"
if have gh && gh auth status >/dev/null 2>&1; then
  ok "gh authenticated ($(gh auth status 2>&1 | grep -oE 'account [^ ]+' | head -1))"
else wn "gh not authenticated — run 'gh auth login'"; fi
if have ssh; then
  out="$(ssh -o BatchMode=yes -o ConnectTimeout=8 -T git@github.com 2>&1)"
  if printf '%s' "$out" | grep -qi 'successfully authenticated'; then
    ok "SSH to github.com OK ($(printf '%s' "$out" | grep -oE 'Hi [^!]+' | head -1))"
  else bad "SSH to github.com failed — check ~/.ssh key + GitHub"; fi
else wn "ssh client not found"; fi

sec "Git identity (effective in this repo)"
name="$(git -C "$REPO_ROOT" config user.name 2>/dev/null)"
mail="$(git -C "$REPO_ROOT" config user.email 2>/dev/null)"
crlf="$(git -C "$REPO_ROOT" config core.autocrlf 2>/dev/null)"
[ -n "$name" ] && ok "user.name = $name" || wn "git user.name not set (git config --global user.name ...)"
[ -n "$mail" ] && ok "user.email = $mail" || wn "git user.email not set"
[ "$crlf" = "true" ] && ok "core.autocrlf = true" || wn "core.autocrlf='${crlf:-unset}' (recommend true on Windows)"

sec "Directory layout"
parent="$(dirname "$REPO_ROOT")"
sib="$(ls -d "$parent"/z77-1.0.0-* 2>/dev/null | head -1)"
[ -n "$sib" ] && ok "sibling project(s) present, e.g. $(basename "$sib") — path-repos resolve" \
              || wn "no sibling z77-1.0.0-* project next to the framework (path-repos need siblings)"

sec "Post-sync artifacts"
[ -d "$REPO_ROOT/node_modules" ] && ok "node_modules present" || wn "node_modules missing — run 'npm install'"

printf "\n\033[1mSummary:\033[0m \033[32m%d PASS\033[0m  \033[33m%d WARN\033[0m  \033[31m%d FAIL\033[0m\n" "$pass" "$warn" "$fail"
if [ "$fail" -eq 0 ]; then
  echo "Environment looks ready. Address any WARN above, then work after syncing code."; exit 0
else
  echo "Fix the FAIL items before working — see docs/01-handbook/dev-environment.md"; exit 1
fi
