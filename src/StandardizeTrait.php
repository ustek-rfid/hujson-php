<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * StandardizeTrait provides IsStandard/Minimize/Standardize for {@see Value}
 * (a port of standard.go).
 */
trait StandardizeTrait
{
    /**
     * isStandard reports whether this is standard JSON (no comments and no
     * trailing commas).
     */
    public function isStandard(): bool
    {
        if (!Extra::isStandard($this->beforeExtra)) {
            return false;
        }
        if ($this->value instanceof Composite) {
            foreach ($this->value->allValues() as $v2) {
                if (!$v2->isStandard()) {
                    return false;
                }
            }
            if (Nodes::hasTrailingComma($this->value) || !Extra::isStandard($this->value->getAfterExtra())) {
                return false;
            }
        }
        if (!Extra::isStandard($this->afterExtra)) {
            return false;
        }
        return true;
    }

    /**
     * minimize removes all whitespace, comments, and trailing commas, making the
     * value compliant with standard JSON per RFC 8259.
     */
    public function minimize(): void
    {
        $this->minimizeInner();
        $this->updateOffsets();
    }

    private function minimizeInner(): void
    {
        $this->beforeExtra = null;
        if ($this->value instanceof Composite) {
            foreach ($this->value->allValues() as $v3) {
                $v3->minimizeInner();
            }
            Nodes::setTrailingComma($this->value, false);
            $this->value->setAfterExtra(null);
        }
        $this->afterExtra = null;
    }

    /**
     * standardize replaces all comments and trailing commas with spaces to
     * preserve the original line numbers and byte offsets.
     */
    public function standardize(): void
    {
        $this->standardizeInner();
        $this->updateOffsets(); // should be a no-op if offsets are already correct
    }

    private function standardizeInner(): void
    {
        $this->beforeExtra = Extra::standardize($this->beforeExtra);
        if ($this->value instanceof Composite) {
            $comp = $this->value;
            foreach ($comp->allValues() as $v2) {
                $v2->standardizeInner();
            }
            $last = $comp->lastValue();
            if ($last !== null && $last->afterExtra !== null) {
                $comp->setAfterExtra($last->afterExtra . ' ' . ($comp->getAfterExtra() ?? ''));
                $last->afterExtra = null;
            }
            $comp->setAfterExtra(Extra::standardize($comp->getAfterExtra()));
        }
        $this->afterExtra = Extra::standardize($this->afterExtra);
    }
}
