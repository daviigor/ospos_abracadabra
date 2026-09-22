#!/bin/bash
# Instala o auto-deploy: systemd timer (root) rodando auto-deploy.sh
# Roda como root: bash /home/abracadabra/pdv-migracao/install-autodeploy.sh
set -euo pipefail

cat > /etc/systemd/system/ospos-deploy.service <<'EOF'
[Unit]
Description=OSPOS auto-deploy (fork master -> /var/www/ospos)
After=network-online.target docker.service

[Service]
Type=oneshot
ExecStart=/home/abracadabra/pdv-migracao/auto-deploy.sh
EOF

cat > /etc/systemd/system/ospos-deploy.timer <<'EOF'
[Unit]
Description=Verifica atualizacoes do OSPOS a cada 5 min

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF

chmod +x /home/abracadabra/pdv-migracao/auto-deploy.sh
systemctl daemon-reload
systemctl enable --now ospos-deploy.timer

echo "==> instalado. status:"
systemctl list-timers ospos-deploy.timer --no-pager 2>&1 | head -4
echo "==> rodando deploy agora para sincronizar producao:"
systemctl start ospos-deploy.service
sleep 3
tail -12 /var/log/ospos-deploy.log 2>/dev/null || echo "(log vazio - rode de novo)"
