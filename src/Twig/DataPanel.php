<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Twig;

class DataPanel
{
    public const TYPE_ARRAY = 'array';
    public const TYPE_JSON = 'json';
    public const TYPE_STRING = 'string';

    public function __construct(
        protected string $title,
        protected mixed $data,
        protected string $type = self::TYPE_ARRAY
    ) {
    }

    public function getTitle(): string
    {
        return $this->title ?? "";
    }

    /**
     * An array for TYPE_ARRAY panels; a string for TYPE_STRING ones; any decoded JSON value (array or scalar) for
     * TYPE_JSON ones.
     */
    public function getData(): mixed
    {
        return $this->data ?? [];
    }

    public function getType(): string
    {
        return $this->type ?? "";
    }
}
