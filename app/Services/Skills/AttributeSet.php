<?php

namespace App\Services\Skills;

final readonly class AttributeSet
{
    public function __construct(
        public int $charisma,
        public int $intelligence,
        public int $memory,
        public int $perception,
        public int $willpower,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public function get(string $attribute): int
    {
        return $this->{$attribute};
    }

    public function add(self $other): self
    {
        return new self(
            $this->charisma + $other->charisma,
            $this->intelligence + $other->intelligence,
            $this->memory + $other->memory,
            $this->perception + $other->perception,
            $this->willpower + $other->willpower,
        );
    }

    public function subtract(self $other): self
    {
        return new self(
            $this->charisma - $other->charisma,
            $this->intelligence - $other->intelligence,
            $this->memory - $other->memory,
            $this->perception - $other->perception,
            $this->willpower - $other->willpower,
        );
    }

    public function total(): int
    {
        return $this->charisma + $this->intelligence + $this->memory + $this->perception + $this->willpower;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'charisma' => $this->charisma,
            'intelligence' => $this->intelligence,
            'memory' => $this->memory,
            'perception' => $this->perception,
            'willpower' => $this->willpower,
        ];
    }
}
