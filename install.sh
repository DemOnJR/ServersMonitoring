#!/bin/bash
# Oprește execuția imediat dacă apare orice eroare
set -e

# ============================
# VARIABILE GLOBALE
# ============================

# Directorul unde va fi instalat agentul
INSTALL_DIR="/opt/server-monitor"

# Scriptul agentului care va rula periodic
AGENT="$INSTALL_DIR/monitor-agent.sh"

# Fișierul de configurare (permisiuni restrictive)
CONFIG="$INSTALL_DIR/config.env"

# Comanda rulată din cron (fără output)
CRON_CMD="$AGENT >/dev/null 2>&1"

# Endpoint API unde se trimit datele
API_URL="https://itschool.pbcv.dev/api/report.php"

# ============================
# VERIFICARE PERMISIUNI ROOT
# ============================

# Scriptul trebuie rulat ca root (cron, /opt, system info)
# $EUID global variable
if [[ $EUID != 0 ]]; then
  echo "Run as root (sudo)"
  exit 1
fi

echo "Installing Server Monitor Agent (IPv4 only)..."

# ============================
# CREARE DIRECTOR DE INSTALARE
# ============================

# Creează directorul dacă nu există
mkdir -p "$INSTALL_DIR"

# Permisiuni standard pentru directoare de sistem
chmod 755 "$INSTALL_DIR"

# ============================
# FIȘIER CONFIGURARE
# ============================

# Stocăm variabilele configurabile separat
# (bun pentru securitate și extensibilitate)
cat > "$CONFIG" <<EOF
API_URL="$API_URL"
EOF

# Permisiuni restrictive (doar root poate citi)
chmod 600 "$CONFIG"

# ============================
# SCRIPT AGENT (RULEAZĂ DIN CRON)
# ============================

cat > "$AGENT" <<'EOF'
#!/bin/bash
set -e

# Importă configurația
source /opt/server-monitor/config.env

# Numele mașinii
HOSTNAME=$(hostname)

# ============================
# CPU
# ============================

# Load average (1 minut)
CPU_LOAD=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f1 | xargs)

# Număr de core-uri CPU
CPU_CORES=$(nproc)

# ============================
# MEMORIE RAM / SWAP (MB)
# ============================

# RAM total și utilizat
RAM_TOTAL=$(free -m | awk '/Mem:/ {print $2}')
RAM_USED=$(free -m | awk '/Mem:/ {print $3}')

# SWAP total și utilizat
SWAP_TOTAL=$(free -m | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m | awk '/Swap:/ {print $3}')

# ============================
# DISK (KB, partiția /)
# ============================

DISK_TOTAL=$(df -k / | awk 'NR==2 {print $2}')
DISK_USED=$(df -k / | awk 'NR==2 {print $3}')

# ============================
# NETWORK IPv4 ONLY
# ============================

# Detectează interfața activă IPv4
IFACE=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')

# Trafic RX / TX (bytes)
RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes)
TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes)

# ============================
# PROCESE
# ============================

# Număr total de procese
PROC_TOTAL=$(ps ax --no-headers | wc -l)

# Procese zombie (Z)
PROC_ZOMBIE=$(ps axo stat | grep -c Z || true)

# ============================
# SYSTEMD SERVICES
# ============================

# Servicii systemd eșuate
FAILED_SERVICES=$(systemctl --failed --no-legend 2>/dev/null | wc -l)

# ============================
# PORTURI DESCHISE (IPv4)
# ============================

# Porturi ascultate TCP/UDP IPv4
OPEN_PORTS=$(ss -4 -lntu | tail -n +2 | wc -l)

# ============================
# OS / KERNEL / ARHITECTURĂ
# ============================

# Nume OS (fallback dacă lsb_release nu există)
OS_NAME=$(lsb_release -ds 2>/dev/null || grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '"')

KERNEL=$(uname -r)
ARCH=$(uname -m)

# ============================
# UPTIME
# ============================

UPTIME=$(uptime -p)

# ============================
# TRIMITERE DATE CĂTRE API
# FORȚAT IPv4 (-4)
# ============================

curl -4 -s -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"hostname\": \"$HOSTNAME\",

    \"cpu\": \"$CPU_LOAD\",
    \"cpu_cores\": $CPU_CORES,

    \"ram_used\": $RAM_USED,
    \"ram_total\": $RAM_TOTAL,

    \"swap_used\": $SWAP_USED,
    \"swap_total\": $SWAP_TOTAL,

    \"disk_used\": $DISK_USED,
    \"disk_total\": $DISK_TOTAL,

    \"rx_bytes\": $RX_BYTES,
    \"tx_bytes\": $TX_BYTES,

    \"processes\": $PROC_TOTAL,
    \"zombies\": $PROC_ZOMBIE,

    \"failed_services\": $FAILED_SERVICES,
    \"open_ports\": $OPEN_PORTS,

    \"os\": \"$OS_NAME\",
    \"kernel\": \"$KERNEL\",
    \"arch\": \"$ARCH\",

    \"uptime\": \"$UPTIME\"
  }"
EOF

# Permite execuția agentului
chmod +x "$AGENT"

# ============================
# CRON JOB (FĂRĂ DUBLURI)
# ============================

(
  # Păstrează ce există deja, elimină dublurile
  crontab -l 2>/dev/null | grep -v "$AGENT" || true
  # Rulează agentul la fiecare minut
  echo "* * * * * $CRON_CMD"
) | crontab -

# ============================
# FINAL
# ============================

echo "Server Monitor installed successfully (IPv4 only)"
echo "Reporting to: $API_URL"
echo "Interval: every 1 minute"
echo "Manual test: $AGENT"
