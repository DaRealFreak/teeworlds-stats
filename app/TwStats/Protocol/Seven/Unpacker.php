<?php

namespace App\TwStats\Protocol\Seven;

/**
 * Reads a 0.7 message payload field by field. Like the reference CUnpacker, any read past the
 * end of the buffer raises a sticky error flag rather than throwing, so a truncated/garbage
 * packet is dropped by the caller instead of aborting the scrape.
 */
final class Unpacker
{
    private int $offset = 0;
    private bool $error = false;

    public function __construct(private readonly string $buffer)
    {
    }

    public function getInt(): int
    {
        if ($this->error || $this->offset >= strlen($this->buffer)) {
            $this->error = true;
            return 0;
        }

        try {
            [$value, $this->offset] = VariableInt::unpack($this->buffer, $this->offset);
        } catch (\Throwable) {
            $this->error = true;
            return 0;
        }

        if ($this->offset > strlen($this->buffer)) {
            $this->error = true;
        }

        return $value;
    }

    public function getString(): string
    {
        if ($this->error) {
            return '';
        }

        $end = strpos($this->buffer, "\x00", $this->offset);
        if ($end === false) {
            $this->error = true;
            return '';
        }

        $string = substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $string;
    }

    public function getRaw(int $length): string
    {
        if ($this->error || $this->offset + $length > strlen($this->buffer)) {
            $this->error = true;
            return '';
        }

        $raw = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $raw;
    }

    public function error(): bool
    {
        return $this->error;
    }

    public function remaining(): int
    {
        return max(0, strlen($this->buffer) - $this->offset);
    }
}
