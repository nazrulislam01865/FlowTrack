#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 https://your-domain.example/login"
    exit 2
fi

target_url="$1"

curl --output /dev/null --silent --show-error --location \
    --write-out $'dns=%{time_namelookup}\nconnect=%{time_connect}\ntls=%{time_appconnect}\nttfb=%{time_starttransfer}\ntotal=%{time_total}\nhttp=%{http_code}\nremote_ip=%{remote_ip}\n' \
    "$target_url"

echo
echo "Response headers:"
curl --silent --show-error --head --location "$target_url" | sed -n '1,30p'
