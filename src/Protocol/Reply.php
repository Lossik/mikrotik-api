<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Protocol;

use Lossik\DeviceCommunication\ProtocolException;

/**
 * One reply sentence of the device: its type (`!re`, `!done`, `!trap`, `!empty`, `!fatal`), its attributes
 * (`=key=value`), its `.tag` and its other words (the reason of `!fatal`).
 */
final class Reply
{
    /**
     * @param array<string, string> $attributes
     * @param list<string> $otherWords
     */
    private function __construct(
        public readonly string $type,
        public readonly array $attributes,
        public readonly ?string $tag,
        public readonly array $otherWords,
    ) {
    }

    /**
     * @param list<string> $words
     * @throws ProtocolException the sentence has no type word
     */
    public static function fromWords(array $words): self
    {
        $type = $words[0] ?? '';
        if (!str_starts_with($type, '!')) {
            throw new ProtocolException('A reply sentence without a type word');
        }
        $attributes = [];
        $tag = null;
        $other = [];
        foreach (array_slice($words, 1) as $word) {
            if (str_starts_with($word, '=')) {
                // "=k=v": only the first "=" after the key separates; "=k=" is an empty value
                $pair = explode('=', substr($word, 1), 2);
                $attributes[$pair[0]] = $pair[1] ?? '';
            } elseif (str_starts_with($word, '.tag=')) {
                $tag = substr($word, 5);
            } else {
                $other[] = $word;
            }
        }

        return new self($type, $attributes, $tag, $other);
    }
}
