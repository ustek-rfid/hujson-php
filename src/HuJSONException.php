<?php

declare(strict_types=1);

namespace Ustek\HuJSON;

/**
 * HuJSONException is the public error thrown by the {@see HuJSON} facade and by
 * {@see Value::patch()}. Its message mirrors the Go implementation's error text,
 * e.g. "hujson: line 1, column 6: invalid character ',' after top-level value".
 */
class HuJSONException extends \RuntimeException
{
}
