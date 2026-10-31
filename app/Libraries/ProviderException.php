<?php
namespace App\Libraries;

final class ProviderException extends \RuntimeException
{
    public function __construct(string $message,public readonly int $httpStatus,public readonly bool $definiteRejection=false,public readonly ?string $providerStatus=null)
    {
        parent::__construct($message);
    }
}
