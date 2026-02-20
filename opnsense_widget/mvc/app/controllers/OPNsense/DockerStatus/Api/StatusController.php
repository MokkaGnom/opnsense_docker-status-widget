<?php

/*
 * Copyright (C) 2026
 * All rights reserved.
 */

namespace OPNsense\DockerStatus\Api;

use OPNsense\Base\ApiControllerBase;

class StatusController extends ApiControllerBase
{
    private const DEFAULT_PORT = 42679;

    private function buildUrl($host)
    {
        $host = trim($host);
        if ($host === "" || strlen($host) > 255 || preg_match('/\s/', $host)) {
            return null;
        }

        if (preg_match('~^https?://~i', $host)) {
            $parts = parse_url($host);
            if (empty($parts['host']) || empty($parts['scheme'])) {
                return null;
            }
            if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return null;
            }
            if (isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
            $path = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $parts['scheme'] . '://' . $parts['host'] . $port . rtrim($path, '/') . '/' . $query;
        }

        $ipv6 = preg_match('/^\[[0-9a-fA-F:]+\](?::\d+)?$/', $host);
        $hostname = preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host);
        if (!$ipv6 && !$hostname) {
            return null;
        }

        if (strpos($host, ':') === false || $ipv6) {
            return 'http://' . $host . ':' . self::DEFAULT_PORT . '/';
        }

        return 'http://' . $host . '/';
    }

    private function fetchJson($url, $timeoutMs)
    {
        $timeoutMs = (int)$timeoutMs;
        if ($timeoutMs <= 0) {
            $timeoutMs = 1000;
        }
        $timeoutMs = max(1000, min($timeoutMs, 30000));
        $timeoutSec = (int)ceil($timeoutMs / 1000);
        $connectTimeout = max(1, min(2, $timeoutSec));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['result' => 'failed', 'message' => $error !== '' ? $error : 'request failed'];
        }

        if ($status !== 200) {
            return ['result' => 'failed', 'message' => 'http ' . $status];
        }

        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['result' => 'failed', 'message' => 'invalid json'];
        }

        return ['result' => 'ok', 'data' => $data];
    }

    public function containersAction()
    {
        $host = $this->request->getQuery('host', 'string', '');
        $timeoutMs = $this->request->getQuery('timeout_ms', 'int', 0);
        $url = $this->buildUrl($host);
        if ($url === null) {
            return ['result' => 'failed', 'message' => 'invalid host'];
        }

        return $this->fetchJson($url, $timeoutMs);
    }
}
