<?php

declare(strict_types=1);

namespace Nova\Admin;

/** Persian headlines for refused changes; the service's own message is shown under them as the technical detail. */
final class Messages
{
    public static function catalogue(string $reason): string
    {
        return match ($reason) {
            'stale' => 'این صفحه در این فاصله توسط کس دیگری تغییر کرده است. صفحه را دوباره باز کنید و تغییر خود را تکرار کنید.',
            'invalid' => 'مقدار واردشده پذیرفته نشد.',
            'not_found' => 'مورد موردنظر پیدا نشد.',
            'conflict' => 'این تغییر با وضعیت فعلی داده‌ها سازگار نیست.',
            'designation_collision' => 'این کد با کد دیگری در کاتالوگ یکسان است (پس از یکسان‌سازی نگارش).',
            'forbidden' => 'این کار فقط از عهده مالک برمی‌آید.',
            default => 'تغییر انجام نشد.',
        };
    }
}
