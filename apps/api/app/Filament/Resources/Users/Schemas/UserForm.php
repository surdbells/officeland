<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * User form.
 *
 * The password is write-only and optional on edit: a blank field leaves the
 * current password untouched (it is not dehydrated), and a value is hashed by
 * the model's `hashed` cast. The role carries its own description so whoever
 * assigns it sees exactly what it grants.
 */
final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    // Required when creating; on edit a blank field keeps the
                    // existing password (dehydrated only when filled).
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the current password.'),

                Select::make('role')
                    ->options(Role::options())
                    ->required()
                    ->default(Role::Viewer->value)
                    ->live()
                    ->helperText(fn (Get $get): string => self::roleDescription($get('role'))),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('A deactivated account keeps its history but cannot sign in to the panel.'),
            ])->columns(2),
        ]);
    }

    private static function roleDescription(mixed $value): string
    {
        $role = Role::tryFrom((string) $value);

        return $role?->description() ?? 'Pick a role to see what it grants.';
    }
}
