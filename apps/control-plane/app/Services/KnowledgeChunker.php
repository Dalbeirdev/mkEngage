<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Paragraph-aware chunking (§19 "chunking"): split on blank lines, pack
 * paragraphs into ~800-char chunks; paragraphs longer than the budget are
 * hard-split. Deterministic — same body, same chunks (checksum-friendly).
 */
final class KnowledgeChunker
{
    private const TARGET_SIZE = 800;

    /** @return list<string> */
    public function chunk(string $body): array
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($body)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== ''));

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            // Hard-split oversized paragraphs.
            while (mb_strlen($paragraph) > self::TARGET_SIZE) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = mb_substr($paragraph, 0, self::TARGET_SIZE);
                $paragraph = trim(mb_substr($paragraph, self::TARGET_SIZE));
            }

            if ($paragraph === '') {
                continue;
            }

            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            if (mb_strlen($candidate) > self::TARGET_SIZE) {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
