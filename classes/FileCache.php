<?php

class FileCache
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'educore-cache';
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    public function remember(string $key, int $ttl, callable $resolver)
    {
        $path = $this->path($key);
        if (is_file($path) && filemtime($path) + max(1, $ttl) >= time()) {
            $value = json_decode((string)file_get_contents($path), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        $value = $resolver();
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporary, json_encode($value, JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($temporary, $path);
        return $value;
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
