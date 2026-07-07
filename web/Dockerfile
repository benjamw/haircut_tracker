FROM node:22-alpine

WORKDIR /app

EXPOSE 5173

# Install deps (if missing) then run the Vite dev server.
# node_modules lives on a named volume so it survives the source bind-mount.
CMD ["sh", "-c", "npm install && npm run dev"]
