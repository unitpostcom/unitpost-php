<?php

declare(strict_types=1);

namespace Unitpost;

final class UnitpostError
{
    public string $code;
    public string $message;
    public int $status;
    public ?string $requestId;
    /** @var list<array{field?: string, message?: string}> */
    public array $details;

    /** @param list<array{field?: string, message?: string}> $details */
    public function __construct(string $code, string $message, int $status, ?string $requestId, array $details = [])
    {
        $this->code = $code;
        $this->message = $message;
        $this->status = $status;
        $this->requestId = $requestId;
        $this->details = $details;
    }
}

/**
 * Thrown only where a Result cannot carry the error: a failed page request in
 * a listAll() generator. $error is the same typed UnitpostError a Result holds.
 */
final class UnitpostException extends \RuntimeException
{
    public UnitpostError $error;

    public function __construct(UnitpostError $error)
    {
        parent::__construct($error->message, $error->status);
        $this->error = $error;
    }
}

/**
 * @template T
 */
final class Result
{
    /** @var T|null */
    public mixed $data;
    public ?UnitpostError $error;

    /** @param T|null $data */
    public function __construct(mixed $data, ?UnitpostError $error)
    {
        $this->data = $data;
        $this->error = $error;
    }
}
