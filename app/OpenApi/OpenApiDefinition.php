<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Tukaatu Express API',
    description: 'API documentation for the Tukaatu Express courier delivery platform'
)]
#[OA\Server(
    url: 'http://localhost:8000',
    description: 'Local development server'
)]
#[OA\Server(
    url: 'https://api.tukaatuexpress.com',
    description: 'Production server'
)]
#[OA\SecurityScheme(
    securityScheme: 'TukaatuApiKey',
    type: 'apiKey',
    in: 'header',
    name: 'X-Tukaatu-Api-Key'
)]
final class OpenApiDefinition
{
}