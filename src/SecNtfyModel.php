<?php declare(strict_types=1);

namespace SecNtfyPHP;

final class SecNtfyModel
{
    public string $title = '';
    public string $body = '';
    public string $image = '';
    public SecNtfyNotification $notification;

    public function __construct()
    {
        $this->notification = new SecNtfyNotification();
    }
}

final class SecNtfyNotification
{
    public SecNtfySound $sound;
    public bool $critical = false;
    public int $priority = 0;
    public int $mutablecontent = 0;

    public function __construct()
    {
        $this->sound = new SecNtfySound();
    }
}

final class SecNtfySound
{
    public string $name = '';
    public float $volume = 0.0;
}

final class SecNtfyResponse
{
    public string $Message = '';
    public string $Token = '';
    public int $Status = 0;
}

final class ResultResponse
{
    public ?string $Message = '';
    public string $Error = '';
    public int $Status = 0;

    public static function error(string $msg, int $status = 500): self
    {
        $r = new self();
        $r->Message = $msg;
        $r->Error = $msg;
        $r->Status = $status;
        return $r;
    }

    public static function ok(string $msg, int $status): self
    {
        $r = new self();
        $r->Message = $msg;
        $r->Error = '';
        $r->Status = $status;
        return $r;
    }
}