# SSH console image for pelican-vscode-gateway.
#   docker build --build-arg UID=995 --build-arg GID=987 -t pelican-ssh:latest .
# UID/GID must match system.user.uid/gid in /etc/pelican/config.yml.
FROM debian:trixie-slim

ARG UID=995
ARG GID=987

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      bash bash-completion ca-certificates curl wget git nano vim-tiny less htop procps \
      tar gzip zip unzip xz-utils p7zip-full rsync jq sqlite3 file tree tmux screen \
      lua5.4 python3 openssh-client openssh-sftp-server socat mariadb-client iputils-ping dnsutils \
 && rm -rf /var/lib/apt/lists/*

RUN groupadd -g ${GID} container && useradd -u ${UID} -g ${GID} -M -d /home/container -s /bin/bash container

# C.UTF-8 is built into glibc, so it can't be missing like a generated locale.
ENV LANG=C.UTF-8 LC_ALL=C.UTF-8

COPY banner.sh /etc/profile.d/zz-pelican-banner.sh
COPY bashrc /etc/bash.bashrc
