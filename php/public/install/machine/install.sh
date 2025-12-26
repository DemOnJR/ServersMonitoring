#!/bin/bash
set -e

BASE_URL="__BASE_URL__"
API_URL="__API_URL__"

INSTALL_DIR="/opt/server-monitor"
AGENT="$INSTALL_DIR/agent.sh"
AGENT_ID_FILE="$INSTALL_DIR/agent_id"
CRON_EXPR="* * * * *"

echo "Installing Server Monitor Agent"
echo "API: $API_URL"
echo "Install dir: $INSTALL_DIR"
echo

mkdir -p "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR"

echo "__TOKEN__" > "$AGENT_ID_FILE"
chmod 600 "$AGENT_ID_FILE"

cat > "$AGENT" <<'EOF'
#!/bin/bash
set -e

API_URL="__API_URL__"
HOSTNAME=$(hostname)

INSTALL_DIR="/opt/server-monitor"
AGENT_ID_FILE="$INSTALL_DIR/agent_id"
AGENT_TOKEN=$(cat "$AGENT_ID_FILE" 2>/dev/null || echo "")

if [ -z "$AGENT_TOKEN" ]; then
  exit 0
fi

has_cmd() { command -v "$1" >/dev/null 2>&1; }

json_escape() {
  echo -n "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r/\\r/g' -e 's/\n/\\n/g'
}

