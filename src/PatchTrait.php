<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/** PatchTrait provides Patch for {@see Value} (a port of patch.go). */
trait PatchTrait
{
    /**
     * patch applies a JSON Patch (RFC 6902) to the value. The patch may be in
     * HuJSON format; comments around and within inserted values are preserved.
     * On failure the value may be left partially mutated. It throws
     * {@see HuJSONException} on any error.
     */
    public function patch(string $patch): void
    {
        $ops = self::parsePatchOps($patch);
        foreach ($ops as $i => $op) {
            switch ($op->op) {
                case 'add':
                    $this->patchAdd($i, $op);
                    break;
                case 'remove':
                case 'replace':
                    $this->patchRemoveOrReplace($i, $op);
                    break;
                case 'move':
                case 'copy':
                    $this->patchMoveOrCopy($i, $op);
                    break;
                case 'test':
                    $this->patchTest($i, $op);
                    break;
            }
        }
    }

    /** @return list<PatchOperation> */
    private static function parsePatchOps(string $patch): array
    {
        $v = HuJSON::parse($patch);
        $arr = $v->value;
        if (!($arr instanceof ArrayValue)) {
            throw new HuJSONException('hujson: patch must be a JSON array');
        }

        $ops = [];
        foreach ($arr->elements as $i => $e) {
            $obj = $e->value;
            if (!($obj instanceof ObjectValue)) {
                throw new HuJSONException("hujson: patch operation $i: must be a JSON object");
            }
            $seen = [];
            $op = new PatchOperation();
            foreach ($obj->members as $j => $m) {
                $lit = $m->name->value;
                $name = $lit instanceof Literal ? $lit->asString() : '';
                if (isset($seen[$name])) {
                    throw new HuJSONException("hujson: patch operation $i: duplicate name " . Chars::goQuote($name));
                }
                $seen[$name] = true;
                switch ($name) {
                    case 'op':
                        $mv = $m->value->value;
                        if (!($mv instanceof Literal) || $mv->kind() !== Kind::STRING) {
                            throw new HuJSONException("hujson: patch operation $i: member \"op\" must be a JSON string");
                        }
                        $opType = $mv->asString();
                        if (in_array($opType, ['add', 'remove', 'replace', 'move', 'copy', 'test'], true)) {
                            $op->op = $opType;
                        } else {
                            throw new HuJSONException("hujson: patch operation $i: unknown operation " . Chars::goQuote($opType));
                        }
                        break;
                    case 'path':
                        $mv = $m->value->value;
                        if (!($mv instanceof Literal) || $mv->kind() !== Kind::STRING) {
                            throw new HuJSONException("hujson: patch operation $i: member \"path\" must be a JSON string");
                        }
                        $op->path = $mv->asString();
                        break;
                    case 'from':
                        $mv = $m->value->value;
                        if (!($mv instanceof Literal) || $mv->kind() !== Kind::STRING) {
                            throw new HuJSONException("hujson: patch operation $i: member \"from\" must be a JSON string");
                        }
                        $op->from = $mv->asString();
                        break;
                    case 'value':
                        $val = clone $m->value;
                        [, $val->beforeExtra] = Extra::extractLeadingComments($obj->getBeforeExtraAt($j + 0), true);
                        [, $val->afterExtra] = Extra::extractTrailingComments($obj->getBeforeExtraAt($j + 1), true);
                        $op->value = $val;
                        break;
                }
            }
            if (!isset($seen['op'])) {
                throw new HuJSONException("hujson: patch operation $i: missing required member \"op\"");
            }
            if (!isset($seen['path'])) {
                throw new HuJSONException("hujson: patch operation $i: missing required member \"path\"");
            }
            if (!isset($seen['from']) && ($op->op === 'move' || $op->op === 'copy')) {
                throw new HuJSONException("hujson: patch operation $i: missing required member \"from\"");
            }
            if (!isset($seen['value']) && ($op->op === 'add' || $op->op === 'replace' || $op->op === 'test')) {
                throw new HuJSONException("hujson: patch operation $i: missing required member \"value\"");
            }
            $ops[] = $op;
        }
        return $ops;
    }

