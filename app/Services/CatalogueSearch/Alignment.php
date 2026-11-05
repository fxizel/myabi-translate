<?php

namespace App\Services\CatalogueSearch;

final class Alignment
{
    /** Minimum lexical cost with distinct occurrences; prefer an ordered alignment at equal cost. */
    public function match(array $edges, Budget $budget): ?array
    {
        if (in_array([], $edges, true)) {
            return null;
        }
        $positions = [];
        $lower = 0;
        foreach ($edges as $row) {
            $positions += array_fill_keys(array_keys($row), true);
            $lower += min(array_column($row, 'cost'));
        }
        ksort($positions);
        $positions = array_keys($positions);
        if (count($positions) < count($edges)) {
            return null;
        }
        if (count($positions) > 10000) {
            throw new SearchStopped;
        }
        $ordered = $this->ordered($edges, $budget);
        if ($ordered !== null && $ordered['cost'] === $lower) {
            return $ordered;
        }
        // Rectangular Hungarian assignment. Missing edges have an unreachable cost.
        $n = count($edges);
        $m = count($positions);
        $u = array_fill(0, $n + 1, 0);
        $v = $p = $way = array_fill(0, $m + 1, 0);
        $infinity = 100000000;
        for ($i = 1; $i <= $n; $i++) {
            $budget->check();
            $p[0] = $i;
            $j0 = 0;
            $minv = array_fill(0, $m + 1, $infinity);
            $used = array_fill(0, $m + 1, false);
            do {
                $budget->check();
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = $infinity;
                $j1 = 0;
                for ($j = 1; $j <= $m; $j++) {
                    if ($used[$j]) {
                        continue;
                    }
                    $cost = ($edges[$i0 - 1][$positions[$j - 1]]['cost'] ?? $infinity) - $u[$i0] - $v[$j];
                    if ($cost < $minv[$j]) {
                        $minv[$j] = $cost;
                        $way[$j] = $j0;
                    }
                    if ($minv[$j] < $delta) {
                        $delta = $minv[$j];
                        $j1 = $j;
                    }
                }
                if ($delta >= $infinity / 2) {
                    return null;
                }
                for ($j = 0; $j <= $m; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }
                $j0 = $j1;
            } while ($p[$j0] !== 0);
            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }
        $chosen = [];
        for ($j = 1; $j <= $m; $j++) {
            if ($p[$j] !== 0) {
                $chosen[$p[$j] - 1] = $positions[$j - 1];
            }
        }
        ksort($chosen);
        $result = $this->result($edges, array_values($chosen));

        return $ordered !== null && $ordered['cost'] === $result['cost'] ? $ordered : $result;
    }

    private function ordered(array $edges, Budget $budget): ?array
    {
        $previous = [-1 => ['cost' => 0, 'positions' => []]];
        foreach ($edges as $row) {
            $budget->check();
            ksort($row);
            $prior = array_keys($previous);
            $offset = 0;
            $best = null;
            $next = [];
            foreach ($row as $position => $edge) {
                while ($offset < count($prior) && $prior[$offset] < $position) {
                    $candidate = $previous[$prior[$offset++]];
                    if ($best === null || $candidate['cost'] < $best['cost']) {
                        $best = $candidate;
                    }
                }
                if ($best !== null) {
                    $next[$position] = ['cost' => $best['cost'] + $edge['cost'], 'positions' => [...$best['positions'], $position]];
                }
            }
            if (! $next) {
                return null;
            }
            $previous = $next;
        }
        uasort($previous, fn ($a, $b) => $a['cost'] <=> $b['cost']);

        return $this->result($edges, reset($previous)['positions']);
    }

    private function result(array $edges, array $positions): array
    {
        $cost = $disorder = 0;
        $ranges = [];
        foreach ($positions as $i => $position) {
            $edge = $edges[$i][$position];
            $cost += $edge['cost'];
            $ranges[] = [$edge['start'], $edge['length']];
            foreach (array_slice($positions, 0, $i) as $before) {
                $disorder += $before > $position ? 1 : 0;
            }
        }

        return compact('cost', 'disorder', 'ranges');
    }
}
