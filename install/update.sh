#!/usr/bin/env bash
# Atualiza uma instalação do RNV Sync para a versão mais recente.
# Update an existing RNV Sync install to the latest version.
#
# Uso: bash ~/.local/share/rnv-sync/install/update.sh
# Não precisa de root. Seus arquivos sincronizados não são tocados.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "${APP_DIR}"

say() { printf '\033[1;36m==>\033[0m %s\n' "$1"; }

if [ ! -d .git ]; then
  echo "Esta instalação não é um clone git — reinstale com install.sh."
  exit 1
fi

say "Baixando a versão mais recente (git pull)"
git pull --ff-only

say "Atualizando dependências PHP"
composer install --no-dev --no-interaction --prefer-dist

if command -v npm >/dev/null 2>&1; then
  say "Recompilando a interface"
  npm ci && npm run build
fi

say "Aplicando migrações do banco"
php artisan migrate --force

say "Limpando caches"
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear  >/dev/null 2>&1 || true
php artisan view:clear   >/dev/null 2>&1 || true

# Reaplica integrações (extensão do gerenciador, bandeja) caso tenham
# mudado nesta versão. Best-effort: nunca aborta o update.
say "Atualizando integrações de desktop"
bash install/nautilus/install.sh >/dev/null 2>&1 || true
bash install/tray/install.sh     >/dev/null 2>&1 || true

say "Reiniciando os serviços em segundo plano"
systemctl --user daemon-reload 2>/dev/null || true
for u in rnv-sync-web rnv-sync-queue rnv-sync-scheduler rnv-sync-reverb rnv-sync-watch; do
  systemctl --user restart "${u}.service" 2>/dev/null || true
done

echo
say "Atualizado para: $(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)"
echo "Pronto. Abra http://localhost:8080"
