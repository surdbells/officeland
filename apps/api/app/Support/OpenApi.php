<?php

declare(strict_types=1);

namespace App\Support;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 3.1 document root.
 *
 * The spec is generated from these attributes, committed to
 * packages/contracts, and the storefront's TypeScript types are generated
 * from it. Hand-written HTTP calls in the Angular app are a review rejection
 * (MASTER BUILD PROMPT section 15.1), and a contract change not reflected in
 * packages/contracts is a failed gate (section 6.2).
 */
#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        version: '1.0.0',
        title: 'OfficeLand Catalogue API',
        description: <<<'TXT'
        Public catalogue endpoints for the OfficeLand storefront.

        The storefront is prerendered to static HTML, so these endpoints are
        consumed mainly by the build-time render worker and by client-side
        facet filtering — not on the critical path of a page view.

        No prices are returned anywhere: OfficeLand is quote-first, and
        `pricing_mode` drives the call to action from data rather than from a
        hardcoded rule.
        TXT,
    ),
    servers: [
        new OA\Server(url: 'https://officelandng.com', description: 'Production'),
        new OA\Server(url: 'http://localhost:8095', description: 'Local development'),
    ],
    tags: [
        new OA\Tag(name: 'Navigation', description: 'Category tree and featured brands'),
        new OA\Tag(name: 'Categories', description: 'Category pages and listings'),
        new OA\Tag(name: 'Products', description: 'Product detail'),
        new OA\Tag(name: 'Brands', description: 'Brand hubs and listings'),
    ],
)]
#[OA\Schema(
    schema: 'Error',
    title: 'Error envelope',
    description: 'Consistent error shape (section 8.3). trace_id correlates to the structured logs.',
    required: ['error'],
    properties: [
        new OA\Property(
            property: 'error',
            required: ['code', 'message'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'NotFoundHttpException'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'trace_id', type: 'string', format: 'uuid', nullable: true),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Brand',
    required: ['uuid', 'name', 'slug', 'url'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'HP'),
        new OA\Property(property: 'slug', type: 'string', example: 'hp'),
        new OA\Property(property: 'url', type: 'string', example: '/brands/hp/'),
        new OA\Property(property: 'description_html', type: 'string', nullable: true),
        new OA\Property(property: 'is_featured', type: 'boolean'),
        new OA\Property(property: 'product_count', type: 'integer'),
        new OA\Property(property: 'seo', ref: '#/components/schemas/Seo'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Seo',
    properties: [
        new OA\Property(property: 'meta_title', type: 'string', nullable: true),
        new OA\Property(property: 'meta_description', type: 'string', nullable: true),
        new OA\Property(property: 'canonical_override', type: 'string', nullable: true),
        new OA\Property(property: 'is_indexable', type: 'boolean'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'BreadcrumbItem',
    required: ['name', 'url'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'url', type: 'string'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Category',
    required: ['uuid', 'name', 'slug', 'path', 'url', 'depth'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Executive Chairs'),
        new OA\Property(property: 'slug', type: 'string', example: 'executive-chairs'),
        new OA\Property(property: 'path', type: 'string', example: '/office-furniture/seating/executive-chairs/'),
        new OA\Property(property: 'url', type: 'string', example: '/c/office-furniture/seating/executive-chairs/'),
        new OA\Property(property: 'depth', type: 'integer', maximum: 3, minimum: 1),
        new OA\Property(property: 'intro_copy', type: 'string', nullable: true),
        new OA\Property(property: 'description_html', type: 'string', nullable: true),
        new OA\Property(property: 'product_count', type: 'integer'),
        new OA\Property(
            property: 'children',
            description: 'Rendered as real crawlable anchors on every category page (D-011).',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Category'),
        ),
        new OA\Property(
            property: 'breadcrumb',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BreadcrumbItem'),
        ),
        new OA\Property(property: 'seo', ref: '#/components/schemas/Seo'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ProductCard',
    description: 'Listing payload. One shape shared by PLP, search, related rails and collections.',
    required: ['uuid', 'name', 'slug', 'url', 'availability', 'pricing_mode'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'url', type: 'string', example: '/p/hp-128a-black-laserjet-toner-ce320a/'),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
        new OA\Property(property: 'mpn', type: 'string', nullable: true),
        new OA\Property(property: 'model_code', type: 'string', nullable: true),
        new OA\Property(property: 'brand', ref: '#/components/schemas/Brand', nullable: true),
        new OA\Property(
            property: 'availability',
            type: 'string',
            enum: ['in_stock', 'limited', 'made_to_order', 'lead_time', 'discontinued'],
        ),
        new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
        new OA\Property(
            property: 'pricing_mode',
            description: 'No prices are returned (D-002). This drives the call to action from data.',
            type: 'string',
            enum: ['public', 'quote_only'],
        ),
        new OA\Property(property: 'min_order_qty', type: 'integer', minimum: 1),
        new OA\Property(property: 'qty_step', type: 'integer', minimum: 1),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SpecificationGroup',
    description: 'Specifications arrive already grouped from the source (Icecat CategoryFeatureGroup).',
    required: ['group', 'items'],
    properties: [
        new OA\Property(property: 'group', type: 'string', example: 'PERFORMANCE'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'label', type: 'string', example: 'Page yield'),
                new OA\Property(property: 'value', description: 'Formatted for display.', type: 'string', example: '2,000 pages'),
                new OA\Property(property: 'value_raw', description: 'For search and compare.', type: 'string', example: '2000'),
                new OA\Property(property: 'unit', type: 'string', nullable: true),
            ], type: 'object'),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Product',
    allOf: [new OA\Schema(ref: '#/components/schemas/ProductCard')],
    properties: [
        new OA\Property(property: 'ean', type: 'string', nullable: true),
        new OA\Property(property: 'primary_category', ref: '#/components/schemas/Category', nullable: true),
        new OA\Property(property: 'breadcrumb', type: 'array', items: new OA\Items(ref: '#/components/schemas/BreadcrumbItem')),
        new OA\Property(property: 'short_description', type: 'string', nullable: true),
        new OA\Property(property: 'description_html', type: 'string', nullable: true),
        new OA\Property(property: 'warranty_text', type: 'string', nullable: true),
        new OA\Property(property: 'specifications', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpecificationGroup')),
        new OA\Property(property: 'seo', ref: '#/components/schemas/Seo'),
    ],
    type: 'object',
)]
final class OpenApi
{
    //
}
