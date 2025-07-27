#!/bin/bash

set -e

echo "🚀 Starting build process..."

# Create build directory
mkdir -p build

# Step 1: Install/update dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Step 2: Create .phar with Box
echo "📦 Creating .phar file with Box..."
box compile

echo "✅ File created: docker-backup.phar"

# Step 3: Verify PHPacker is available
if command -v phpacker >/dev/null 2>&1; then
    echo "🔧 Generating standalone executables..."
    echo "  → Building for all platforms..."
    phpacker build all --src=docker-backup.phar --dest=build/ || echo "⚠️  All platforms build failed"
    echo "✅ Building standalone executables completed!"
else
    echo "⚠️  PHPacker not available, skipping standalone executables"
    echo "💡 You can still use the .phar file with: php docker-backup.phar"
fi

echo "✅ Build completed!"
echo "📁 List of generated files:"
ls -la docker-backup.phar build/ 2>/dev/null || ls -la docker-backup.phar