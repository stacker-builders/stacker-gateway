#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

mkdir -p .docker

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  HTTP_PORT="${GETFY_HTTP_PORT:-80}"
  APP_URL="${GETFY_APP_URL:-http://localhost}"
  WEBHOOK_PUBLIC="${GETFY_WEBHOOK_PUBLIC_URL:-$APP_URL}"

  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"

  cat > "$ENV_FILE" <<EOF
GETFY_DB_CONNECTION=pgsql
GETFY_DB_HOST=postgres
GETFY_DB_PORT=5432
GETFY_DB_DATABASE=getfy
GETFY_DB_USERNAME=$U
GETFY_DB_PASSWORD=$P
GETFY_APP_URL=$APP_URL
GETFY_WEBHOOK_PUBLIC_URL=$WEBHOOK_PUBLIC
GETFY_HTTP_PORT=$HTTP_PORT
GETFY_QUEUE_CONNECTION=${GETFY_QUEUE_CONNECTION:-redis}
GETFY_CACHE_STORE=${GETFY_CACHE_STORE:-redis}
GETFY_SESSION_DRIVER=${GETFY_SESSION_DRIVER:-file}
GETFY_REDIS_MAXMEMORY=${GETFY_REDIS_MAXMEMORY:-128mb}
GETFY_REDIS_MAXMEMORY_POLICY=${GETFY_REDIS_MAXMEMORY_POLICY:-allkeys-lru}
GETFY_QUEUE_WORKER_MEMORY=${GETFY_QUEUE_WORKER_MEMORY:-128}
GETFY_QUEUE_WORKER_MAX_TIME=${GETFY_QUEUE_WORKER_MAX_TIME:-3600}
GETFY_QUEUE_WORKER_MAX_JOBS=${GETFY_QUEUE_WORKER_MAX_JOBS:-1000}
GETFY_CADDY_HOST=${GETFY_CADDY_HOST:-:80}
API_INBOUND_WEBHOOKS_ASYNC=${API_INBOUND_WEBHOOKS_ASYNC:-true}
GETFY_APP_ENV=production
GETFY_APP_DEBUG=false
GETFY_COMPOSE_PROJECT_NAME=$(basename "$ROOT_DIR")
EOF
else
  # Instalação existente: só preenche se user/senha estiverem ausentes ou vazios.
  # Nunca regenera só por ainda serem o default "getfy" — o volume Postgres já foi
  # inicializado com essas credenciais; trocá-las no stack.env derruba a origem (521/522).
  NEED_U=0
  NEED_P=0
  if ! grep -Eq '^\s*GETFY_DB_USERNAME\s*=\s*\S' "$ENV_FILE"; then
    NEED_U=1
  fi
  if ! grep -Eq '^\s*GETFY_DB_PASSWORD\s*=\s*\S' "$ENV_FILE"; then
    NEED_P=1
  fi
  if [ "$NEED_U" = "1" ] || [ "$NEED_P" = "1" ]; then
    U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
    P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
    TMP="$(mktemp)"
    awk -v U="$U" -v P="$P" -v need_u="$NEED_U" -v need_p="$NEED_P" '
      BEGIN { u=0; p=0 }
      $0 ~ /^GETFY_DB_USERNAME=/ {
        if (need_u == "1") { print "GETFY_DB_USERNAME=" U; u=1; next }
        print; u=1; next
      }
      $0 ~ /^GETFY_DB_PASSWORD=/ {
        if (need_p == "1") { print "GETFY_DB_PASSWORD=" P; p=1; next }
        print; p=1; next
      }
      { print }
      END {
        if (need_u == "1" && !u) print "GETFY_DB_USERNAME=" U
        if (need_p == "1" && !p) print "GETFY_DB_PASSWORD=" P
      }
    ' "$ENV_FILE" > "$TMP"
    mv "$TMP" "$ENV_FILE"
    echo "Aviso: GETFY_DB_USERNAME/PASSWORD estavam vazios em $ENV_FILE — valores gerados." >&2
  elif grep -Eq '^\s*GETFY_DB_USERNAME\s*=\s*getfy\s*$' "$ENV_FILE" \
    || grep -Eq '^\s*GETFY_DB_PASSWORD\s*=\s*getfy\s*$' "$ENV_FILE"; then
    echo "Aviso: $ENV_FILE ainda usa GETFY_DB_USERNAME/PASSWORD default (getfy). Mantido para não quebrar o Postgres existente." >&2
  fi
fi

