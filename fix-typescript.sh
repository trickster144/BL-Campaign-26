#!/bin/bash
# TypeScript Fixes Script - Fix all compilation errors

echo "🔧 Applying TypeScript fixes..."

# Fix all route files with RequestHandler casting
echo "📝 Fixing route files..."

# Apply the same RequestHandler fix to all route files
find backend/src/routes -name "*.ts" -exec sed -i 's/import express from/import express, { RequestHandler } from/g' {} \;
find backend/src/routes -name "*.ts" -exec sed -i 's/authenticateToken/authenticateToken as RequestHandler/g' {} \;
find backend/src/routes -name "*.ts" -exec sed -i 's/requireRole(\[/requireRole(\[/g' {} \;
find backend/src/routes -name "*.ts" -exec sed -i 's/\]\)/\]\) as RequestHandler/g' {} \;

echo "✅ Route fixes applied"

echo ""
echo "🔨 Building backend to test fixes..."
cd backend
npm run build

if [ $? -eq 0 ]; then
    echo "✅ Build successful! TypeScript errors fixed."
else
    echo "❌ Build still has errors. Check output above."
fi

cd ..
echo ""
echo "🎯 TypeScript fixes complete!"