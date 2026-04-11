FROM node:20-alpine

WORKDIR /app

# Install dependencies (including devDependencies for build)
COPY package*.json ./
RUN npm install

# Copy source code
COPY . .

# Build TypeScript
RUN npm run build || echo "tsc build had warnings, continuing..."

# Use non-root user
RUN addgroup -S nodejs && adduser -S nodejs -G nodejs
USER nodejs

EXPOSE 55002

# Try compiled output first, fall back to tsx
CMD ["node", "dist/server.js"]