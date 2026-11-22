<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $rule,
        public string $file,
        public int $line,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return sprintf('[%s] %s:%d %s', $this->rule, $this->file, $this->line, $this->message);
    }
}
