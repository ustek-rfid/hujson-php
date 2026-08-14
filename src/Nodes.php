<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * Nodes holds static helpers over {@see Composite} values: trailing-comma state
 * and the comment-aware structural mutations used by Patch (added in the Patch
 * phase).
 *
 * @internal
 */
final class Nodes
{
    /** hasTrailingComma reports whether the composite ends with a trailing comma. */
    public static function hasTrailingComma(Composite $comp): bool
    {
        $last = $comp->lastValue();
        return $last !== null && $last->afterExtra !== null;
    }

    /** setTrailingComma adds or removes a trailing comma on the composite. */
    public static function setTrailingComma(Composite $comp, bool $v): void
    {
        $last = $comp->lastValue();
        if ($last === null) {
            return;
        }
        if ($v && $last->afterExtra === null) {
            $last->afterExtra = '';
        } elseif (!$v && $last->afterExtra !== null) {
            $comp->setAfterExtra($last->afterExtra . ($comp->getAfterExtra() ?? ''));
            $last->afterExtra = null;
        }
    }

    /** copyAt returns a deep copy of the value at index $i, carrying its comments. */
    public static function copyAt(Composite $comp, int $i): Value
    {
        $v = new Value();
        [, $v->beforeExtra] = Extra::extractLeadingComments($comp->getBeforeExtraAt($i + 0), true);
        [, $v->afterExtra] = Extra::extractTrailingComments($comp->getBeforeExtraAt($i + 1), true);
        $v->value = $comp->getAt($i)?->cloneTrimmed();
        return $v;
    }

    /** replaceAt replaces the value at index $i, merging in its comments. */
    public static function replaceAt(Composite $comp, int $i, Value $v): void
    {
        $comp->setBeforeExtraAt($i + 0, Extra::injectLeadingComments($comp->getBeforeExtraAt($i + 0), $v->beforeExtra));
        $comp->setBeforeExtraAt($i + 1, Extra::injectTrailingComments($comp->getBeforeExtraAt($i + 1), $v->afterExtra));
        $comp->setAt($i, $v->value);
    }

    /** insertAt inserts $v at index $i, placing its leading/trailing comments. */
    public static function insertAt(Composite $comp, int $i, Value $v): void
    {
        $comp->insertAt($i, $v->value);
        [$slot, $trailing] = Extra::extractTrailingComments($comp->getBeforeExtraAt($i + 1), false);
        $comp->setBeforeExtraAt($i + 1, $slot);
        $comp->setBeforeExtraAt($i + 0, Extra::injectTrailingComments($comp->getBeforeExtraAt($i + 0), $trailing));
        $comp->setBeforeExtraAt($i + 0, Extra::injectLeadingComments($comp->getBeforeExtraAt($i + 0), $v->beforeExtra));
        $comp->setBeforeExtraAt($i + 1, Extra::injectTrailingComments($comp->getBeforeExtraAt($i + 1), $v->afterExtra));
    }

    /** removeAt removes and returns the value at index $i, carrying its comments. */
    public static function removeAt(Composite $comp, int $i): Value
    {
        $v = new Value();
        [$slot0, $v->beforeExtra] = Extra::extractLeadingComments($comp->getBeforeExtraAt($i + 0), false);
        $comp->setBeforeExtraAt($i + 0, $slot0);
        [$slot1, $v->afterExtra] = Extra::extractTrailingComments($comp->getBeforeExtraAt($i + 1), false);
        $comp->setBeforeExtraAt($i + 1, $slot1);

        $trailing = $comp->getBeforeExtraAt($i + 0);
        if (Extra::hasComment($trailing)) {
            $leading = $comp->getBeforeExtraAt($i + 1) ?? '';
            $leading = substr($leading, Extra::consumeWhitespace($leading, 0));
            $comp->setBeforeExtraAt($i + 1, ($trailing ?? '') . $leading);
        }
        $v->value = $comp->removeAt($i);
        return $v;
    }
}
