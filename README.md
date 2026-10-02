# Pelican plugins: VS Code, SSH, Disk Analyzer, Git

| File | Install on | What it adds |
|---|---|---|
| `vscode-files.zip` | Panel | VS Code file manager (List Mode / VS Code toggle), browser SSH console on the Console page, SSH Details page, Open in Local VS Code |
| `disk-analyzer.zip` | Panel | Disk Analyzer page (treemap, folders, file types) |
| `git-manager.zip` | Panel | Git page (scan, pull, clone, history, deploy key, HTTPS tokens, webhooks; no push) |
| `pelican-node-gateway.tar.gz` | **Every node** | The service the plugins talk to: runs the VS Code / SSH / git containers, scans disks, serves SSH on port 2222 |

The panel plugins are independent of each other, but all three need the node
gateway on each node whose servers should use them.

Requirements: Pelican Panel 1.0.0-beta38 or newer, Wings with Docker, and
amd64 or arm64 nodes.

## 1. Panel

1. **Admin → Plugins → Import from file**, upload each zip you want, then click
   **Install** on it. Install runs the plugin's migrations (VS Code mode
   preference, git credentials/webhooks).
2. Clear caches on the panel:
   ```bash
   php artisan optimize:clear
   ```
3. Give subusers the new permissions (Server → Users → edit). Owners and root
   admins have them already.

   | Group | Permissions |
   |---|---|
   | VS Code | `vscode.open`, `vscode.write` |
   | SSH Console | `ssh.connect` (logging in on port 2222 also needs SFTP) |
   | Disk Analyzer | `disk.view`, `disk.rescan` |
   | Git | `git.view`, `git.pull`, `git.manage` |

## 2. Each node

```bash
tar xzf pelican-node-gateway.tar.gz
cd node-gateway
sudo ./install.sh
```

The installer installs `/usr/local/bin/pelican-node-gateway` and its systemd
service, builds the `pelican-ssh` image with the uid/gid from the Wings config,
and pulls the VS Code image. Run it again to update.

Then:

- **Web access.** Add the `/vscode-gw/` location from `nginx.conf.example`
  (WebSocket upgrade included). Any proxy in front (Cloudflare, another nginx)
  must pass WebSocket upgrades for that path too.
- **SSH (port 2222).** Open/forward TCP 2222 to the node. Cloudflare's orange
  cloud can't carry SSH, so make sure users connect to a direct address: the
  plugin uses the node's **SFTP alias** if set, otherwise its FQDN.

## Configuration

Defaults work when the panel and Wings share a machine. Set these in the panel's
`.env`, then run `php artisan optimize:clear`:

| Variable | Default | Purpose |
|---|---|---|
| `VSCODE_FILES_GATEWAY_URL` | `APP_URL/vscode-gw` | Gateway URL used by the VS Code plugin |
| `DISK_ANALYZER_GATEWAY_URL` | `APP_URL/vscode-gw` | Same, for the Disk Analyzer |
| `GIT_MANAGER_GATEWAY_URL` | `APP_URL/vscode-gw` | Same, for Git |
| `VSCODE_FILES_SSH_HOST` | node SFTP alias, then FQDN | Address shown for SSH / Open in Local VS Code |
| `VSCODE_FILES_SSH_PORT` | `2222` | SSH port shown to users |
| `VSCODE_FILES_SSH_ENABLED` | `true` | Turn the SSH console and SSH port links off in the panel |

### Nodes on other machines

Each plugin has a per-node map in `plugins/<id>/config/<id>.php`:

```php
'nodes' => [
    2 => 'https://node2.example.com/vscode-gw',   // node id => gateway URL
],
```

and `vscode-files` also has `'ssh' => ['nodes' => [2 => ['host' => '…', 'port' => 2222]]]`.
The node hostname must share a domain with the panel for the VS Code iframe.

### Gateway options

Edit `ExecStart` in `/etc/systemd/system/pelican-node-gateway.service`, e.g.
`ExecStart=/usr/local/bin/pelican-node-gateway -ssh-listen :2200 -idle-timeout 15m`.
Run `pelican-node-gateway -h` for all flags (memory/CPU limits, ports, images).
Use `-ssh-listen ""` to disable the SSH port.

## Updating or moving from this dev panel

The dev node runs the same gateway as `pelican-vscode-gateway`. If you install
the package on that node, stop the old service first:
`systemctl disable --now pelican-vscode-gateway`.
