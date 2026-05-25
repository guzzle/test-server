# Change Log

## 1.0.0 - Upcoming

- Dropped support for PHP 7.2 and 7.3
- Switched from Guzzle 7.x to 8.x and from Guzzle PSR-7 2.x to 3.x
- Added native return types to `Server` control methods
- Added native parameter types to `Server` utility methods
- Made `Server` final and non-instantiable

## 0.5.0 - Upcoming

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
