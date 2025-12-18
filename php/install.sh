#!/bin/bash
set -e

# ============================
# ============================
# ROOT CHECK
# ============================
if [ "$EUID" -ne 0 ]; then
  echo "Run as root (sudo)"
  exit 1
fi

# ============================
# ARGUMENTS
# ============================
BASE_URL="${1:-}"

if [ -z "$BASE_URL" ]; then
  echo "BASE_URL missing"
  echo "Usage:"
  echo "curl -fsSL https://example.com/install.sh | sudo bash -s -- https://example.com"
  exit 1
fi

# Normalize URL
BASE_URL="${BASE_URL%/}"
API_URL="$BASE_URL/api/report.php"

# ============================
# GLOBAL VARS
# ============================
INSTALL_DIR="/opt/server-monitor"
AGENT="$INSTALL_DIR/monitor-agent.sh"
CONFIG="$INSTALL_DIR/config.env"
CRON_CMD="$AGENT >/dev/null 2>&1"

echo "Installing Server Monitor Agent"
echo "API endpoint: $API_URL"
echo

# ============================
# CLEAN OLD INSTALL
# ============================
if [ -f "$AGENT" ]; then
  echo "Removing existing agent"
  rm -f "$AGENT"
fi

if [ -f "$CONFIG" ]; then
  echo "Removing existing config"
  rm -f "$CONFIG"
fi

echo "Cleaning existing cron jobs"
crontab -l 2>/dev/null | grep -v "$INSTALL_DIR/monitor-agent.sh" | crontab - || true

# ============================
# CREATE INSTALL DIR
# ============================
mkdir -p "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR"

# ============================
# CONFIG FILE
# ============================
cat > "$CONFIG" <<EOF
API_URL="$API_URL"
EOF

chmod 600 "$CONFIG"

# ============================
# AGENT SCRIPT
# ============================
cat > "$AGENT" <<'EOF'
#!/bin/bash
set -e

source /opt/server-monitor/config.env

HOSTNAME=$(hostname)

CPU_LOAD=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f1 | xargs)
CPU_CORES=$(nproc)

RAM_TOTAL=$(free -m | awk '/Mem:/ {print $2}')
RAM_USED=$(free -m | awk '/Mem:/ {print $3}')
SWAP_TOTAL=$(free -m | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m | awk '/Swap:/ {print $3}')

DISK_TOTAL=$(df -k / | awk 'NR==2 {print $2}')
DISK_USED=$(df -k / | awk 'NR==2 {print $3}')

IFACE=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')

RX_BYTES=0
TX_BYTES=0
if [ -n "$IFACE" ] && [ -d "/sys/class/net/$IFACE" ]; then
  RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes)
  TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes)
fi

PROC_TOTAL=$(ps ax --no-headers | wc -l)
PROC_ZOMBIE=$(ps axo stat | grep -c Z || true)

FAILED_SERVICES=$(systemctl --failed --no-legend 2>/dev/null | wc -l)
OPEN_PORTS=$(ss -4 -lntu | tail -n +2 | wc -l)

OS_NAME=$(lsb_release -ds 2>/dev/null || grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '"')
KERNEL=$(uname -r)
ARCH=$(uname -m)
UPTIME=$(uptime -p)

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
  }" >/dev/null 2>&1 || true
EOF

chmod +x "$AGENT"

# ============================
# ADD CRON (EVERY MINUTE)
# ============================
(
  crontab -l 2>/dev/null | grep -v "$INSTALL_DIR/monitor-agent.sh" || true
  echo "* * * * * $CRON_CMD"
) | crontab -

echo
echo "Server Monitor Agent installed or updated successfully"
echo "Reporting to: $API_URL"
echo "Interval: every 1 minute"
echo "Manual run: $AGENT"