<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** Opaque, scope-bound keyset cursors. No offset or dependence on a surviving anchor row. */
final class TimelineCursor
{
    public static function encode(string $scope, object $row): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'scope' => $scope, 'at' => $row->at,
            'source' => $row->source, 'id' => (string) $row->id], JSON_THROW_ON_ERROR));
    }

    public static function apply($query, array $input, string $scope): bool
    {
        if (isset($input['before'], $input['after'])) {
            throw new InvalidArgumentException('Use before or after, not both');
        }
        $after = isset($input['after']);
        $token = $input[$after ? 'after' : 'before'] ?? null;
        if ($token !== null) {
            try {
                if (! is_string($token) || strlen($token) > 4096) {
                    throw new InvalidArgumentException;
                }
                $c = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
                if (($c['v'] ?? null) !== 1 || ($c['scope'] ?? null) !== $scope
                    || ! is_string($c['at'] ?? null) || ! is_string($c['source'] ?? null)
                    || ! ctype_digit($c['id'] ?? '') || strlen($c['id']) > 20) {
                    throw new InvalidArgumentException;
                }
            } catch (\Throwable) {
                throw new InvalidArgumentException('Invalid cursor for this scope and filter');
            }
            $op = $after ? '>' : '<';
            $query->where(function ($q) use ($c, $op): void {
                $q->where('at', $op, $c['at'])
                    ->orWhere(function ($q) use ($c, $op): void {
                        $q->where('at', $c['at'])->where('source', $op, $c['source']);
                    })->orWhere(function ($q) use ($c, $op): void {
                        $q->where('at', $c['at'])->where('source', $c['source'])->where('id', $op, $c['id']);
                    });
            });
        }
        $direction = $after ? 'asc' : 'desc';
        $query->orderBy('at', $direction)->orderBy('source', $direction)->orderBy('id', $direction);

        return $after;
    }

    /**
     * Anchors describe this page, not whether a navigation link should be shown.
     * Keep both for forward polling, including the request anchor on empty polls.
     * Separate navigation flags suppress known dead-end links in the HTML view.
     */
    public static function metadata($rows, int $limit, bool $more, string $scope, bool $after, bool $cursored, ?string $requestAnchor = null): array
    {
        $newer = $after ? $more : $cursored;
        $older = $after ? $cursored : $more;

        return ['limit' => $limit, 'has_more' => $more, 'truncated' => $more,
            'before' => $rows->isEmpty() ? $requestAnchor : self::encode($scope, $rows->last()),
            'after' => $rows->isEmpty() ? $requestAnchor : self::encode($scope, $rows->first()),
            'has_older' => ! $rows->isEmpty() && $older,
            'has_newer' => ! $rows->isEmpty() && $newer,
            'next_cursor' => ! $more || $rows->isEmpty() ? null : self::encode($scope, $after ? $rows->first() : $rows->last()),
            'direction' => $after ? 'after' : 'before',
            'order' => 'at DESC, source DESC, id DESC',
            'pagination' => 'before means older; after means newer (nearest page first). Entries always newest first. Timestamps UTC. Cursors bind scope and filters; inserts do not shift pages. Edits to event timestamps can reposition entries.'];
    }
}
