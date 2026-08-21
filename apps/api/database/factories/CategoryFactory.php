<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Content\ContentMinimums;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = Str::slug(implode(' ', (array) $this->faker->unique()->words(2)));

        return [
            'uuid' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'path' => '/'.$slug.'/',
            'depth' => 1,
            'is_indexable' => false,
        ];
    }

    /**
     * Places this category beneath another, maintaining path and depth.
     *
     * The slug is a parameter rather than being read from $attributes inside
     * the state closure: state closures run BEFORE create() overrides, so
     * reading $attributes['slug'] yields the definition's random slug and
     * builds a path that does not match the slug the row is created with.
     * The CHECK constraint accepts it, and the mismatch then surfaces much
     * later as a subtree query silently returning nothing.
     */
    public function childOf(Category $parent, string $slug): self
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'path' => $parent->path.$slug.'/',
            'depth' => $parent->depth + 1,
        ]);
    }

    public function root(string $slug): self
    {
        return $this->state(fn (): array => [
            'name' => Str::headline($slug),
            'slug' => $slug,
            'path' => '/'.$slug.'/',
            'depth' => 1,
        ]);
    }

    /**
     * A category with a real intro, so it may actually be indexed.
     *
     * The model refuses `is_indexable` without one (EnforcesContentMinimums),
     * which is the rule SEO_SPEC §7 states: an intro is *required before a
     * category may be marked indexable*.
     */
    public function indexable(): self
    {
        return $this->state(fn (): array => [
            'is_indexable' => true,
            'intro_copy' => implode(' ', (array) $this->faker->words(
                ContentMinimums::CATEGORY_WORDS_MIN + 20,
            )),
        ]);
    }
}
