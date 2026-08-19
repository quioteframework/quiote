<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\ListableCassetteStoreInterface;
use Quiote\Support\Compiler\Diagnostic;
use Throwable;

/**
 * Decodes every cassette a {@see ListableCassetteStoreInterface} holds into
 * the small summary shape `cassette:list` and `cassette:prune` both filter
 * and sort in PHP -- shared so the two commands never disagree about what a
 * "row" is or how an unreadable cassette is reported.
 */
trait CollectsCassetteRows
{
    /**
     * @return array{0: list<array{id: string, slug: string, recorded_at: ?string, route: ?string, status: ?int, trigger: ?string}>, 1: list<Diagnostic>}
     */
    private function collectCassetteRows(ListableCassetteStoreInterface $store): array
    {
        $rows = [];
        $diagnostics = [];
        foreach ($store->slugs() as $slug) {
            try {
                $cassette = $store->get(CassetteId::fromRaw($slug));
            } catch (Throwable $e) {
                $diagnostics[] = new Diagnostic(Diagnostic::SEVERITY_WARNING, 'CASSETTE_UNREADABLE', $e->getMessage(), $slug);
                continue;
            }
            if ($cassette === null) {
                continue;
            }
            $id = $cassette->meta['id'] ?? null;
            $recordedAt = $cassette->meta['recorded_at'] ?? null;
            $route = $cassette->resolved['route'] ?? null;
            $status = $cassette->response['status'] ?? null;
            $trigger = $cassette->meta['trigger'] ?? null;
            $rows[] = [
                'id' => is_string($id) ? $id : $slug,
                'slug' => $slug,
                'recorded_at' => is_string($recordedAt) ? $recordedAt : null,
                'route' => is_string($route) ? $route : null,
                'status' => is_int($status) ? $status : null,
                'trigger' => is_string($trigger) ? $trigger : null,
            ];
        }

        return [$rows, $diagnostics];
    }
}
