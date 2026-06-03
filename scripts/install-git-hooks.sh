#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
hook_dir="$repo_root/.git/hooks"
hook_path="$hook_dir/pre-push"

mkdir -p "$hook_dir"

if [[ -f "$hook_path" ]] && ! grep -q "archibot ci-local pre-push gate" "$hook_path"; then
  backup="$hook_path.backup.$(date +%Y%m%d%H%M%S)"
  cp "$hook_path" "$backup"
  echo "Existing pre-push hook backed up to $backup"
fi

cat > "$hook_path" <<'HOOK'
#!/usr/bin/env bash
# archibot ci-local pre-push gate
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
"$repo_root/scripts/ci-local.sh" --pre-push
HOOK

chmod +x "$hook_path"
echo "Installed ArchiBot pre-push hook at $hook_path"
