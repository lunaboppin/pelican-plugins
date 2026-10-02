# VS Code for the Web image for pelican-node-gateway, built locally on Debian
# from the official openvscode-server release (same layout as the upstream
# gitpod/openvscode-server image, so the gateway can use either).
#   docker build --build-arg ARCH=x64 -t pelican-vscode:1.105.1 .
# ARCH is x64 or arm64. The gateway runs it as the server's numeric uid/gid.
FROM debian:trixie-slim

ARG VERSION=1.105.1
ARG ARCH=x64

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates curl git openssh-client libatomic1 libstdc++6 procps less nano \
 && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /home/.openvscode-server \
 && curl -fsSL "https://github.com/gitpod-io/openvscode-server/releases/download/openvscode-server-v${VERSION}/openvscode-server-v${VERSION}-linux-${ARCH}.tar.gz" \
    | tar -xz --strip-components=1 -C /home/.openvscode-server \
 && /home/.openvscode-server/bin/openvscode-server --version

# C.UTF-8 is built into glibc, so it can't be missing like a generated locale.
ENV LANG=C.UTF-8 LC_ALL=C.UTF-8 HOME=/tmp OPENVSCODE_SERVER_ROOT=/home/.openvscode-server

ENTRYPOINT ["/home/.openvscode-server/bin/openvscode-server"]
