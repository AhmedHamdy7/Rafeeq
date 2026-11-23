<?php

namespace App\Domains\Commute\Enums;

enum CommuteDirection: string
{
    case Going = 'going';
    case Return = 'return';

    public const array ALIASES = ['to_work' => 'going', 'to_home' => 'return'];

    public static function fromInput(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(self::ALIASES[$value] ?? $value);
    }

    /**
     * @return list<string>
     */
    public static function accepted(): array
    {
        return [self::Going->value, self::Return->value, ...array_keys(self::ALIASES)];
    }

    public function isReturn(): bool
    {
        return $this === self::Return;
    }
}
