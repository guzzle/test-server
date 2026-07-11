/**
 * Guzzle node.js test server to return queued responses to HTTP requests and
 * expose a RESTful API for enqueueing responses and retrieving the requests
 * that have been received.
 *
 * - Delete all requests that have been received:
 *      > DELETE /guzzle-server/requests
 *      > Host: 127.0.0.1:8126
 *
 *  - Enqueue responses
 *      > PUT /guzzle-server/responses
 *      > Host: 127.0.0.1:8126
 *      >
 *      > [{'status': 200, 'reason': 'OK', 'headers': {}, 'body': '' }]
 *
 *  - Get the received requests
 *      > GET /guzzle-server/requests
 *      > Host: 127.0.0.1:8126
 *
 *      < HTTP/1.1 200 OK
 *      <
 *      < [{'http_method': 'GET', 'uri': '/', 'headers': {}, 'body': 'string'}]
 *
 *  - Attempt access to the secure area
 *      > GET /secure/by-digest/qop-auth/guzzle-server/requests
 *      > Host: 127.0.0.1:8126
 *
 *      < HTTP/1.1 401 Unauthorized
 *      < WWW-Authenticate: Digest realm="Digest Test", nonce="<32 hex chars>", algorithm=MD5, qop="auth"
 *      < Content-Length: 0
 *
 *  - Shutdown the server
 *      > DELETE /guzzle-server
 *      > Host: 127.0.0.1:8126
 *
 * @package Guzzle PHP
 * @license See the LICENSE file that was distributed with this source code.
 */

import * as crypto from 'node:crypto';
import * as http from 'node:http';
import * as url from 'node:url';
import * as zlib from 'node:zlib';

/**
 * Guzzle node.js server
 * @class
 */
