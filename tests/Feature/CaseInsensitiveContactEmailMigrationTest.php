<?php

use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const CASE_INSENSITIVE_EMAIL_MIGRATION_ERROR = 'Cannot enforce case-insensitive contact email uniqueness while duplicate emails exist.';
const OIDC_ACCOUNT_ROLLBACK_ERROR = 'Cannot reverse OIDC account support while passwordless users remain.';

function runCaseInsensitiveContactEmailMigration(): void
{
    $migration = require database_path('migrations/2026_11_10_190001_add_case_insensitive_contact_email_uniqueness.php');
    $migration->up();
}

it('creates a case-insensitive index when existing emails do not conflict', function () {
    $user = User::factory()->create(['email' => 'migration.clean@example.test']);
    $other = User::factory()->create(['email' => 'migration.other@example.test']);
    DB::statement('DROP INDEX users_email_case_insensitive_unique');
    DB::table('users')->where('id', $user->id)->update(['email' => 'Migration.Clean@example.test']);

    runCaseInsensitiveContactEmailMigration();

    expect(fn () => DB::table('users')->where('id', $other->id)->update(['email' => 'MIGRATION.CLEAN@example.test']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('preserves historical rows and refuses index creation when email case variants already conflict', function () {
    $first = User::factory()->create(['email' => 'legacy.first@example.test']);
    $second = User::factory()->create(['email' => 'legacy.second@example.test']);
    DB::statement('DROP INDEX users_email_case_insensitive_unique');
    DB::table('users')->where('id', $first->id)->update(['email' => 'Legacy.Case@example.test']);
    DB::table('users')->where('id', $second->id)->update(['email' => 'legacy.case@example.test']);
    $before = DB::table('users')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    expect(fn () => runCaseInsensitiveContactEmailMigration())->toThrow(RuntimeException::class, CASE_INSENSITIVE_EMAIL_MIGRATION_ERROR);
    expect(DB::table('users')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before);
});

it('preserves OIDC identities and contact uniqueness when rollback meets passwordless users', function () {
    $identity = OidcIdentity::factory()->create();
    $other = User::factory()->create(['email' => 'rollback.other@example.test']);
    $identityMigration = require database_path('migrations/2026_11_10_190002_create_oidc_identities_table.php');
    $contactEmailMigration = require database_path('migrations/2026_11_10_190001_add_case_insensitive_contact_email_uniqueness.php');
    $accountFoundationMigration = require database_path('migrations/2026_11_10_190000_add_oidc_account_foundation.php');

    expect(fn () => $identityMigration->down())->toThrow(RuntimeException::class, OIDC_ACCOUNT_ROLLBACK_ERROR);
    expect(fn () => $contactEmailMigration->down())->toThrow(RuntimeException::class, OIDC_ACCOUNT_ROLLBACK_ERROR);
    expect(fn () => $accountFoundationMigration->down())->toThrow(RuntimeException::class, OIDC_ACCOUNT_ROLLBACK_ERROR);

    $this->assertModelExists($identity);
    $this->assertModelExists($identity->user);
    $this->assertModelExists($other);
    expect(Schema::hasTable('oidc_identities'))->toBeTrue();
    expect(fn () => DB::table('users')->where('id', $other->id)->update(['email' => strtoupper($identity->user->email)]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reverses the OIDC schema while preserving local accounts without passwordless users', function () {
    $first = User::factory()->create(['email' => 'rollback.first@example.test']);
    $second = User::factory()->create(['email' => 'rollback.second@example.test']);
    $identityMigration = require database_path('migrations/2026_11_10_190002_create_oidc_identities_table.php');
    $contactEmailMigration = require database_path('migrations/2026_11_10_190001_add_case_insensitive_contact_email_uniqueness.php');
    $accountFoundationMigration = require database_path('migrations/2026_11_10_190000_add_oidc_account_foundation.php');

    $identityMigration->down();
    $contactEmailMigration->down();
    DB::table('users')->where('id', $second->id)->update(['email' => strtoupper($first->email)]);
    $accountFoundationMigration->down();

    $this->assertModelExists($first);
    $this->assertModelExists($second);
    expect(Schema::hasTable('oidc_identities'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'disabled_at'))->toBeFalse();
});

it('refuses stable identity rollback before changing the column or its rows', function () {
    $identity = OidcIdentity::factory()->create([
        'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0',
        'subject' => 'stable-rollback-subject',
        'stable_subject' => '33333333-3333-4333-8333-333333333333',
    ]);
    $migration = require database_path('migrations/2026_11_11_000001_add_oidc_stable_subject.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot remove stable OIDC identity bindings while stable identities remain.');
    expect(Schema::hasColumn('oidc_identities', 'stable_subject'))->toBeTrue();
    $this->assertDatabaseHas('oidc_identities', ['id' => $identity->id, 'stable_subject' => $identity->stable_subject]);
    $other = User::factory()->create(['password' => null]);
    expect(fn () => OidcIdentity::factory()->create([
        'user_id' => $other->id,
        'provider' => $identity->provider,
        'issuer' => $identity->issuer,
        'subject' => 'different-stable-rollback-subject',
        'stable_subject' => $identity->stable_subject,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('removes the stable identity column cleanly when no provider uses it', function () {
    $identity = OidcIdentity::factory()->create(['provider' => 'google', 'stable_subject' => null]);
    $migration = require database_path('migrations/2026_11_11_000001_add_oidc_stable_subject.php');

    $migration->down();

    expect(Schema::hasColumn('oidc_identities', 'stable_subject'))->toBeFalse();
    $this->assertDatabaseHas('oidc_identities', ['id' => $identity->id, 'subject' => $identity->subject]);
});
