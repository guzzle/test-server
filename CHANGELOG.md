# Change Log

## 0.7.0 - 2026-07-16

- Require `guzzlehttp/guzzle` ^7.13.3 and `guzzlehttp/psr7` ^2.12.4
- Decode received request data without Guzzle's deprecated JSON utility

## 0.6.0 - 2026-06-23

- Require `guzzlehttp/guzzle` ^7.12.3 and `guzzlehttp/psr7` ^2.12.3
- Improve diagnostics for invalid responses and malformed received requests

## 0.5.1 - 2026-06-18

- Disable proxying on the control client so an ambient `http_proxy` cannot misroute it
- Return a 500 instead of crashing when the response queue is empty

## 0.5.0 - 2026-06-02

- Require `guzzlehttp/guzzle` ^7.11 and `guzzlehttp/psr7` ^2.11
- Harden received request reconstruction from node.js server data

## 0.4.0 - 2026-05-25

- Parse ports from Host headers when reconstructing received requests

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

- First release extracted from Guzzle 7.3
