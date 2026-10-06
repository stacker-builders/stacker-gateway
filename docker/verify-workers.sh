#!/usr/bin/env sh
# Verifica workers e filas após install/update.
# Uso: cd /opt/getfy && sh docker/verify-workers.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado." >&2
  exit 1
fi

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
set -a
# shellcheck disable=SC1091
. "$ENV_FILE"
set +a

COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"
COMPOSE_ARGS="-f $COMPOSE_FILE --env-file $ENV_FILE"

PROFILE=""
if [ -f .docker/compose-profile ]; then
  PROFILE="$(tr -d ' \t\r\n' < .docker/compose-profile)"
fi
if [ -z "$PROFILE" ]; then
  case "$COMPOSE_FILE" in
    *caddy*) PROFILE="caddy" ;;
    *no-redis*) PROFILE="no-redis" ;;
    *) PROFILE="standard" ;;
  esac
fi

QUEUE_CONN="${GETFY_QUEUE_CONNECTION:-redis}"
WARNINGS=0
ERRORS=0

warn() {
  echo "AVISO: $1" >&2
  WARNINGS=$((WARNINGS + 1))
}

fail() {
  echo "ERRO: $1" >&2
  ERRORS=$((ERRORS + 1))
}

echo "=== Verificação de workers (perfil: $PROFILE) ==="
echo "Compose: $COMPOSE_FILE"
echo "QUEUE_CONNECTION: $QUEUE_CONN"
echo ""

if [ "$QUEUE_CONN" != "redis" ]; then
  warn "GETFY_QUEUE_CONNECTION não é redis — API PIX async e webhooks não são suportados oficialmente em produção. Use install.sh ou install-caddy.sh."
fi

echo "=== Containers ==="
docker compose $COMPOSE_ARGS ps -a 2>/dev/null || true
echo ""

is_running() {
  docker compose $COMPOSE_ARGS ps --status running --services 2>/dev/null | grep -qx "$1"
}

case "$PROFILE" in
  standard)
    for svc in app postgres redis scheduler worker-payments worker-webhooks-out worker-webhooks-in worker-payouts worker-uazapi; do
      if is_running "$svc"; then
        echo "OK: $svc em execução"
      else
        fail "serviço $svc não está em execução"
      fi
    done
    ;;
  caddy)
    for svc in caddy app postgres redis queue; do
      if is_running "$svc"; then
        echo "OK: $svc em execução"
      else
        fail "serviço $svc não está em execução"
      fi
    done
    ;;
  no-redis)
    for svc in app postgres queue; do
      if is_running "$svc"; then
        echo "OK: $svc em execução"
      else
        fail "serviço $svc não está em execução"
      fi
    done
    ;;
  *)
    warn "perfil desconhecido ($PROFILE); verificando app e queue/workers"
    is_running app || fail "app não está em execução"
    ;;
esac

echo ""
echo "=== Profundidade das filas ==="

CRITICAL_QUEUES="payments webhooks-outbound webhooks-inbound webhooks payouts"
STUCK_TOTAL=0

if [ "$QUEUE_CONN" = "redis" ] && is_running redis; then
  for q in $CRITICAL_QUEUES; do
    DEPTH="$(docker compose $COMPOSE_ARGS exec -T redis redis-cli LLEN "queues:$q" 2>/dev/null | tr -d '\r\n' || echo "?")"
    if [ "$DEPTH" = "?" ]; then
      warn "não foi possível ler fila redis:$q"
    elif [ "$DEPTH" -gt 0 ] 2>/dev/null; then
      echo "FILA $q: $DEPTH job(s) pendente(s)"
      STUCK_TOTAL=$((STUCK_TOTAL + DEPTH))
    else
      echo "OK: $q vazia"
    fi
  done
elif [ "$PROFILE" = "no-redis" ] || [ "$QUEUE_CONN" = "database" ]; then
  if is_running app; then
    for q in $CRITICAL_QUEUES; do
      DEPTH="$(docker compose $COMPOSE_ARGS exec -T app php artisan tinker --execute="echo (int) \\Illuminate\\Support\\Facades\\DB::table('jobs')->where('queue', '$q')->count();" 2>/dev/null | tr -d '\r\n' || echo "?")"
      if [ "$DEPTH" = "?" ]; then
        warn "não foi possível ler fila database:$q"
      elif [ "$DEPTH" -gt 0 ] 2>/dev/null; then
        echo "FILA $q: $DEPTH job(s) pendente(s)"
        STUCK_TOTAL=$((STUCK_TOTAL + DEPTH))
      else
        echo "OK: $q vazia"
      fi
    done
  else
    warn "app não está rodando — não foi possível checar filas database"
  fi
else
  warn "redis não está em execução — não foi possível checar filas"
fi

if [ "$STUCK_TOTAL" -gt 0 ]; then
  fail "$STUCK_TOTAL job(s) preso(s) nas filas críticas — verifique logs dos workers (docker compose logs worker-payments queue --tail 50)"
fi

echo ""
if [ "$ERRORS" -gt 0 ]; then
  echo "Verificação FALHOU ($ERRORS erro(s), $WARNINGS aviso(s))." >&2
  exit 1
fi

if [ "$WARNINGS" -gt 0 ]; then
  echo "Verificação OK com $WARNINGS aviso(s)."
  exit 0
fi

echo "Verificação OK — workers e filas da API prontos."
exit 0
