# Frontend Dockerfile — build with Vite, serve with nginx
FROM node:20-alpine AS builder

WORKDIR /app

# Copy package files first for better caching
COPY package.json package-lock.json ./
RUN npm ci

# Copy build configuration files
COPY index.html tsconfig.json tsconfig.node.json vite.config.ts ./

# Copy source code
COPY src ./src

# Build the application
RUN npm run build

# Production stage
FROM nginx:alpine

# Copy built application
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
