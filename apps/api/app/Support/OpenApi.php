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
        facet filtering - not on the critical path of a page view.

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
        new OA\Tag(name: 'Home', description: 'Homepage editorial content'),
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
            property: 'product_links',
            description: 'Every product in a LEAF category, as crawlable {name, url} anchors. '
                .'A leaf sits at <=2 clicks from home, so linking its products directly here '
                .'keeps them within the 3-click budget (SEO_SPEC 10.5) regardless of how many '
                .'pages the paginated grid spans. Absent on non-leaf categories, whose products '
                .'are reached through their own leaves.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BreadcrumbItem'),
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
    schema: 'Image',
    description: 'One image, shaped for a <picture> element (section 12.5). Only derivatives that actually exist are advertised - generation is resumable, so a srcset must never promise a 404.',
    required: ['uuid', 'alt', 'sources'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'alt', description: 'Never empty: alt text is required before media may be attached (section 12.6).', type: 'string'),
        new OA\Property(property: 'width', description: 'Intrinsic width of the fallback. Set on the img to prevent layout shift.', type: 'integer', nullable: true),
        new OA\Property(property: 'height', type: 'integer', nullable: true),
        new OA\Property(property: 'role', type: 'string', enum: ['primary', 'gallery', 'spec_sheet', 'lifestyle']),
        new OA\Property(property: 'src', description: 'Fallback JPEG for the img element.', type: 'string', nullable: true, example: '/media/ab/cd/<sha>/pdp-800.jpeg'),
        new OA\Property(
            property: 'sources',
            description: 'Ordered best-format-first: AVIF, WebP, then JPEG.',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'type', type: 'string', example: 'image/avif'),
                new OA\Property(property: 'srcset', type: 'string', example: '/media/.../card-400.avif 400w, /media/.../pdp-800.avif 800w'),
            ], type: 'object'),
        ),
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
        new OA\Property(
            property: 'key_specs',
            description: '4-6 bullets shown above the fold, ordered by the source'.'"'.'s own buyer-orientation priority (Icecat ProductFeature@No). Present only when the caller loaded attribute values.',
            type: 'array',
            items: new OA\Items(required: ['label', 'value'], properties: [
                new OA\Property(property: 'label', type: 'string', example: 'Page yield'),
                new OA\Property(property: 'value', type: 'string', example: '2,000 pages'),
            ], type: 'object'),
        ),
        new OA\Property(
            property: 'image',
            description: 'The primary image only. A 24-item grid does not need four gallery shots per card.',
            ref: '#/components/schemas/Image',
            nullable: true,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'HeroSlide',
    description: 'One slide of the homepage hero carousel. Managed in admin; the storefront falls back to department-derived slides when none are active.',
    required: ['headline'],
    properties: [
        new OA\Property(property: 'headline', type: 'string'),
        new OA\Property(property: 'subheadline', type: 'string', nullable: true),
        new OA\Property(property: 'cta_label', type: 'string', nullable: true),
        new OA\Property(property: 'cta_url', type: 'string', nullable: true),
        new OA\Property(property: 'secondary_cta_label', type: 'string', nullable: true),
        new OA\Property(property: 'secondary_cta_url', type: 'string', nullable: true),
        new OA\Property(property: 'image', ref: '#/components/schemas/Image', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'HomeCategoryTile',
    description: 'One tile in the homepage "Shop by category" grid - a curated pointer to a category.',
    required: ['name', 'url', 'product_count'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'url', type: 'string', example: '/c/it-products/computers/laptops/'),
        new OA\Property(property: 'product_count', type: 'integer'),
        new OA\Property(property: 'image', ref: '#/components/schemas/Image', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Home',
    description: 'Editorial content for the homepage. Every section is optional: an empty array means the storefront keeps its built-in derived behaviour for that section.',
    required: ['hero_slides', 'category_picks', 'product_picks'],
    properties: [
        new OA\Property(property: 'hero_slides', type: 'array', items: new OA\Items(ref: '#/components/schemas/HeroSlide')),
        new OA\Property(property: 'category_picks', type: 'array', items: new OA\Items(ref: '#/components/schemas/HomeCategoryTile')),
        new OA\Property(
            property: 'product_picks',
            description: 'The curated "best sellers" band. Empty means the storefront fills the band from the catalogue instead.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ProductCard'),
        ),
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
        new OA\Property(
            property: 'images',
            description: 'The full gallery, primary first. The storefront gives the first image fetchpriority=high as the LCP element.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Image'),
        ),
        new OA\Property(property: 'specifications', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpecificationGroup')),
        new OA\Property(
            property: 'related',
            description: 'Typed relations supplied by the source rather than hand-curated (ICECAT_MAPPING.md F-3), keyed by relation type: accessory, compatible-with, replacement. This is what answers "which toner fits this printer".',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(ref: '#/components/schemas/ProductCard'),
            ),
        ),
        new OA\Property(property: 'seo', ref: '#/components/schemas/Seo'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'BasketLine',
    description: 'One line of a quote basket. The quantity rules travel with the line so the storefront stepper cannot offer a quantity the server would silently change.',
    required: ['id', 'qty', 'name', 'is_free_text'],
    properties: [
        new OA\Property(property: 'id', description: 'Addressed by every mutation, and scoped to the caller'.'"'.'s own basket server-side.', type: 'string'),
        new OA\Property(property: 'qty', type: 'integer', minimum: 1),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'min_order_qty', type: 'integer', minimum: 1),
        new OA\Property(property: 'qty_step', type: 'integer', minimum: 1),
        new OA\Property(property: 'quantity_note', type: 'string', nullable: true, example: 'Minimum 4, in multiples of 5'),
        new OA\Property(property: 'is_free_text', description: 'A request for something the catalogue does not list.', type: 'boolean'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
        new OA\Property(property: 'mpn', type: 'string', nullable: true),
        new OA\Property(property: 'brand', type: 'string', nullable: true),
        new OA\Property(property: 'availability', type: 'string', nullable: true),
        new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Basket',
    description: 'The quote basket. No prices and no total anywhere (D-002). An absent basket is returned as an empty one, so the storefront needs one code path rather than two.',
    required: ['items', 'line_count', 'unit_count'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/BasketLine')),
        new OA\Property(property: 'line_count', type: 'integer'),
        new OA\Property(property: 'unit_count', type: 'integer'),
        new OA\Property(property: 'max_lines', type: 'integer'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'QuoteConfirmation',
    description: 'What a buyer is shown after submitting. The reference is the string they quote on the phone.',
    required: ['reference', 'email'],
    properties: [
        new OA\Property(property: 'reference', type: 'string', example: 'OL-Q-2026-000001'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'line_count', type: 'integer'),
        new OA\Property(property: 'response_expectation', type: 'string', example: 'We respond within one business day.'),
    ],
    type: 'object',
)]
final class OpenApi
{
    //
}
