<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

/**
 * A way to keep track of pairs of things when the ordering of the pair does
 * not matter. We do this by maintaining a sort of double adjacency sets.
 */
class Pair_Set
{
    /** @var array<string, array<string, bool>> */
    private array $data = [];
    public function has(string $a, string $b, bool $are_mutually_exclusive): bool
    {
        $first = $this->data[$a] ?? null;
        $result = $first !== null && isset($first[$b]) ? $first[$b] : null;
        if ($result === null) {
            return false;
        }
        // areMutuallyExclusive being false is a superset of being true,
        // hence if we want to know if this PairSet "has" these two with no
        // exclusivity, we have to ensure it was added as such.
        if ($are_mutually_exclusive === false) {
            return $result === false;
        }
        return true;
    }
    public function add(string $a, string $b, bool $are_mutually_exclusive): void
    {
        $this->pair_set_add($a, $b, $are_mutually_exclusive);
        $this->pair_set_add($b, $a, $are_mutually_exclusive);
    }
    private function pair_set_add(string $a, string $b, bool $are_mutually_exclusive): void
    {
        $this->data[$a] ??= [];
        $this->data[$a][$b] = $are_mutually_exclusive;
    }
}