var GuzzleServer = function(port, log) {

  this.port = port;
  this.log = log;
  this.responses = [];
  this.requests = [];
  var that = this;

  var md5 = function(input) {
    var hasher = crypto.createHash('md5');
    hasher.update(input);
    return hasher.digest('hex');
  };

  var digestCredentials = {realm: 'Digest Test', login: 'me', password: 'test'};
  var issuedDigestNonces = new Set();

  var digestChallengeHeader = function(qop) {
    var nonce = crypto.randomBytes(16).toString('hex');
    issuedDigestNonces.add(nonce);

    var header = 'Digest realm="' + digestCredentials.realm + '", nonce="'
      + nonce + '", algorithm=MD5';
    if (qop) {
      header += ', qop="' + qop + '"';
    }
    return header;
  };

  var parseDigestAuthorization = function(header) {
    var scheme = /^Digest[ \t]+/i.exec(header || '');
    if (!scheme) {
      return null;
    }

    var params = Object.create(null);
    var offset = scheme[0].length;
    var pattern = /([!#$%&'*+\-.^_`|~0-9A-Za-z]+)=(?:"((?:[^"\\]|\\.)*)"|([^,\s]+))/y;
    var match;

    while (offset < header.length) {
      while (header[offset] === ' ' || header[offset] === '\t') {
        offset++;
      }

      pattern.lastIndex = offset;
      match = pattern.exec(header);
      if (!match) {
        return null;
      }

      var name = match[1].toLowerCase();
      if (Object.prototype.hasOwnProperty.call(params, name)) {
        return null;
      }

      params[name] = match[2] !== undefined
        ? match[2].replace(/\\(.)/g, '$1')
        : match[3];

      offset = pattern.lastIndex;

      while (header[offset] === ' ' || header[offset] === '\t') {
        offset++;
      }

      if (offset === header.length) {
        return params;
      }

      if (header[offset] !== ',') {
        return null;
      }

      offset++;
      if (offset === header.length) {
        return null;
      }
    }

    return params;
  };

  var hasOnlyDigestParams = function(params, names) {
    var allowed = Object.create(null);
    for (var i = 0; i < names.length; i++) {
      allowed[names[i]] = true;
    }

    for (var name in params) {
      if (!allowed[name]) {
        return false;
      }
    }

    return true;
  };

  var digestResponseEquals = function(expected, actual) {
    if (!/^[0-9a-f]{32}$/i.test(actual || '')) {
      return false;
    }

    var expectedBuffer = Buffer.from(expected, 'hex');
    var actualBuffer = Buffer.from(actual, 'hex');

    return expectedBuffer.length === actualBuffer.length
      && crypto.timingSafeEqual(expectedBuffer, actualBuffer);
  };

  var checkDigestAuthorization = function(req, qop) {
    var params = parseDigestAuthorization(req.headers['authorization']);
    var expectedParams = qop
      ? ['username', 'realm', 'nonce', 'uri', 'response', 'algorithm', 'qop', 'nc', 'cnonce']
      : ['username', 'realm', 'nonce', 'uri', 'response', 'algorithm'];

    if (!params || !hasOnlyDigestParams(params, expectedParams)
        || params.username !== digestCredentials.login
        || params.realm !== digestCredentials.realm
        || !params.nonce || !issuedDigestNonces.has(params.nonce)
        || params.uri !== req.url
        || !params.algorithm || params.algorithm.toUpperCase() !== 'MD5') {
      return false;
    }

    var ha1 = md5(digestCredentials.login + ':' + digestCredentials.realm + ':' + digestCredentials.password);
    var ha2 = md5(req.method + ':' + req.url);
    var expected;

    if (qop) {
      if (params.qop !== qop
          || !/^[0-9a-f]{8}$/i.test(params.nc || '')
          || params.nc === '00000000'
          || !params.cnonce) {
        return false;
      }

      expected = md5(ha1 + ':' + params.nonce + ':' + params.nc + ':' + params.cnonce + ':' + qop + ':' + ha2);
    } else {
      expected = md5(ha1 + ':' + params.nonce + ':' + ha2);
    }

    return digestResponseEquals(expected, params.response);
  };

  var firewallRequest = function(request, req, res, requestHandlerCallback) {
    var securedAreaUriParts = request.uri.match(/^\/secure\/by-(digest)(\/qop-([^\/]*))?(\/.*)$/);
    if (securedAreaUriParts) {
      var qop = securedAreaUriParts[3] || null;
      if (!checkDigestAuthorization(req, qop)) {
        res.writeHead(401, 'Unauthorized', {
          'WWW-Authenticate': digestChallengeHeader(qop),
          'Content-Length': 0
        });
        res.end();
        return;
      }
      req.url = securedAreaUriParts[4];
      requestHandlerCallback(request, req, res);
    } else {
      requestHandlerCallback(request, req, res);
    }
  };

  var controlRequest = function(request, req, res) {
    if (req.url == '/guzzle-server/garbage') {
      if (that.log) {
        console.log('returning garbage')
      }
      res.socket.end("220 example.com ESMTP\r\n200 This is garbage\r\n\r\n");
    } else if (req.url == '/guzzle-server/bad-status') {
      if (that.log) {
        console.log('returning bad status code')
      }
      res.writeHead(700, 'BAD', {'Content-Length': 16});
      res.end('Body of response');
    } else if (req.url == '/guzzle-server/perf') {
      res.writeHead(200, 'OK', {'Content-Length': 16});
      res.end('Body of response');
    } else if (req.method == 'DELETE') {
      if (req.url == '/guzzle-server/requests') {
        // Clear the received requests
        that.requests = [];
        res.writeHead(200, 'OK', { 'Content-Length': 0 });
        res.end();
        if (that.log) {
          console.log('Flushing requests');
        }
      } else if (req.url == '/guzzle-server') {
        // Shutdown the server
        res.writeHead(200, 'OK', { 'Content-Length': 0, 'Connection': 'close' });
        res.end();
        if (that.log) {
          console.log('Shutting down');
        }
        that.server.close();
      }
    } else if (req.method == 'GET') {
      if (req.url === '/guzzle-server/requests') {
        if (that.log) {
          console.log('Sending received requests');
        }
        // Get received requests
        var body = JSON.stringify(that.requests);
        res.writeHead(200, 'OK', { 'Content-Length': Buffer.byteLength(body) });
        res.end(body);
      } else if (req.url == '/guzzle-server/read-timeout') {
        if (that.log) {
          console.log('Sleeping');
        }
        res.writeHead(200, 'OK');
        res.write("sleeping 60 seconds ...\n");
        setTimeout(function () {
          res.end("slept 60 seconds\n");
        }, 60*1000);
      } else if (req.url == '/guzzle-server/read-timeout-gzip') {
        if (that.log) {
          console.log('Sleeping (gzip)');
        }
        var gzipped = zlib.gzipSync(
          Buffer.from('hi there ... this gzip body never finishes\n'.repeat(64))
        );
        var half = Math.floor(gzipped.length / 2);
        res.writeHead(200, 'OK', { 'Content-Encoding': 'gzip' });
        // Send a valid but incomplete gzip prefix, then stall so the client
        // times out mid-body while inflating.
        res.write(gzipped.slice(0, half));
        setTimeout(function () {
          res.end(gzipped.slice(half));
        }, 60*1000);
      } else if (req.url == '/guzzle-server/drip-timeout') {
        if (that.log) {
          console.log('Dripping response body');
        }
        res.writeHead(200, 'OK');
        // Flush the headers now; Node would otherwise hold them until the
        // first timer tick, risking the client's header-phase timeout.
        res.flushHeaders();
        // Send one body byte every 100ms so no single read stalls, while the
        // whole body takes ~2 seconds to arrive. The disconnect check runs
        // inside the tick because the request 'close' event fires when the
        // message completes on current Node, not when the connection closes.
        var dripped = 0;
        var dripInterval = setInterval(function () {
          if (res.writableEnded || res.destroyed || !res.socket || res.socket.destroyed) {
            clearInterval(dripInterval);
            return;
          }
          res.write('.');
          if (++dripped >= 20) {
            clearInterval(dripInterval);
            res.end();
          }
        }, 100);
      } else if (req.url == '/guzzle-server/drip-timeout-gzip') {
        if (that.log) {
          console.log('Dripping response body (gzip)');
        }
        var gzippedDrip = zlib.gzipSync(
          Buffer.from('hi there ... this gzip body arrives slowly\n'.repeat(64))
        );
        var pieceLength = Math.ceil(gzippedDrip.length / 20);
        var offset = 0;
        res.writeHead(200, 'OK', { 'Content-Encoding': 'gzip' });
        // Flush the headers now; Node would otherwise hold them until the
        // first timer tick, risking the client's header-phase timeout.
        res.flushHeaders();
        // Send a valid gzip slice every 100ms so no single read stalls, while
        // the whole body takes ~2 seconds to arrive.
        var gzipDripInterval = setInterval(function () {
          if (res.writableEnded || res.destroyed || !res.socket || res.socket.destroyed) {
            clearInterval(gzipDripInterval);
            return;
          }
          res.write(gzippedDrip.slice(offset, offset + pieceLength));
          offset += pieceLength;
          if (offset >= gzippedDrip.length) {
            clearInterval(gzipDripInterval);
            res.end();
          }
        }, 100);
      } else if (req.url == '/guzzle-server/drip-timeout-headers') {
        if (that.log) {
          console.log('Dripping response headers');
        }
        // Write the header block to the raw socket so the header phase itself
        // arrives slowly (~0.8 seconds) without any single read stalling.
        res.socket.write('HTTP/1.1 200 OK\r\n');
        var headerCount = 0;
        var headerInterval = setInterval(function () {
          if (!res.socket || res.socket.destroyed) {
            clearInterval(headerInterval);
            return;
          }
          res.socket.write('X-Drip-' + headerCount + ': ' + headerCount + '\r\n');
          if (++headerCount >= 8) {
            clearInterval(headerInterval);
            res.socket.end('Content-Length: 2\r\nConnection: close\r\n\r\nok');
          }
        }, 100);
      } else if (req.url == '/guzzle-server/stall-brief') {
        if (that.log) {
          console.log('Stalling briefly');
        }
        // Stall mid-body for longer than a small socket timeout, then finish,
        // so a completed response can be observed without a long wait.
        res.writeHead(200, 'OK');
        res.write('partial-');
        setTimeout(function () {
          res.end('rest');
        }, 1500);
      }
    } else if (req.method == 'PUT' && req.url == '/guzzle-server/responses') {
      if (that.log) {
        console.log('Adding responses...');
      }
      if (!request.body) {
        if (that.log) {
          console.log('No response data was provided');
        }
        res.writeHead(400, 'NO RESPONSES IN REQUEST', { 'Content-Length': 0 });
      } else {
        var responses;
        try {
          responses = JSON.parse(request.body);
        } catch (e) {
          if (that.log) {
            console.log('Invalid response JSON: ' + e.message);
          }
          res.writeHead(400, 'INVALID RESPONSE JSON', { 'Content-Length': 0 });
          res.end();
          return;
        }
        if (!Array.isArray(responses)) {
          if (that.log) {
            console.log('Response data must be an array');
          }
          res.writeHead(400, 'RESPONSES MUST BE AN ARRAY', { 'Content-Length': 0 });
          res.end();
          return;
        }
        that.responses = responses;
        for (var i = 0; i < that.responses.length; i++) {
          if (Object.prototype.hasOwnProperty.call(that.responses[i], 'body') && that.responses[i].body !== null) {
            that.responses[i].body = Buffer.from(that.responses[i].body, 'base64');
          }
        }
        if (that.log) {
          console.log(that.responses);
        }
        res.writeHead(200, 'OK', { 'Content-Length': 0 });
      }
      res.end();
    }
  };

  var receivedRequest = function(request, req, res) {
    if (req.url.indexOf('/guzzle-server') === 0) {
      controlRequest(request, req, res);
    } else if (req.url.indexOf('/guzzle-server') == -1 && !that.responses.length) {
      res.writeHead(500);
      res.end('No responses in queue');
    } else {
      if (that.log) {
        console.log('Returning response from queue and adding request');
      }
      that.requests.push(request);
      var response = that.responses.shift();
      if (!response) {
        res.writeHead(500);
        res.end('No responses in queue');
        return;
      }
      if (response.raw === true) {
        if (that.log) {
          console.log('Returning raw response from queue');
        }
        res.socket.end(response.body);
        return;
      }
      res.writeHead(response.status, response.reason, response.headers);
      res.end(response.body);
    }
  };

  this.start = function() {

    that.server = http.createServer(function(req, res) {

      var parts = url.parse(req.url, false);
      var request = {
        http_method: req.method,
        scheme: parts.scheme,
        uri: parts.pathname,
        query_string: parts.query,
        headers: req.headers,
        version: req.httpVersion,
        body: ''
      };

      // Receive each chunk of the request body
      req.addListener('data', function(chunk) {
        request.body += chunk;
      });

      // Called when the request completes
      req.addListener('end', function() {
        firewallRequest(request, req, res, receivedRequest);
      });
    });

    that.server.listen(this.port, '127.0.0.1');

    if (this.log) {
      console.log('Server running at http://127.0.0.1:8126/');
    }
  };
};

// Get the port from the arguments
const port = process.argv.length >= 3 ? process.argv[2] : 8126;
const log = process.argv.length >= 4 ? process.argv[3] : false;

// Start the server
const server = new GuzzleServer(port, log);
server.start();
