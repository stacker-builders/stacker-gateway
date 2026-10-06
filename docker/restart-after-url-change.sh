#!/usr/bin/env sh
# Reinicia containers apos mudanca da URL publica.
# Usa docker restart (nao recreate) — compativel com Docker Desktop/Windows.
# Nao reinicia o stacker-agent (ele mesmo executa este script; a URL e lida de .docker/app.url).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

log() {
  printf "%s\n" "$*"
}

mkdir -p "$ROOT_DIR/.docker"
ENV_FILE="$ROOT_DIR/.docker/stack.env"

url=""
if [ -f "$ROOT_DIR/.docker/app.url" ]; then
  url="$(tr -d ' \t\r\n' < "$ROOT_DIR/.docker/app.url")"
fi
if [ -z "$url" ] && [ -f "$ROOT_DIR/.env" ]; then
  line="$(grep -E '^APP_URL=' "$ROOT_DIR/.env" | head -n1 || true)"
  url="$(printf '%s' "$line" | cut -d= -f2- | tr -d '\"' | tr -d "'")"
fi
if [ -n "$url" ]; then
  if [ ! -f "$ENV_FILE" ]; then
    printf 'GETFY_APP_URL=%s\nGETFY_WEBHOOK_PUBLIC_URL=%s\nAPP_URL=%s\n' "$url" "$url" "$url" > "$ENV_FILE"
  else
    for key in GETFY_APP_URL GETFY_WEBHOOK_PUBLIC_URL APP_URL; do
      if grep -Eq "^${key}=" "$ENV_FILE"; then
        tmp="$(mktemp)"
        awk -v k="$key" -v v="$url" 'BEGIN{FS=OFS="="} $1==k {$0=k"="v} {print}' "$ENV_FILE" > "$tmp"
        mv "$tmp" "$ENV_FILE"
      else
        printf '%s=%s\n' "$key" "$url" >> "$ENV_FILE"
      fi
    done
  fi
fi

PROJECT=""
if [ -n "${GETFY_COMPOSE_PROJECT_NAME:-}" ]; then
  PROJECT="$GETFY_COMPOSE_PROJECT_NAME"
elif [ -f "$ENV_FILE" ]; then
  pline="$(grep -E '^GETFY_COMPOSE_PROJECT_NAME=' "$ENV_FILE" | tail -1 || true)"
  PROJECT="$(printf '%s' "$pline" | cut -d= -f2- | tr -d '\"' | tr -d "'")"
fi
if [ -z "$PROJECT" ] || [ "$PROJECT" = "gateway" ]; then
  PROJECT="$(docker ps --format '{{.Names}}' 2>/dev/null | grep 'app-1$' | grep -v '^gateway-' | head -1 | sed 's/-app-1$//' || true)"
fi
if [ -z "$PROJECT" ]; then
  PROJECT="$(docker ps --format '{{.Names}}' 2>/dev/null | grep 'stacker-agent' | head -1 | sed 's/-stacker-agent.*//' || true)"
fi
if [ -z "$PROJECT" ]; then
  PROJECT="getfy"
fi

log "Projeto compose: $PROJECT"

# Sem stacker-agent: o processo atual morreria no meio e nao gravaria status completed.
TARGETS="app scheduler worker queue worker-webhooks-in worker-payments worker-webhooks-out worker-payouts worker-meta-tracking worker-utmify-tracking worker-integrax-sms worker-uazapi caddy"

RESTART_IDS=""
for svc in $TARGETS; do
  ids="$(docker ps -q --filter "name=${PROJECT}-${svc}" 2>/dev/null || true)"
  if [ -z "$ids" ]; then
    ids="$(docker ps -q --filter "label=com.docker.compose.project=${PROJECT}" --filter "label=com.docker.compose.service=${svc}" 2>/dev/null || true)"
  fi
  if [ -n "$ids" ]; then
    RESTART_IDS="$RESTART_IDS $ids"
    log "Marcado: $svc"
  fi
done

RESTART_IDS="$(printf '%s\n' $RESTART_IDS | awk 'NF' | sort -u | tr '\n' ' ')"
RESTART_IDS="$(echo "$RESTART_IDS" | sed 's/^ //;s/ $//')"

if [ -z "$RESTART_IDS" ]; then
  log "Nenhum container encontrado para reiniciar (projeto=$PROJECT)."
  exit 1
fi

log "Reiniciando: $RESTART_IDS"
# shellcheck disable=SC2086
docker restart $RESTART_IDS
log "Reinicio concluido."