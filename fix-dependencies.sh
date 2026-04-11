#!/bin/bash
# Fix Package Lock Script
# Run this if you continue having npm ci issues

echo "🔧 Fixing npm dependencies..."
echo ""

# Backend
echo "📦 Fixing backend dependencies..."
cd backend
rm -f package-lock.json
npm install
echo "✅ Backend package-lock.json regenerated"
cd ..

# Frontend  
echo "📦 Fixing frontend dependencies..."
cd frontend
rm -f package-lock.json  
npm install
echo "✅ Frontend package-lock.json regenerated"
cd ..

echo ""
echo "🎉 Dependencies fixed!"
echo ""
echo "🚀 Now try deploying again:"
echo "docker-compose up -d --build"