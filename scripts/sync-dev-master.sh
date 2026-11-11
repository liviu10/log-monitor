#!/usr/bin/env bash
#
# LogMonitor Branch Synchronization Script
# Synchronizes changes from 'dev' to 'master' (via GitHub PR with assignee '@me' or direct local git merge)
# and brings both local branches in sync.
#

set -euo pipefail

# Text formatting
BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
RED="\033[0;31m"
BLUE="\033[0;34m"
NC="\033[0m" # No Color

echo -e "${BOLD}${BLUE}==========================================================${NC}"
echo -e "${BOLD}${BLUE}🔄 LogMonitor Branch Synchronization: dev ➔ master${NC}"
echo -e "${BOLD}${BLUE}==========================================================${NC}"

# 1. Verify working directory is inside a git repo
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo -e "${RED}❌ Error: Must be executed inside the git repository.${NC}"
    exit 1
fi

# 2. Check current branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$CURRENT_BRANCH" != "dev" ]; then
    echo -e "${RED}❌ Error: Must be on the 'dev' branch to initiate sync (current branch: ${CURRENT_BRANCH}).${NC}"
    echo -e "   Please run 'git checkout dev' first."
    exit 1
fi

# 3. Check for uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${RED}❌ Error: Uncommitted changes detected in working tree.${NC}"
    echo -e "   Please commit or stash your changes before synchronizing."
    git status -s
    exit 1
fi

# 4. Push local dev branch to origin
echo -e "\n${BOLD}[1/4] Pushing latest 'dev' commits to remote...${NC}"
git push origin dev
echo -e "${GREEN}✓ 'dev' branch is up to date on remote.${NC}"

# 5. Check if GitHub CLI is available and authenticated
USE_GH=false
if command -v gh >/dev/null 2>&1; then
    if gh auth status >/dev/null 2>&1; then
        USE_GH=true
    else
        echo -e "${YELLOW}⚠️ Notice: 'gh' CLI is installed but not authenticated. Run 'gh auth login' to enable automated PRs.${NC}"
    fi
else
    echo -e "${YELLOW}⚠️ Notice: 'gh' (GitHub CLI) is not installed. To install on Fedora: 'sudo dnf install -y gh && gh auth login'${NC}"
fi

# 6. Perform merge (via GitHub PR or direct local merge)
if [ "$USE_GH" = true ]; then
    echo -e "\n${BOLD}[2/4] Managing GitHub Pull Request with Assignee '@me'...${NC}"
    
    # Check if an existing open PR from dev to master already exists
    EXISTING_PR=$(gh pr list --head dev --base master --state open --json number --jq '.[0].number' 2>/dev/null || true)
    
    if [ -n "$EXISTING_PR" ] && [ "$EXISTING_PR" != "null" ]; then
        echo -e "   Found existing open PR #${EXISTING_PR}."
        PR_NUMBER="$EXISTING_PR"
        # Ensure assignee is set to @me
        gh pr edit "$PR_NUMBER" --add-assignee "@me" >/dev/null 2>&1 || true
    else
        LATEST_COMMIT_MSG=$(git log -1 --pretty=%s)
        echo -e "   Creating new Pull Request assigned to @me..."
        PR_URL=$(gh pr create \
            --base master \
            --head dev \
            --title "Merge dev into master: ${LATEST_COMMIT_MSG}" \
            --body "Automated branch synchronization from \`dev\` into \`master\`." \
            --assignee "@me")
        echo -e "${GREEN}✓ Created Pull Request: ${PR_URL}${NC}"
        PR_NUMBER=$(gh pr view --json number --jq '.number')
    fi
    
    echo -e "\n${BOLD}[3/4] Merging Pull Request #${PR_NUMBER} on GitHub...${NC}"
    # Merge without deleting the dev branch
    gh pr merge "$PR_NUMBER" --merge --delete-branch=false
    echo -e "${GREEN}✓ Pull Request #${PR_NUMBER} successfully merged on GitHub.${NC}"
else
    echo -e "\n${BOLD}[2/4] Merging directly into 'master' via local Git...${NC}"
    git checkout master
    git pull origin master
    git merge dev
    echo -e "\n${BOLD}[3/4] Pushing merged 'master' to remote...${NC}"
    git push origin master
    echo -e "${GREEN}✓ 'master' branch updated and pushed.${NC}"
fi

# 7. Synchronize local branches
echo -e "\n${BOLD}[4/4] Synchronizing local branches...${NC}"
git checkout master
git pull origin master
git checkout dev
git merge master

echo -e "\n${BOLD}${GREEN}==========================================================${NC}"
echo -e "${BOLD}${GREEN}✅ Synchronization complete!${NC}"
echo -e "   - 'master' is up to date with 'dev' on both remote and local."
echo -e "   - 'dev' is up to date with 'master' locally."
echo -e "   - Current active branch: ${BOLD}$(git rev-parse --abbrev-ref HEAD)${NC}"
echo -e "${BOLD}${GREEN}==========================================================${NC}"
