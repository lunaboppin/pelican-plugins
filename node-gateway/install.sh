#!/usr/bin/env bash
# Installs or updates pelican-node-gateway on a Wings node.
# Needed by the vscode-files, disk-analyzer and git-manager panel plugins.
#
#   sudo ./install.sh
#
# Override the container user if your Wings config is unusual:
#   sudo PELICAN_UID=988 PELICAN_GID=988 ./install.sh
set -euo pipefail

cd "$(dirname "$0")"
WINGS_CONFIG=${WINGS_CONFIG:-/etc/pelican/config.yml}

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo ./install.sh)." >&2; exit 1; }
command -v docker >/dev/null || { echo "Docker is required (Wings needs it too)." >&2; exit 1; }
[ -r "$WINGS_CONFIG" ] || { echo "Wings config not found at $WINGS_CONFIG." >&2; exit 1; }

case "$(uname -m)" in
    x86_64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) echo "Unsupported architecture $(uname -m)." >&2; exit 1 ;;
esac

# The uid/gid Wings runs server files as (system.user.uid / gid).
read_user_id() {
    awk -v key="$1" '
        /^system:/ { s = 1; next }
        s && /^[^ ]/ { s = 0 }
        s && /^  user:/ { u = 1; next }
        u && /^  [^ ]/ { u = 0 }
        u && $1 == key":" { print $2; exit }
    ' "$WINGS_CONFIG"
}
UID_=${PELICAN_UID:-$(read_user_id uid)}
GID_=${PELICAN_GID:-$(read_user_id gid)}
[ -n "$UID_" ] && [ -n "$GID_" ] || { echo "Could not read system.user.uid/gid from $WINGS_CONFIG; set PELICAN_UID and PELICAN_GID." >&2; exit 1; }
echo "==> Server files are owned by $UID_:$GID_"

echo "==> Installing binary (linux-$ARCH)"
install -m 755 "bin/pelican-node-gateway-linux-$ARCH" /usr/local/bin/pelican-node-gateway

echo "==> Building the SSH container image (pelican-ssh:latest)"
docker build -q --build-arg UID="$UID_" --build-arg GID="$GID_" -t pelican-ssh:latest ssh-image

# VS Code is built locally on Debian from the official openvscode-server
# release, like pelican-ssh, instead of pulling gitpod/openvscode-server.
VSCODE_VERSION=${VSCODE_VERSION:-1.105.1}
VSCODE_IMAGE=pelican-vscode:$VSCODE_VERSION
case "$ARCH" in amd64) VSCODE_ARCH=x64 ;; arm64) VSCODE_ARCH=arm64 ;; esac

# Check it the way the gateway runs it: as the server's numeric user, via sh.
vscode_runs() {
    docker run --rm --user "$UID_:$GID_" --entrypoint /bin/sh "$VSCODE_IMAGE" \
        -c 'exec /home/.openvscode-server/bin/openvscode-server --version' >/dev/null 2>&1
}
vscode_diagnose() {
    set +e
    echo "--- docker $(docker version --format '{{.Server.Version}}' 2>&1), storage driver: $(docker info --format '{{.Driver}}' 2>&1)"
    echo "--- running as $UID_:$GID_:"
    docker run --rm --user "$UID_:$GID_" --entrypoint /bin/sh "$VSCODE_IMAGE" -c 'exec /home/.openvscode-server/bin/openvscode-server --version' 2>&1 | tail -3
    echo "--- files in the image, read from outside:"
    cid=$(docker create "$VSCODE_IMAGE" 2>&1)
    for f in /bin/sh /home/.openvscode-server/node /home/.openvscode-server/bin/openvscode-server; do
        printf '%s: ' "$f"; docker cp -L "$cid:$f" - 2>/dev/null | tar -xO 2>/dev/null | head -c 16 | od -An -c | tr -s ' ' | head -1
    done
    docker rm -f "$cid" >/dev/null 2>&1
}

echo "==> Building the VS Code image ($VSCODE_IMAGE, linux-$VSCODE_ARCH)"
if ! docker build -q --build-arg VERSION="$VSCODE_VERSION" --build-arg ARCH="$VSCODE_ARCH" -t "$VSCODE_IMAGE" vscode-image; then
    echo "Building the VS Code image failed (see above)." >&2
    exit 1
fi
if ! vscode_runs; then
    echo "VS Code can't run in $VSCODE_IMAGE on this node. Diagnostics:" >&2
    vscode_diagnose >&2
    exit 1
fi
docker run --rm --entrypoint true pelican-ssh:latest || { echo "The pelican-ssh image doesn't run on this node." >&2; exit 1; }

echo "==> Installing systemd service"
install -m 644 pelican-node-gateway.service /etc/systemd/system/pelican-node-gateway.service
systemctl daemon-reload
systemctl enable pelican-node-gateway >/dev/null
systemctl restart pelican-node-gateway
sleep 2
systemctl is-active --quiet pelican-node-gateway || { journalctl -u pelican-node-gateway -n 20 --no-pager; exit 1; }

echo
echo "Done. Gateway: http://127.0.0.1:8089/vscode-gw   SSH: port 2222"
curl -fsS http://127.0.0.1:8089/vscode-gw/ssh-info 2>/dev/null && echo
cat <<'EOF'

Next steps:
  1. Serve /vscode-gw/ through nginx with WebSocket support (see nginx.conf.example),
     on the panel's hostname if the panel runs on this machine, otherwise on a
     hostname for this node in the same domain as the panel.
  2. Open TCP 2222 for SSH. It must not go through an HTTP-only proxy such as
     Cloudflare's orange cloud; set the node's SFTP alias to a direct address.
EOF
