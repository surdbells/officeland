<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The result of a CSV import, whether previewed or applied.
 *
 * Row numbers are the ones the editor sees in their spreadsheet — line 1 is
 * the header — so "row 47 is wrong" means row 47 in their file. Reporting
 * zero-based array offsets would make every error message a puzzle.
 */
final class ImportReport
{
    /** @var list<array{line: int, message: string}> */
    private array $errors = [];

    /** @var list<array{line: int, message: string}> */
    private array $warnings = [];

    /** @var list<array{line: int, name: string, attributes: array<string, mixed>}> */
    private array $creates = [];

    /** @var list<array{line: int, name: string, changes: array<string, array{from: mixed, to: mixed}>}> */
    private array $updates = [];

    /** @var list<array{line: int, name: string}> */
    private array $unchanged = [];

    public function __construct(public readonly bool $applied) {}

    public function addError(int $line, string $message): void
    {
        $this->errors[] = ['line' => $line, 'message' => $message];
    }

    public function addWarning(int $line, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'message' => $message];
    }

    /** @param array<string, mixed> $attributes */
    public function addCreate(int $line, string $name, array $attributes): void
    {
        $this->creates[] = ['line' => $line, 'name' => $name, 'attributes' => $attributes];
    }

    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    public function addUpdate(int $line, string $name, array $changes): void
    {
        $this->updates[] = ['line' => $line, 'name' => $name, 'changes' => $changes];
    }

    public function addUnchanged(int $line, string $name): void
    {
        $this->unchanged[] = ['line' => $line, 'name' => $name];
    }

    /** @return list<array{line: int, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{line: int, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array{line: int, name: string, attributes: array<string, mixed>}> */
    public function creates(): array
    {
        return $this->creates;
    }

    /** @return list<array{line: int, name: string, changes: array<string, array{from: mixed, to: mixed}>}> */
    public function updates(): array
    {
        return $this->updates;
    }

    /** @return list<array{line: int, name: string}> */
    public function unchanged(): array
    {
        return $this->unchanged;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function createCount(): int
    {
        return count($this->creates);
    }

    public function updateCount(): int
    {
        return count($this->updates);
    }

    public function unchangedCount(): int
    {
        return count($this->unchanged);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    /**
     * One line an editor can act on.
     *
     * Deliberately mentions unchanged rows: on a re-run they should be ALL of
     * them, and seeing that is how someone confirms the import is idempotent
     * rather than hoping it is.
     */
    public function summary(): string
    {
        $verb = $this->applied ? 'Imported' : 'Would import';

        return sprintf(
            '%s: %d new, %d updated, %d unchanged, %d row(s) rejected.',
            $verb,
            $this->createCount(),
            $this->updateCount(),
            $this->unchangedCount(),
            $this->errorCount(),
        );
    }
}
