<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/** FindTrait provides Find for {@see Value} (a port of find.go). */
trait FindTrait
{
    /**
     * find locates the value specified by a JSON pointer (RFC 6901). It returns
     * null if the value does not exist or the pointer is invalid. If an object
     * has multiple members with a name, the first is returned.
     */
    public function find(string $ptr): ?Value
    {
        try {
            return $this->findInner(new FindState($ptr))->value;
        } catch (FindException) {
            return null;
        }
    }

    /**
     * findState resolves $ptr, returning [state, error]. Used by Patch, which
     * inspects the error and partial state.
     *
     * @return array{0:FindState,1:?FindException}
     */
    private function findState(string $ptr): array
    {
        try {
            return [$this->findInner(new FindState($ptr)), null];
        } catch (FindException $e) {
            return [$e->state, $e];
        }
    }

    /** @throws FindException */
    private function findInner(FindState $s): FindState
    {
        // An empty pointer denotes the value itself.
        $s->value = $this;
        if (substr($s->pointer, $s->offset) === '') {
            return $s;
        }
        $comp = $this->value;
        if (!($comp instanceof Composite)) {
            throw new FindException(
                $s,
                false,
                'invalid pointer: cannot index into literal at ' . substr($s->pointer, 0, $s->offset)
            );
        }

        // There must be one or more fragments.
        $s->parent = null;
        $s->idx = 0;
        $s->name = '';
        if (!str_starts_with(substr($s->pointer, $s->offset), '/')) {
            throw new FindException($s, false, 'invalid pointer: lacks a forward slash prefix');
        }
        $n = 1; // len("/")
        $i = strpos(substr($s->pointer, $s->offset + $n), '/');
        if ($i !== false) {
            $n += $i;
        } else {
            $n = strlen($s->pointer) - $s->offset;
        }
        $s->offset += $n;

        // Unescape the name if necessary (RFC 6901, section 4).
        $name = substr($s->pointer, $s->offset - $n, $n);
        if (str_contains($name, '~')) {
            $name = str_replace('~1', '/', $name);
            $name = str_replace('~0', '~', $name);
        }
        $name = substr($name, 1); // drop the leading '/'

        // Index into the object or array.
        $s->parent = $comp;
        $s->name = $name;
        $s->idx = $comp->length();
        if ($comp instanceof ObjectValue) {
            foreach ($comp->members as $j => $m) {
                $lit = $m->name->value;
                if ($lit instanceof Literal && $lit->equalString($name)) {
                    $s->idx = $j;
                    return $comp->members[$j]->value->findInner($s);
                }
            }
        } else {
            /** @var ArrayValue $comp */
            if ($name === '-') {
                throw new FindException($s, true);
            }
            $idx = self::parseArrayIndex($name);
            if ($idx === null || ($idx === 0 && $name !== '0')) {
                throw new FindException($s, false, 'invalid array index: ' . $name);
            }
            if ($idx < count($comp->elements)) {
                $s->idx = $idx;
                return $comp->elements[$idx]->findInner($s);
            }
        }
        throw new FindException($s, true);
    }

    /**
     * parseArrayIndex mirrors strconv.ParseUint(name, 10, 0): base-10 digits with
     * no sign, within the uint64 range. Leading zeros are permitted (the caller
     * applies the RFC's "no leading zero" rule). Returns null on failure.
     */
    private static function parseArrayIndex(string $name): ?int
    {
        if ($name === '' || !ctype_digit($name)) {
            return null;
        }
        // Reject values above uint64 max (18446744073709551615).
        if (strlen($name) > 20 || (strlen($name) === 20 && strcmp($name, '18446744073709551615') > 0)) {
            return null;
        }
        // Values above PHP_INT_MAX saturate but are never < count(elements).
        return (int) $name;
    }
}