if [ -f "$ENV_FILE" ] && ! grep -Eq '^\s*GETFY_COMPOSE_PROJECT_NAME\s*=' "$ENV_FILE" 2>/dev/null; then
  echo "GETFY_COMPOSE_PROJECT_NAME=$(basename "$ROOT_DIR")" >> "$ENV_FILE"
fi

if [ -f "$ENV_FILE" ] && ! grep -Eq '^\s*GETFY_WEBHOOK_PUBLIC_URL\s*=' "$ENV_FILE"; then
  LINE_APP="$(grep -E '^GETFY_APP_URL=' "$ENV_FILE" 2>/dev/null | head -1 || true)"
  VAL_APP="${LINE_APP#GETFY_APP_URL=}"
  VAL_APP="${GETFY_APP_URL:-${VAL_APP:-http://localhost}}"
  echo "GETFY_WEBHOOK_PUBLIC_URL=${GETFY_WEBHOOK_PUBLIC_URL:-$VAL_APP}" >> "$ENV_FILE"
fi

# Normaliza banco para PostgreSQL em atualizações de ambientes legados.
TMP_DB="$(mktemp)"
awk '
  BEGIN { c=0; h=0; p=0 }
  $0 ~ /^GETFY_DB_CONNECTION=/ { print "GETFY_DB_CONNECTION=pgsql"; c=1; next }
  $0 ~ /^GETFY_DB_HOST=/ { print "GETFY_DB_HOST=postgres"; h=1; next }
  $0 ~ /^GETFY_DB_PORT=/ { print "GETFY_DB_PORT=5432"; p=1; next }
  { print }
  END {
    if (!c) print "GETFY_DB_CONNECTION=pgsql"
    if (!h) print "GETFY_DB_HOST=postgres"
    if (!p) print "GETFY_DB_PORT=5432"
  }
' "$ENV_FILE" > "$TMP_DB"
mv "$TMP_DB" "$ENV_FILE"

# Sempre produção (install/update e deploy Docker).
TMP_PROD="$(mktemp)"
awk '
  BEGIN { env=0; dbg=0 }
  $0 ~ /^GETFY_APP_ENV=/ { print "GETFY_APP_ENV=production"; env=1; next }
  $0 ~ /^GETFY_APP_DEBUG=/ { print "GETFY_APP_DEBUG=false"; dbg=1; next }
  { print }
  END {
    if (!env) print "GETFY_APP_ENV=production"
    if (!dbg) print "GETFY_APP_DEBUG=false"
  }
' "$ENV_FILE" > "$TMP_PROD"
mv "$TMP_PROD" "$ENV_FILE"

# stacker-agent e outros serviços usam env_file: .env na raiz do projeto
if [ ! -f .env ] || [ ! -s .env ]; then
  APP_URL_VAL="$(grep -E '^GETFY_APP_URL=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  if [ -z "$APP_URL_VAL" ]; then
    APP_URL_VAL="http://localhost"
  fi
  cat > .env <<EOF
# Host: Stacker agent + compose. O Laravel usa .env dentro do container app.
APP_URL=${APP_URL_VAL}
GETFY_APP_URL=${APP_URL_VAL}
STACKER_API_URL=https://api.stacker.builders
STACKER_AGENT_TOKEN=
STACKER_RELEASE_SIGNING_KEY=
STACKER_SUPPORT_WHATSAPP=
EOF
fi

# Compose interpola ${STACKER_AGENT_TOKEN} a partir de stack.env — sincroniza do .env raiz.
if [ -f .env ]; then
  for var in STACKER_AGENT_TOKEN STACKER_API_URL STACKER_RELEASE_SIGNING_KEY; do
    val="$(grep -E "^[[:space:]]*${var}[[:space:]]*=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)"
    if [ -n "$val" ]; then
      if grep -Eq "^[[:space:]]*${var}[[:space:]]*=" "$ENV_FILE" 2>/dev/null; then
        TMP_SYNC="$(mktemp)"
        awk -v k="$var" -v v="$val" '
          $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
          { print }
        ' "$ENV_FILE" > "$TMP_SYNC"
        mv "$TMP_SYNC" "$ENV_FILE"
      else
        echo "${var}=${val}" >> "$ENV_FILE"
      fi
    fi
  done
fi

COMPOSE_FILES="${GETFY_COMPOSE_FILES:-docker-compose.yml}"
COMPOSE_ARGS=""
OLD_IFS="$IFS"
IFS=';'
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done
IFS="$OLD_IFS"

UP_ARGS="-d --remove-orphans"
if [ "${GETFY_SKIP_DOCKER_BUILD:-0}" != "1" ]; then
  UP_ARGS="--build ${UP_ARGS}"
fi

docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" up $UP_ARGS
