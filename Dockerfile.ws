FROM node:20-alpine

WORKDIR /app

COPY package.json ./
RUN npm install --production

COPY ws-server.js ./

EXPOSE 8080

CMD ["node", "ws-server.js"]
