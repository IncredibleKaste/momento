<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

const CASE_INSENSITIVE_EMAIL_MIGRATION_ERROR = 'Cannot enforce case-insensitive contact email uniqueness while duplicate emails exist.';

function runCaseInsensitiveContactEmailMigration(): void
{
    $migration = require database_path('migrations/2026_10_29_160724_add_case_insensitive_contact_email_uniqueness.php');
    $migration->up();
}

test('case-insensitive contact email migration creates its unique index when existing emails do not conflict', function () {
    $user = User::factory()->create(['email' => 'migration.clean@example.test']);
    $otherUser = User::factory()->create(['email' => 'migration.other@example.test']);
    DB::statement('DROP INDEX users_email_case_insensitive_unique');
    DB::table('users')->where('id', $user->id)->update(['email' => 'Migration.Clean@example.test']);

    runCaseInsensitiveContactEmailMigration();

    expect(fn () => DB::table('users')->where('id', $otherUser->id)->update(['email' => 'MIGRATION.CLEAN@example.test']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('case-insensitive contact email migration preserves all rows and fails generically on historical duplicates', function () {
    $firstUser = User::factory()->create(['email' => 'legacy.first@example.test']);
    $secondUser = User::factory()->create(['email' => 'legacy.second@example.test']);
    DB::statement('DROP INDEX users_email_case_insensitive_unique');
    DB::table('users')->where('id', $firstUser->id)->update(['email' => 'Legacy.Case@example.test']);
    DB::table('users')->where('id', $secondUser->id)->update(['email' => 'legacy.case@example.test']);
    $usersBeforeMigration = DB::table('users')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all();

    expect(fn () => runCaseInsensitiveContactEmailMigration())
        ->toThrow(RuntimeException::class, CASE_INSENSITIVE_EMAIL_MIGRATION_ERROR);

    expect(DB::table('users')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all())
        ->toBe($usersBeforeMigration);
});
