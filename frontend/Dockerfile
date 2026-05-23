# HRIS Frontend — Dockerfile PRODUCTION (multi-stage).
#
# Stage 1: Build SPA dengan Vite → output ke /app/dist
# Stage 2: Serve via Nginx ringan (alpine), gzip + cache headers
#
# Build:
#   docker build -t hris-frontend:latest -f frontend/Dockerfile frontend/
#
# Run (standalone):
#   docker run -d -p 80:80 hris-frontend:latest
#
# Set VITE_API_BASE_URL saat build (bukan runtime — SPA static):
#   docker build --build-arg VITE_API_BASE_URL=https://api.hris.example.com \
#                -t hris-frontend:latest -f frontend/Dockerfile frontend/

# ─── Stage 1: build SPA ────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Build args (di-bake ke bundle) — set saat docker build
ARG VITE_API_BASE_URL=/
ARG VITE_USE_PROXY=false
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
ENV VITE_USE_PROXY=$VITE_USE_PROXY

# Install deps dulu (cached layer kalau package.json tidak berubah)
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

# Copy source dan build
COPY . .
RUN npm run build

# ─── Stage 2: nginx static serve ───────────────────────────────────
FROM nginx:alpine

# Default nginx config diganti dengan SPA-friendly (try_files fallback ke index.html)
RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy build artifact dari stage 1
COPY --from=builder /app/dist /usr/share/nginx/html

# Expose http
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://localhost/ || exit 1

CMD ["nginx", "-g", "daemon off;"]