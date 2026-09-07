<?php

namespace App\Dto;

use App\Domain\Item\GtdBucket;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Transport shape for a GTD item, between layers and out over the API.
 *
 * The five metadata fields are read-only and always null in S-01 — they exist so the
 * client's item shape stays stable when S-06 (dates) and S-07 (tags/contexts/flags)
 * start filling them.
 *
 * @implements Arrayable<string, mixed>
 */
class ItemDto implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>|null  $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $note,
        public readonly GtdBucket $bucket,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $dueDate = null,
        public readonly ?array $tags = null,
        public readonly ?string $context = null,
        public readonly ?bool $important = null,
        public readonly ?bool $urgent = null,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        /** @var array<int, string>|null $tags */
        $tags = $item['tags'] ?? null;

        return new self(
            id: (int) $item['id'],
            title: (string) $item['title'],
            note: isset($item['note']) ? (string) $item['note'] : null,
            bucket: GtdBucket::from((string) $item['bucket']),
            createdAt: (string) $item['createdAt'],
            updatedAt: (string) $item['updatedAt'],
            dueDate: isset($item['dueDate']) ? (string) $item['dueDate'] : null,
            tags: $tags,
            context: isset($item['context']) ? (string) $item['context'] : null,
            important: isset($item['important']) ? (bool) $item['important'] : null,
            urgent: isset($item['urgent']) ? (bool) $item['urgent'] : null,
        );
    }

    /**
     * @return array{id: int, title: string, note: string|null, bucket: string, dueDate: string|null, tags: array<int, string>|null, context: string|null, important: bool|null, urgent: bool|null, createdAt: string, updatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'note' => $this->note,
            'bucket' => $this->bucket->value,
            'dueDate' => $this->dueDate,
            'tags' => $this->tags,
            'context' => $this->context,
            'important' => $this->important,
            'urgent' => $this->urgent,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * @return array{id: int, title: string, note: string|null, bucket: string, dueDate: string|null, tags: array<int, string>|null, context: string|null, important: bool|null, urgent: bool|null, createdAt: string, updatedAt: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
