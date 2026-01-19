@echo off
echo ========================================
echo Setup Rewards Testing System
echo ========================================
echo.

echo Step 1: Check rewards system...
php install\check_rewards_system.php
echo.

echo Step 2: Create test rewards...
php install\create_test_reward.php
echo.

echo Step 3: Add test points to user...
php install\add_test_points.php
echo.

echo ========================================
echo Setup Complete!
echo ========================================
echo.
echo Next steps:
echo 1. Open LINE app
echo 2. Go to LIFF rewards page
echo 3. Try to redeem a reward
echo.
pause
