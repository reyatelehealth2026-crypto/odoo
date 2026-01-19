#!/bin/bash

# Deploy to CNY Server
# Server: z129720-ri35sm.ps09.zwhhosting.com:9922
# Path: /home/zrismpsz/public_html/cny.re-ya.com

echo "🚀 Deploying to CNY Server..."

# Configuration
SERVER="z129720-ri35sm.ps09.zwhhosting.com"
PORT="9922"
USER="zrismpsz"
REMOTE_PATH="/home/zrismpsz/public_html/cny.re-ya.com"

# Files to deploy (rewards modal fix)
FILES=(
    "liff/index.php"
    "liff/assets/js/liff-app.js"
    "liff/assets/js/components/rewards-catalog.js"
)

echo "📦 Files to deploy:"
for file in "${FILES[@]}"; do
    echo "  - $file"
done

echo ""
echo "🔗 Connecting to $SERVER:$PORT..."

# Upload files
for file in "${FILES[@]}"; do
    echo "📤 Uploading $file..."
    scp -P $PORT "$file" "$USER@$SERVER:$REMOTE_PATH/$file"

    if [ $? -eq 0 ]; then
        echo "✅ $file uploaded successfully"
    else
        echo "❌ Failed to upload $file"
        exit 1
    fi
done

echo ""
echo "✅ Deployment completed successfully!"
echo "🌐 URL: https://cny.re-ya.com"
echo ""
echo "📝 Next steps:"
echo "1. Clear browser cache (Ctrl+Shift+R)"
echo "2. Test reward cards - modal should open when clicking any card"
echo "3. Try redeeming a reward with sufficient points"
