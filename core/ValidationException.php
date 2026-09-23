<?php
namespace Core;

class ValidationException extends \Exception
{
    public $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422);
        $this->errors = $errors;
    }
}
