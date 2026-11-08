<?php

declare(strict_types=1);

use MiraFive\Http\Response;
use MiraFive\Symfony\Tests\Support\BundleTestCase;

uses(BundleTestCase::class)->in('Feature');

/**
 * @param  array<array-key, mixed>|null  $body
 * @param  array<string, string>  $headers
 */
function answer(int $status, ?array $body = null, array $headers = []): Response
{
    return new Response($status, $headers, $body === null ? '' : (string) json_encode($body));
}
