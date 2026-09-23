<?php
namespace Core;

class HttpException extends \Exception
{
    public function __construct(int $status = 500, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (Response::$texts[$status] ?? 'Error'), $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