CPU_MODEL=$(awk -F: '/model name/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_VENDOR=$(awk -F: '/vendor_id/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_CORES=$(nproc 2>/dev/null || echo 0)
CPU_ARCH=$(uname -m)

CPU_MAX_MHZ=$(lscpu 2>/dev/null | awk -F: '/CPU max MHz/ {print $2}' | xargs)
CPU_MIN_MHZ=$(lscpu 2>/dev/null | awk -F: '/CPU min MHz/ {print $2}' | xargs)

VIRT_TYPE=$(systemd-detect-virt 2>/dev/null || echo "unknown")

MACHINE_ID=$(cat /etc/machine-id 2>/dev/null || echo "unknown")
BOOT_ID=$(cat /proc/sys/kernel/random/boot_id 2>/dev/null || echo "unknown")

FS_ROOT=$(df -T / 2>/dev/null | awk 'NR==2 {print $2}')

DMI_UUID=$(cat /sys/class/dmi/id/product_uuid 2>/dev/null || echo "")
DMI_SERIAL=$(cat /sys/class/dmi/id/product_serial 2>/dev/null || echo "")
BOARD_SERIAL=$(cat /sys/class/dmi/id/board_serial 2>/dev/null || echo "")

MACS=$(ip -o link 2>/dev/null | awk '/link\/ether/ {print $17}' | sort | tr '\n' ',' | sed 's/,$//')

DISK_INFO=$(lsblk -ndo NAME,TYPE,ROTA,MODEL 2>/dev/null | awk '{print $1":"$2":"($3==0?"ssd":"hdd")":"$4}' | tr '\n' ';')

DISKS_JSON="[]"
if has_cmd lsblk; then
  if has_cmd jq; then
    DISKS_JSON=$(lsblk -J -o NAME,TYPE,ROTA,MODEL,SIZE 2>/dev/null | jq -c '
      (.blockdevices // [])
      | map(select(.type=="disk"))
      | map({
          name: (.name // ""),
          size: (.size // ""),
          media: (if ((.rota|tostring)=="0") then "ssd" else "hdd" end),
          model: (.model // "")
        })
    ' 2>/dev/null || echo "[]")
  else
    DISKS_JSON=$(lsblk -ndo NAME,SIZE,TYPE,ROTA,MODEL 2>/dev/null | awk '
      BEGIN{first=1; print "["}
      $3=="disk"{
        name=$1; size=$2; rota=$4; model=$5;
        media=(rota==0?"ssd":"hdd");
        gsub(/\\/,"\\\\",model); gsub(/"/,"\\\"",model);
        if(!first) printf ",";
        printf "{\"name\":\"%s\",\"size\":\"%s\",\"media\":\"%s\",\"model\":\"%s\"}", name, size, media, model;
        first=0;
      }
      END{print "]"}
    ' 2>/dev/null || echo "[]")
  fi
fi

FILESYSTEMS_JSON="[]"
if has_cmd df; then
  if has_cmd jq; then
    FILESYSTEMS_JSON=$(df -kP -T 2>/dev/null | tail -n +2 | awk '
      {printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",$1,$2,$3,$4,$5,$6,$7}
    ' | jq -R -s -c '
      split("\n")[:-1]
      | map(split("\t"))
      | map({
          filesystem: .[0],
          fstype: .[1],
          total_kb: (.[2] | tonumber),
          used_kb: (.[3] | tonumber),
          avail_kb: (.[4] | tonumber),
          used_percent: (.[5] | sub("%";"") | tonumber),
          mount: .[6]
        })
    ' 2>/dev/null || echo "[]")
  else
    FILESYSTEMS_JSON=$(df -kP -T 2>/dev/null | tail -n +2 | awk '
      BEGIN{first=1; print "["}
      {
        fs=$1; fstype=$2; total=$3; used=$4; avail=$5; pct=$6; mnt=$7;
        gsub(/%/,"",pct);
        gsub(/\\/,"\\\\",fs); gsub(/"/,"\\\"",fs);
        gsub(/\\/,"\\\\",fstype); gsub(/"/,"\\\"",fstype);
        gsub(/\\/,"\\\\",mnt); gsub(/"/,"\\\"",mnt);
        if(!first) printf ",";
        printf "{\"filesystem\":\"%s\",\"fstype\":\"%s\",\"total_kb\":%d,\"used_kb\":%d,\"avail_kb\":%d,\"used_percent\":%d,\"mount\":\"%s\"}",
          fs,fstype,total,used,avail,pct,mnt;
        first=0;
      }
      END{print "]"}
    ' 2>/dev/null || echo "[]")
  fi
fi

CPU_LOAD=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f1 | xargs)
CPU_LOAD_5=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f2 | xargs)
CPU_LOAD_15=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f3 | xargs)

RAM_TOTAL=$(free -m 2>/dev/null | awk '/Mem:/ {print $2}')
RAM_USED=$(free -m 2>/dev/null | awk '/Mem:/ {print $3}')
SWAP_TOTAL=$(free -m 2>/dev/null | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m 2>/dev/null | awk '/Swap:/ {print $3}')

DISK_TOTAL=$(df -k / 2>/dev/null | awk 'NR==2 {print $2}')
DISK_USED=$(df -k / 2>/dev/null | awk 'NR==2 {print $3}')

IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
RX_BYTES=0
TX_BYTES=0
[ -n "$IFACE" ] && RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes 2>/dev/null || echo 0)
[ -n "$IFACE" ] && TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes 2>/dev/null || echo 0)

PROC_TOTAL=$(ps ax --no-headers 2>/dev/null | wc -l | xargs)
PROC_ZOMBIE=$(ps axo stat 2>/dev/null | grep -c Z || true)

FAILED_SERVICES=$(systemctl --failed --no-legend 2>/dev/null | wc -l | xargs)
OPEN_PORTS=$(ss -4 -lntu 2>/dev/null | tail -n +2 | wc -l | xargs)

OS=$(grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')
KERNEL=$(uname -r)
UPTIME=$(uptime -p 2>/dev/null || echo "")

PUBLIC_IP=""
if has_cmd curl; then
  PUBLIC_IP=$(curl -4 -s --max-time 2 https://api.ipify.org 2>/dev/null || echo "")
fi

ESC_HOSTNAME=$(json_escape "$HOSTNAME")
ESC_CPU_MODEL=$(json_escape "$CPU_MODEL")
ESC_CPU_VENDOR=$(json_escape "$CPU_VENDOR")
ESC_CPU_ARCH=$(json_escape "$CPU_ARCH")
ESC_CPU_MAX_MHZ=$(json_escape "$CPU_MAX_MHZ")
ESC_CPU_MIN_MHZ=$(json_escape "$CPU_MIN_MHZ")
ESC_VIRT_TYPE=$(json_escape "$VIRT_TYPE")
ESC_DISK_INFO=$(json_escape "$DISK_INFO")
ESC_FS_ROOT=$(json_escape "$FS_ROOT")
ESC_MACHINE_ID=$(json_escape "$MACHINE_ID")
ESC_BOOT_ID=$(json_escape "$BOOT_ID")
ESC_OS=$(json_escape "$OS")
ESC_KERNEL=$(json_escape "$KERNEL")
ESC_UPTIME=$(json_escape "$UPTIME")
ESC_PUBLIC_IP=$(json_escape "$PUBLIC_IP")
ESC_DMI_UUID=$(json_escape "$DMI_UUID")
ESC_DMI_SERIAL=$(json_escape "$DMI_SERIAL")
ESC_BOARD_SERIAL=$(json_escape "$BOARD_SERIAL")
ESC_MACS=$(json_escape "$MACS")

curl -4 -s -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -H "X-Agent-Token: $AGENT_TOKEN" \
  -d "{
    \"hostname\": \"$ESC_HOSTNAME\",
    \"agent\": { \"token\": \"$AGENT_TOKEN\" },
    \"machine\": {
      \"machine_id\": \"$ESC_MACHINE_ID\",
      \"boot_id\": \"$ESC_BOOT_ID\",
      \"cpu_model\": \"$ESC_CPU_MODEL\",
      \"cpu_vendor\": \"$ESC_CPU_VENDOR\",
      \"cpu_cores\": ${CPU_CORES:-0},
      \"cpu_arch\": \"$ESC_CPU_ARCH\",
      \"cpu_max_mhz\": \"$ESC_CPU_MAX_MHZ\",
      \"cpu_min_mhz\": \"$ESC_CPU_MIN_MHZ\",
      \"virtualization\": \"$ESC_VIRT_TYPE\",
      \"disks\": \"$ESC_DISK_INFO\",
      \"disks_json\": $DISKS_JSON,
      \"fs_root\": \"$ESC_FS_ROOT\",
      \"dmi_uuid\": \"$ESC_DMI_UUID\",
      \"dmi_serial\": \"$ESC_DMI_SERIAL\",
      \"board_serial\": \"$ESC_BOARD_SERIAL\",
      \"macs\": \"$ESC_MACS\"
    },
    \"metrics\": {
      \"cpu\": \"$CPU_LOAD\",
      \"cpu_load_5\": \"$CPU_LOAD_5\",
      \"cpu_load_15\": \"$CPU_LOAD_15\",
      \"ram_used\": ${RAM_USED:-0},
      \"ram_total\": ${RAM_TOTAL:-0},
      \"swap_used\": ${SWAP_USED:-0},
      \"swap_total\": ${SWAP_TOTAL:-0},
      \"disk_used\": ${DISK_USED:-0},
      \"disk_total\": ${DISK_TOTAL:-0},
      \"rx_bytes\": ${RX_BYTES:-0},
      \"tx_bytes\": ${TX_BYTES:-0},
      \"processes\": ${PROC_TOTAL:-0},
      \"zombies\": ${PROC_ZOMBIE:-0},
      \"failed_services\": ${FAILED_SERVICES:-0},
      \"open_ports\": ${OPEN_PORTS:-0},
      \"os\": \"$ESC_OS\",
      \"kernel\": \"$ESC_KERNEL\",
      \"uptime\": \"$ESC_UPTIME\",
      \"public_ip\": \"$ESC_PUBLIC_IP\",
      \"filesystems_json\": $FILESYSTEMS_JSON
    }
  }" >/dev/null 2>&1 || true
EOF

sed -i "s|__API_URL__|$API_URL|g" "$AGENT"
chmod +x "$AGENT"

( crontab -l 2>/dev/null | sed "\|$AGENT|d" ; echo "$CRON_EXPR $AGENT" ) | crontab -

echo
echo "Server Monitor installed"
echo "Agent path: $AGENT"
echo "Agent token saved at: $AGENT_ID_FILE"
echo "Interval: every 1 minute"
