<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Core\Sql;
use PDO;

/** Audit events in Persian for the dashboard's recent activity and the publish change list (A1 note 6, A7 note 1). */
final class Activity
{
    /** action => Persian description; {label} is the entity label, shown Latin and isolated. */
    public const array VERBS = [
        'product.create' => 'پیش‌نویس محصول {label} را ساخت',
        'product.update' => 'مشخصات محصول {label} را ویرایش کرد',
        'product.status' => 'وضعیت محصول {label} را تغییر داد',
        'product.delete' => 'پیش‌نویس محصول {label} را حذف کرد',
        'product.verify' => 'صفحه محصول را برای نمایه‌سازی تأیید کرد',
        'variant.create' => 'نوع پسوندی {label} را با منبع ثبت کرد',
        'variant.update' => 'نوع پسوندی {label} را ویرایش کرد',
        'spelling.create' => 'نگارش هم‌ارز {label} را افزود',
        'spelling.delete' => 'نگارش هم‌ارز {label} را حذف کرد',
        'value.create' => 'مقدار {label} را ثبت کرد',
        'value.update' => 'مقدار {label} را ویرایش کرد',
        'value.delete' => 'مقدار {label} را حذف کرد',
        'product.verification_reset' => 'تأیید صفحه {label} به‌خاطر تغییر داده هسته برداشته شد',
        'content.product_description' => 'توضیح محصول {label} را ویرایش کرد',
        'source.create' => 'منبع «{label}» را افزود',
        'relationship.standalone' => 'کد {label} را محصول مستقل تأیید کرد',
        'relationship.resolve_variant' => 'کد {label} را نوع پسوندی ثبت کرد',
        'publish' => 'نسخه {label} را منتشر کرد',
        'publish.rollback' => 'سایت را به نسخه {label} برگرداند',
        'user.create' => 'حساب {label} را ساخت',
        'user.password_reset' => 'گذرواژه حساب {label} را بازنشانی کرد',
        'user.totp_reset' => 'ورود دومرحله‌ای حساب {label} را بازنشانی کرد',
        'migration.no_backup' => 'مهاجرت توسعه بدون پشتیبان اجرا شد ({label})',
    ];

    /** @return list<array<string, mixed>> */
    public static function recent(PDO $pdo, int $limit = 8): array
    {
        return self::describe(Sql::rows($pdo, "SELECT a.*, u.display_name FROM audit_event a LEFT JOIN app_user u ON u.id = a.user_id WHERE a.action NOT LIKE 'auth.%' ORDER BY a.id DESC LIMIT {$limit}"));
    }

    /**
     * @param list<array<string, scalar|null>> $rows audit_event rows joined with the user's display_name
     *
     * @return list<array{id: int, who: string, text: string, label: string, at: string, action: string}>
     */
    public static function describe(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $after = \is_string($r['after_text'] ?? null) ? json_decode($r['after_text'], true) : null;
            $origin = \is_array($after) && \is_array($after['by'] ?? null) ? ($after['by']['origin'] ?? null) : null;
            $who = $r['display_name'] !== null ? (string) $r['display_name'] : match ($origin) {
                'cli' => 'خط فرمان',
                'recovery' => 'بازیابی خودکار',
                default => 'سیستم',
            };
            $action = (string) $r['action'];
            $out[] = ['id' => (int) $r['id'], 'who' => $who, 'text' => self::VERBS[$action] ?? 'رویداد ثبت شد: ' . $action, 'label' => (string) ($r['entity_label'] ?? ''), 'at' => (string) $r['occurred_at'], 'action' => $action];
        }

        return $out;
    }
}
