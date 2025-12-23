#!/bin/bash
set -e

# ============================
# ARGUMENTS
# ============================
BASE_URL="${1:-}"

if [ -z "$BASE_URL" ]; then
  echo "Usage:"
  echo "curl -fsSL https://servermonitor.pbcv.dev/install.sh | sudo bash -s -- https://servermonitor.pbcv.dev"
  exit 1
fi

BASE_URL="${BASE_URL%/}"
API_URL="$BASE_URL/api/report.php"

# ============================
# PATHS
# ============================
INSTALL_DIR="/opt/server-monitor"
AGENT="$INSTALL_DIR/agent.sh"
CRON_EXPR="* * * * *"

echo "Installing Server Monitor Agent"
echo "API: $API_URL"
echo

mkdir -p "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR"

# ============================
# WRITE AGENT
# ============================
cat > "$AGENT" <<'EOF'
#!/bin/bash
set -e

API_URL="__API_URL__"
HOSTNAME=$(hostname)

# ============================
# HARDWARE (STATIC)
# ============================

CPU_MODEL=$(awk -F: '/model name/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_VENDOR=$(awk -F: '/vendor_id/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_CORES=$(nproc)
CPU_ARCH=$(uname -m)

CPU_MAX_MHZ=$(lscpu | awk -F: '/CPU max MHz/ {print $2}' | xargs)
CPU_MIN_MHZ=$(lscpu | awk -F: '/CPU min MHz/ {print $2}' | xargs)

VIRT_TYPE=$(systemd-detect-virt 2>/dev/null || echo "unknown")

DISK_INFO=$(lsblk -ndo NAME,TYPE,ROTA,MODEL | awk '{print $1":"$2":"($3==0?"ssd":"hdd")":"$4}' | tr '\n' ';')

FS_ROOT=$(df -T / | awk 'NR==2 {print $2}')

MACHINE_ID=$(cat /etc/machine-id 2>/dev/null || echo "unknown")
BOOT_ID=$(cat /proc/sys/kernel/random/boot_id 2>/dev/null || echo "unknown")

# ============================
# METRICS (DYNAMIC)
# ============================

CPU_LOAD=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f1 | xargs)
CPU_LOAD_5=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f2 | xargs)
CPU_LOAD_15=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f3 | xargs)

RAM_TOTAL=$(free -m | awk '/Mem:/ {print $2}')
RAM_USED=$(free -m | awk '/Mem:/ {print $3}')
SWAP_TOTAL=$(free -m | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m | awk '/Swap:/ {print $3}')

DISK_TOTAL=$(df -k / | awk 'NR==2 {print $2}')
DISK_USED=$(df -k / | awk 'NR==2 {print $3}')

IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
RX_BYTES=0
TX_BYTES=0
[ -n "$IFACE" ] && RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes 2>/dev/null || echo 0)
[ -n "$IFACE" ] && TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes 2>/dev/null || echo 0)

PROC_TOTAL=$(ps ax --no-headers | wc -l)
PROC_ZOMBIE=$(ps axo stat | grep -c Z || true)

TOP_CPU=$(ps -eo comm,%cpu --sort=-%cpu | head -n 6 | tail -n 5 | awk '{print $1 ":" $2}' | tr '\n' ',')
TOP_RAM=$(ps -eo comm,%mem --sort=-%mem | head -n 6 | tail -n 5 | awk '{print $1 ":" $2}' | tr '\n' ',')

FAILED_SERVICES=$(systemctl --failed --no-legend 2>/dev/null | wc -l)
OPEN_PORTS=$(ss -4 -lntu 2>/dev/null | tail -n +2 | wc -l)

OS=$(grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '"')
KERNEL=$(uname -r)
UPTIME=$(uptime -p)

# ============================
# SEND
# ============================
curl -4 -s -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"hostname\": \"$HOSTNAME\",
    \"machine\": {
      \"machine_id\": \"$MACHINE_ID\",
      \"boot_id\": \"$BOOT_ID\",
      \"cpu_model\": \"$CPU_MODEL\",
      \"cpu_vendor\": \"$CPU_VENDOR\",
      \"cpu_cores\": $CPU_CORES,
      \"cpu_arch\": \"$CPU_ARCH\",
      \"cpu_max_mhz\": \"$CPU_MAX_MHZ\",
      \"cpu_min_mhz\": \"$CPU_MIN_MHZ\",
      \"virtualization\": \"$VIRT_TYPE\",
      \"disks\": \"$DISK_INFO\",
      \"fs_root\": \"$FS_ROOT\"
    },
    \"metrics\": {
      \"cpu\": \"$CPU_LOAD\",
      \"cpu_load_5\": \"$CPU_LOAD_5\",
      \"cpu_load_15\": \"$CPU_LOAD_15\",
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
      \"top_cpu\": \"$TOP_CPU\",
      \"top_ram\": \"$TOP_RAM\",
      \"failed_services\": $FAILED_SERVICES,
      \"open_ports\": $OPEN_PORTS,
      \"os\": \"$OS\",
      \"kernel\": \"$KERNEL\",
      \"uptime\": \"$UPTIME\"
    }
  }" >/dev/null 2>&1 || true
EOF

sed -i "s|__API_URL__|$API_URL|g" "$AGENT"
chmod +x "$AGENT"

(
  crontab -l 2>/dev/null | grep -v "$AGENT" || true
  echo "$CRON_EXPR $AGENT"
) | crontab -

echo
echo "Server Monitor installed"
echo "Agent path: $AGENT"
echo "Interval: every 1 minute"
