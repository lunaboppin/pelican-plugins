# Login banner for the Pelican SSH console. Values come from the gateway via -e.
[ -n "$PSSH_SERVER_UUID" ] || return 0

b=$'\e[1m'; r=$'\e[31m'; d=$'\e[2m'; n=$'\e[0m'
case "$PSSH_STATUS" in
    running) s=$'\e[32m' ;;
    starting|stopping) s=$'\e[33m' ;;
    *) s=$'\e[31m' ;;
esac

used=$(timeout 2 du -sh "/home/container-${PSSH_SERVER_UUID:0:8}" 2>/dev/null | cut -f1)

cat <<EOF

${b}Welcome to your Pelican SSH container!${n}

 * Common tools and packages are pre-installed (git, curl, nano, vim, htop, rsync, jq, sqlite3, lua, python3...).
 * Your servers on this node are mounted in /home as container-<id>. Use this to move files
   between servers or run commands across them.
 * Your home folder (~) is persistent and private. Anything you save there is kept between sessions.

${r}DANGER:${n} This is an advanced environment with ${r}powerful${n} and ${r}destructive${n} tools. Be careful when running
commands - a single mistake can have serious consequences. Make sure you have a ${r}backup schedule${n},
it is the ${r}only way${n} to recover from accidental mistakes.

  Server:   ${b}${PSSH_SERVER_NAME}${n}
  UUID:     ${PSSH_SERVER_UUID}
  Status:   ${s}${PSSH_STATUS:-unknown}${n}
  Node:     ${PSSH_NODE}
  Address:  ${PSSH_ADDRESS}
  Disk:     ${used:-?} / ${PSSH_DISK_LIMIT}

EOF
unset b r d n s used
