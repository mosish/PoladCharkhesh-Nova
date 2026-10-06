<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/** Which blocks a rich-content field allows (05 §27). Marks are allowed in all. */
enum Profile: string
{
    case Inline = 'inline';
    case Basic = 'basic';
    case Article = 'article';

    /** @return list<string> */
    public function blocks(): array
    {
        return match ($this) {
            self::Inline => ['paragraph'],
            self::Basic => ['paragraph', 'list'],
            self::Article => ['paragraph', 'list', 'heading', 'table', 'figure', 'document'],
        };
    }

    public function maxBlocks(): int
    {
        return $this === self::Inline ? 1 : RichText::MAX_BLOCKS;
    }
}
