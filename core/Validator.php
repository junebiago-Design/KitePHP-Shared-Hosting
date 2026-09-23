<?php
namespace Core;

class Validator
{
    /**
     * Rules: required|min:3|max:100|email|numeric|in:a,b,c
     * Returns [field => [messages]] (empty array when valid).
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $raw = $data[$field] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $label = str_replace('_', ' ', $field);

            foreach (explode('|', $ruleString) as $rule) {
                $parts = explode(':', $rule, 2);
                $name = $parts[0];
                $arg = $parts[1] ?? null;

                if ($name !== 'required' && $value === '') {
                    continue;
                }

                $msg = null;
                switch ($name) {
                    case 'required':
                        if ($value === '') $msg = "The $label field is required.";
                        break;
                    case 'min':
                        if (mb_strlen($value) < (int) $arg) $msg = "The $label must be at least $arg characters.";
                        break;
                    case 'max':
                        if (mb_strlen($value) > (int) $arg) $msg = "The $label may not be longer than $arg characters.";
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) $msg = "The $label must be a valid email.";
                        break;
                    case 'numeric':
                        if (!is_numeric($value)) $msg = "The $label must be a number.";
                        break;
                    case 'confirmed':
                        if ((string) $raw !== (string) ($data[$field . '_confirmation'] ?? '')) $msg = "The $label confirmation does not match.";
                        break;
                    case 'in':
                        if (!in_array($value, explode(',', (string) $arg), true)) $msg = "The selected $label is invalid.";
                        break;
                }
                if ($msg !== null) {
                    $errors[$field][] = $msg;
                    break;
                }
            }
        }
        return $errors;
    }
}