    private function patchAdd(int $i, PatchOperation $op): void
    {
        [$s, $err] = $this->findState($op->path);
        if ($err !== null && (!$err->notFound || $s->offset !== strlen($s->pointer))) {
            throw new HuJSONException("hujson: patch operation $i: " . $err->getMessage());
        }
        if ($s->parent === null) {
            // Only occurs for the root value.
            $this->copyFrom($op->value);
            return;
        }
        $parent = $s->parent;
        if ($parent instanceof ObjectValue) {
            if ($s->idx < $parent->length()) {
                Nodes::replaceAt($parent, $s->idx, $op->value);
            } else {
                Nodes::insertAt($parent, $s->idx, $op->value);
                $parent->members[$s->idx]->name->value = Literal::fromString($s->name);
            }
        } else {
            Nodes::insertAt($parent, $s->idx, $op->value);
        }
    }

    private function patchRemoveOrReplace(int $i, PatchOperation $op): void
    {
        [$s, $err] = $this->findState($op->path);
        if ($err !== null) {
            throw new HuJSONException("hujson: patch operation $i: " . $err->getMessage());
        }
        if ($s->parent === null) {
            throw new HuJSONException("hujson: patch operation $i: cannot {$op->op} root value");
        }
        if ($op->op === 'remove') {
            Nodes::removeAt($s->parent, $s->idx);
        } else {
            Nodes::replaceAt($s->parent, $s->idx, $op->value);
        }
    }

    private function patchMoveOrCopy(int $i, PatchOperation $op): void
    {
        if ($op->from === '' || ($op->op === 'move' && self::hasPathPrefix($op->path, $op->from))) {
            throw new HuJSONException(
                "hujson: patch operation $i: cannot {$op->op} " . Chars::goQuote($op->from) . ' into ' . Chars::goQuote($op->path)
            );
        }
        [$sFrom, $err] = $this->findState($op->from);
        if ($err !== null) {
            throw new HuJSONException("hujson: patch operation $i: " . $err->getMessage());
        }
        if ($op->op === 'move') {
            $op->value = Nodes::removeAt($sFrom->parent, $sFrom->idx);
        } else {
            $op->value = Nodes::copyAt($sFrom->parent, $sFrom->idx);
        }
        $this->patchAdd($i, $op);
    }

    private function patchTest(int $i, PatchOperation $op): void
    {
        [$s, $err] = $this->findState($op->path);
        if ($err !== null) {
            throw new HuJSONException("hujson: patch operation $i: " . $err->getMessage());
        }
        if (!self::equalValueFor($s->value, $op->value)) {
            throw new HuJSONException("hujson: patch operation $i: values differ at " . Chars::goQuote($op->path));
        }
    }

    /** hasPathPrefix is strings.HasPrefix where the prefix must end on a segment boundary. */
    private static function hasPathPrefix(string $s, string $prefix): bool
    {
        if (str_starts_with($s, $prefix)) {
            return strlen($s) === strlen($prefix) || $s[strlen($prefix)] === '/';
        }
        return false;
    }

    private function copyFrom(Value $o): void
    {
        $this->beforeExtra = $o->beforeExtra;
        $this->startOffset = $o->startOffset;
        $this->value = $o->value;
        $this->endOffset = $o->endOffset;
        $this->afterExtra = $o->afterExtra;
    }

    /**
     * equalValueFor reports semantic equality, reproducing Go's equalValue quirks:
     * both sides are standardized and JSON-decoded (with all numbers coerced to
     * float64), invalid UTF-8 is substituted, and any non-finite number (integer
     * overflow) makes a side unequal.
     */
    private static function equalValueFor(Value $x, Value $y): bool
    {
        [$okx, $vx] = self::unmarshalForCompare($x);
        [$oky, $vy] = self::unmarshalForCompare($y);
        return $okx && $oky && self::deepEqual($vx, $vy);
    }

    /** @return array{0:bool,1:mixed} */
    private static function unmarshalForCompare(Value $v): array
    {
        $c = clone $v;
        $c->standardize();
        $decoded = json_decode($c->pack(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, null];
        }
        try {
            return [true, self::floatify($decoded)];
        } catch (\RuntimeException) {
            return [false, null];
        }
    }

    private static function floatify(mixed $x): mixed
    {
        if (is_int($x)) {
            return (float) $x;
        }
        if (is_float($x)) {
            if (!is_finite($x)) {
                throw new \RuntimeException('non-finite number');
            }
            return $x;
        }
        if (is_array($x)) {
            $out = [];
            foreach ($x as $k => $val) {
                $out[$k] = self::floatify($val);
            }
            return $out;
        }
        return $x;
    }

    private static function deepEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::deepEqual($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($a) || is_array($b)) {
            return false;
        }
        return $a === $b;
    }
}
