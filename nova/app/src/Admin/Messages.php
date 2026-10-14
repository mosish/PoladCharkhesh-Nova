<?php

declare(strict_types=1);

namespace Nova\Admin;

/** Persian headlines for refused changes; the service's own message is shown under them as the technical detail. */
final class Messages
{
    public static function media(string $reason): string
    {
        return match ($reason) {
            'too_large' => 'فایل از سقف ایمنی بزرگ‌تر است.',
            'unsupported' => 'نوع فایل پذیرفته نمی‌شود. فقط JPEG، PNG و WebP (و SVG برای مالک) یا PDF.',
            'invalid' => 'فایل یا مقدار واردشده پذیرفته نشد.',
            'forbidden' => 'بارگذاری SVG فقط از عهده مالک برمی‌آید. تصویر شطرنجی یا PDF بارگذاری کنید، یا از مالک بخواهید.',
            'not_found' => 'مورد موردنظر پیدا نشد.',
            'duplicate' => 'این مورد از پیش وجود دارد.',
            'referenced' => 'این مورد در جایی به کار رفته و حذف نمی‌شود. نخست آن را از جاهای نام‌برده بردارید.',
            'stale' => 'این مورد در این فاصله توسط کس دیگری تغییر کرده است. صفحه را دوباره باز کنید و تغییر خود را تکرار کنید.',
            default => 'انجام نشد.',
        };
    }

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
