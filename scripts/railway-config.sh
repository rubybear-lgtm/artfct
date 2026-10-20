#!/bin/sh
# Plan or apply the Railway config for one environment, refusing to run when the
# linked environment is not the one the file describes (RUB-466).
# usage: scripts/railway-config.sh <staging|production> <plan|apply> [extra railway args]
set -eu

target="${1:?usage: railway-config.sh <staging|production> <plan|apply>}"
action="${2:?usage: railway-config.sh <staging|production> <plan|apply>}"
shift 2

case "$target" in
  staging|production) ;;
  *) echo "Unknown environment: $target" >&2; exit 2 ;;
esac

linked="$(railway status 2>/dev/null | sed -n 's/^Environment:[[:space:]]*//p' | head -n 1)"

if [ "$linked" != "$target" ]; then
  echo "Linked Railway environment is '${linked:-unknown}', not '$target'. Run 'railway environment $target' first." >&2
  exit 1
fi

exec railway config "$action" --file ".railway/${target}.ts" "$@"
