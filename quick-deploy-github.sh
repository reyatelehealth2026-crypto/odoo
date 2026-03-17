#!/bin/bash
# Quick deploy to GitHub

set -e

echo "🚀 Deploying to GitHub..."

# Add all files
git add .

# Commit
echo "📝 Enter commit message (or press Enter for default):"
read -r COMMIT_MSG
if [ -z "$COMMIT_MSG" ]; then
    COMMIT_MSG="Update: $(date '+%Y-%m-%d %H:%M:%S')"
fi

git commit -m "$COMMIT_MSG" || echo "No changes to commit"

# Push
echo "📤 Pushing to GitHub..."
git push -u origin main || {
    echo "⚠️  Push failed. Trying to pull first..."
    git pull origin main --allow-unrelated-histories
    git push -u origin main
}

echo "✅ Deploy complete!"
echo "🌐 Check your repository at: https://github.com/reyatelehealth2026-crypto/odoo"
