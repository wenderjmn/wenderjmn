#!/bin/bash
set -euo pipefail

# Only run in remote Claude Code environments
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

SKILLS_DIR="${CLAUDE_PROJECT_DIR:-$(pwd)}/.agents/skills"

install_skills_if_needed() {
  local marker="$SKILLS_DIR/.skills-installed"

  # Check if skills are already installed
  if [ -d "$SKILLS_DIR/ui-ux-pro-max" ] && [ -d "$SKILLS_DIR/obsidian-markdown" ]; then
    echo "[session-start] Skills already installed, skipping."
    return 0
  fi

  echo "[session-start] Installing skills..."

  # ui-ux-pro-max (design intelligence)
  npx --yes skills add nextlevelbuilder/ui-ux-pro-max-skill --yes 2>&1 | tail -5

  # obsidian-skills
  npx --yes skills add git@github.com:kepano/obsidian-skills.git --yes 2>&1 | tail -5

  echo "[session-start] Skills installed successfully."
}

install_gsd_if_needed() {
  if [ -f "$HOME/.claude/skills/gsd-new-project/SKILL.md" ] || \
     ls "$HOME/.claude/skills/" 2>/dev/null | grep -q "^gsd-"; then
    echo "[session-start] gsd-core already installed, skipping."
    return 0
  fi

  echo "[session-start] Installing gsd-core..."
  npx --yes @opengsd/gsd-core@latest 2>&1 | tail -10
  echo "[session-start] gsd-core installed."
}

install_skills_if_needed
install_gsd_if_needed

echo "[session-start] All skills ready."
