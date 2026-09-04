<?php

class Api
{
    public static function json($payload, $status = 200, array $headers = [])
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function body()
    {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::json(['error' => true, 'message' => 'Request body must be a JSON object.'], 400);
        }

        return $decoded;
    }

    public static function method()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    public static function bearerToken()
    {
        $authorization = self::headerValue([
            'Authorization',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'X-Authorization',
        ]);
        $token = self::tokenFromAuthorization($authorization);
        if ($token !== '') {
            return $token;
        }

        return self::headerValue([
            'X-PBB-Agent-Token',
            'HTTP_X_PBB_AGENT_TOKEN',
            'X-Agent-Token',
            'HTTP_X_AGENT_TOKEN',
        ]);
    }

    private static function tokenFromAuthorization($authorization)
    {
        $authorization = trim((string) $authorization);
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        return '';
    }

    private static function headerValue(array $names)
    {
        foreach ($names as $name) {
            $serverName = strtoupper(str_replace('-', '_', $name));
            if (strpos($serverName, 'HTTP_') !== 0 && $serverName !== 'CONTENT_TYPE' && $serverName !== 'CONTENT_LENGTH') {
                $serverName = 'HTTP_' . $serverName;
            }
            if (isset($_SERVER[$name]) && trim((string) $_SERVER[$name]) !== '') {
                return trim((string) $_SERVER[$name]);
            }
            if (isset($_SERVER[$serverName]) && trim((string) $_SERVER[$serverName]) !== '') {
                return trim((string) $_SERVER[$serverName]);
            }
        }

        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        }

        if (is_array($headers)) {
            $normalized = [];
            foreach ($headers as $key => $value) {
                $normalized[strtolower($key)] = trim((string) $value);
            }
            foreach ($names as $name) {
                $key = strtolower(str_replace('HTTP_', '', str_replace('_', '-', $name)));
                if (isset($normalized[$key]) && $normalized[$key] !== '') {
                    return $normalized[$key];
                }
            }
        }

        return '';
    }
}
