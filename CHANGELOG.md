# Change Log

## 0.3.3 - 2026-05-25

- Fixed `Server::enqueue()` to accept a single PSR-7 response as documented

## 0.3.2 - 2026-05-18

- Start the node.js server without shell backgrounding

## 0.3.1 - 2026-05-18

- Fix node.js server startup on Windows

## 0.3.0 - 2026-05-18

- Harden node.js server startup and shutdown handling
- Improve JSON handling for queued and received server data
- Fix digest authentication qop handling

## 0.2.0 - 2026-05-18

- Require Node.js `^20.19 || ^22.13 || >=24` and convert the server to ESM

## 0.1.0 - 2021-10-05

* First release extracted from Guzzle 7.3
