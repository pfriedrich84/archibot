#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
hook_dir="$repo_root/.git/hooks"
hook_path="$hook_dir/pre-push"
previous_hook="$hook_dir/pre-push.archibot-previous"

mkdir -p "$hook_dir"

if [[ -f "$hook_path" ]] && ! grep -q "archibot ci-local pre-push gate" "$hook_path"; then
  if [[ -e "$previous_hook" ]]; then
    backup="$previous_hook.backup.$(date +%Y%m%d%H%M%S)"
    cp "$previous_hook" "$backup"
    echo "Existing chained pre-push hook backed up to $backup"
  fi

  mv "$hook_path" "$previous_hook"
  chmod +x "$previous_hook"
  echo "Existing pre-push hook moved to $previous_hook and will be chained."
elif [[ -f "$hook_path" ]] && grep -q "archibot ci-local pre-push gate" "$hook_path"; then
  echo "Updating existing ArchiBot pre-push hook."
fi

cat > "$hook_path" <<'HOOK'
#!/usr/bin/env bash
# archibot ci-local pre-push gate
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
hook_dir="$repo_root/.git/hooks"
previous_hook="$hook_dir/pre-push.archibot-previous"
push_input="$(mktemp)"
trap 'rm -f "$push_input"' EXIT

cat > "$push_input"

if [[ -x "$previous_hook" ]]; then
  "$previous_hook" < "$push_input"
fi

"$repo_root/scripts/ci-local.sh" --pre-push < "$push_input"
HOOK

chmod +x "$hook_path"
echo "Installed ArchiBot pre-push hook at $hook_path"
