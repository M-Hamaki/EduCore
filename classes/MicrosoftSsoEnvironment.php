<?php

declare(strict_types=1);

/** Resolves Microsoft SSO settings without allowing arbitrary Host headers to choose callbacks. */
final class MicrosoftSsoEnvironment
{
    /** @var callable(string,string):string */
    private $envReader;

    /** @param array<string,mixed> $server */
    public function __construct(private array $server, callable $envReader)
    {
        $this->envReader = $envReader;
    }

    public function isLocal(): bool
    {
        $mode = strtolower($this->env('MICROSOFT_SSO_ENV'));
        if ($mode === 'local') {
            return true;
        }
        if ($mode === 'production') {
            return false;
        }
        return $this->isLoopbackHost();
    }

    public function name(): string
    {
        return $this->isLocal() ? 'local' : 'production';
    }

    public function credential(string $productionKey, string $localKey): string
    {
        if ($this->isLocal()) {
            $local = $this->env($localKey);
            if ($local !== '') {
                return $local;
            }
        }
        return $this->env($productionKey);
    }

    public function redirectUri(bool $teams = false): string
    {
        $productionKey = $teams ? 'AZURE_TEAMS_REDIRECT_URI' : 'AZURE_REDIRECT_URI';
        $localKey = $teams ? 'AZURE_LOCAL_TEAMS_REDIRECT_URI' : 'AZURE_LOCAL_REDIRECT_URI';
        if (!$this->isLocal()) {
            return $this->env($productionKey);
        }

        $configured = $this->env($localKey);
        if ($configured !== '') {
            return $configured;
        }
        if (!$this->isLoopbackHost()) {
            return '';
        }

        $endpoint = $teams ? 'teams_sso.php' : 'microsoft_callback.php';
        return $this->localApplicationUrl() . '/auth/' . $endpoint;
    }

    public function teamsAppIdUri(string $clientId): string
    {
        $configured = $this->credential('TEAMS_APP_ID_URI', 'AZURE_LOCAL_TEAMS_APP_ID_URI');
        return $configured !== ''
            ? rtrim($configured, '/')
            : 'api://' . $clientId;
    }

    private function env(string $key): string
    {
        return trim((string) call_user_func($this->envReader, $key, ''));
    }

    private function host(): string
    {
        $rawHost = trim((string) ($this->server['HTTP_HOST'] ?? ''));
        if ($rawHost === '') {
            return '';
        }
        $parsed = parse_url('http://' . $rawHost, PHP_URL_HOST);
        return strtolower((string) ($parsed ?: ''));
    }

    private function isLoopbackHost(): bool
    {
        return in_array($this->host(), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function localApplicationUrl(): string
    {
        $rawHost = trim((string) ($this->server['HTTP_HOST'] ?? 'localhost'));
        $host = $this->host();
        $authority = $host === '::1' ? '[::1]' : $host;
        $port = parse_url('http://' . $rawHost, PHP_URL_PORT);
        if (is_int($port) && $port > 0) {
            $authority .= ':' . $port;
        }
        $scheme = !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off' ? 'https' : 'http';
        $scriptName = str_replace('\\', '/', (string) ($this->server['SCRIPT_NAME'] ?? '/EduCore/auth/microsoft_login.php'));
        $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if (strtolower((string) basename($directory)) === 'auth') {
            $directory = rtrim(str_replace('\\', '/', dirname($directory)), '/');
        }
        if ($directory === '.' || $directory === '/') {
            $directory = '';
        }
        return $scheme . '://' . $authority . $directory;
    }
}
