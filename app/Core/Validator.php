<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;

/**
 * Declarative validator.
 * Rules: required, nullable, string, int, numeric, decimal, email, date,
 * datetime, bool, array, min:n, max:n, in:a,b,c, regex:/../, confirmed,
 * gte_field:other (dates/numbers)
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $clean = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $labels
     */
    public function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    /**
     * @return array<string, mixed> validated data
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $v = new self($data, $rules, $labels);
        if (!$v->passes()) {
            throw new ValidationException($v->errors());
        }
        return $v->validated();
    }

    public function passes(): bool
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            $label = $this->labels[$field] ?? __('field.' . $field, [], $field);

            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = __('validation.required', ['field' => $label]);
                } else {
                    $this->clean[$field] = in_array('array', $rules, true) ? [] : null;
                }
                continue;
            }

            $numericType = in_array('int', $rules, true) || in_array('numeric', $rules, true) || in_array('decimal', $rules, true);
            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $param, $value, $numericType, $field);
                if ($error !== null) {
                    $this->errors[$field] = __('validation.' . $error, ['field' => $label, 'param' => (string) $param]);
                    break;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->clean[$field] = $this->cast($rules, $value);
            }
        }
        return $this->errors === [];
    }

    private function check(string $name, ?string $param, mixed $value, bool $numericType, string $field): ?string
    {
        if (!in_array($name, ['required', 'nullable', 'array'], true) && !is_scalar($value)) { return 'string'; }
        switch ($name) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                return is_string($value) ? null : 'string';
            case 'int':
                return (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) ? null : 'int';
            case 'numeric':
                return is_numeric($value) ? null : 'numeric';
            case 'decimal':
                return (is_numeric($value) && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', (string) $value)) ? null : 'decimal';
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'email';
            case 'bool':
                return in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off', 'true', 'false'], true) ? null : 'bool';
            case 'array':
                return is_array($value) ? null : 'array';
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
                return ($d && $d->format('Y-m-d') === $value) ? null : 'date';
            case 'datetime':
                return (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', (string) $value) && strtotime(str_replace('T', ' ', (string) $value)) !== false) ? null : 'datetime';
            case 'min':
                if ($numericType) {
                    return (float) $value >= (float) $param ? null : 'min_num';
                }
                return mb_strlen((string) $value) >= (int) $param ? null : 'min_str';
            case 'max':
                if ($numericType) {
                    return (float) $value <= (float) $param ? null : 'max_num';
                }
                return mb_strlen((string) $value) <= (int) $param ? null : 'max_str';
            case 'in':
                return in_array((string) $value, explode(',', (string) $param), true) ? null : 'in';
            case 'regex':
                return preg_match((string) $param, (string) $value) ? null : 'regex';
            case 'confirmed':
                return ($this->data[$field . '_confirmation'] ?? null) === $value ? null : 'confirmed';
            case 'gte_field':
                $other = $this->data[(string) $param] ?? null;
                if ($other === null || $other === '') {
                    return null;
                }
                return (string) $value >= (string) $other ? null : 'gte_field';
            case 'password':
                // >= 10 chars, upper, lower, digit
                return (mb_strlen((string) $value) >= 10 && preg_match('/[A-Z]/', (string) $value) && preg_match('/[a-z]/', (string) $value) && preg_match('/\d/', (string) $value)) ? null : 'password';
        }
        return null;
    }

    private function cast(array $rules, mixed $value): mixed
    {
        if (in_array('int', $rules, true)) {
            return (int) $value;
        }
        if (in_array('bool', $rules, true)) {
            return in_array($value, [true, 1, '1', 'on', 'true'], true);
        }
        if (in_array('datetime', $rules, true)) {
            return str_replace('T', ' ', (string) $value);
        }
        return $value;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->clean;
    }
}
