<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // What the participant signs in with, with a password (owner,
            // 2026-11-24 — replacing community ID + IC). Stored lower-case and
            // trimmed. Set by the office only: the email is the key to the
            // account, and the reset link goes to it. Nullable because imported
            // accounts may have none yet; such an account cannot sign in until
            // the office adds one.
            $table->string('email')->nullable()->unique();

            // Unused until `password_changed_at` is set: before that the IC is
            // the password (LoginRequest), and the participant must choose one.
            $table->string('password');

            // NULL = first sign-in still to come, or the office has reset the
            // account back to its IC. Set when the participant chooses a password.
            $table->timestamp('password_changed_at')->nullable();
            $table->string('phone')->nullable();

            // The participant's IC. User::icHash() turns it into the code printed
            // on the lot slip and shown on the vote page, so an account with no IC
            // simply has no code — see the model for why that code is an
            // identifier and not a secret.
            $table->string('ic_number')->nullable();

            // The office's community ID for this participant. Free text and not
            // unique: the office has not said whether IDs are ever shared.
            $table->string('community_id', 50)->nullable();

            // The owner's postal address, typed by the office. Free text: nothing
            // reads it but a person, so there is nothing to split it into.
            $table->text('address')->nullable();

            // The old system's Gelaran, Poskod and Daerah (`usahawan.salu`,
            // `postcode`, `region`), carried over by legacy:accounts so they are
            // not lost in the move. Free text, as the office typed them.
            $table->string('salutation', 50)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('district', 100)->nullable();

            // Accounts are created by an admin, never self-registered, so there is
            // no verification step and no `email_verified_at`. Switching an account
            // off is what `is_active` is for, and EnforceUserIsActive turns that
            // into an immediate logout rather than a quiet half-session.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_seen_at')->nullable();

            // The old-system row this account was built from by legacy:accounts,
            // as `source_table:id` (e.g. `usahawan:17`) — one person can appear
            // in many old tables, and this names the one their profile came from.
            // Every other row that is the same person links here through
            // `legacy_usahawan.user_id`. Null for every account the office makes.
            $table->string('legacy_id', 100)->nullable()->index();

            $table->rememberToken();
            $table->timestamps();

            /*
             * The two columns the office's own imports look rows up by.
             *
             * `import:participants` MATCHES ON IC, and the sign-in on community
             * ID + IC; both load candidates to do it — the match is on IC normalised to
             * digits, which no index can serve, but the reads around it are plain
             * lookups that this does serve. `community_id` is the office's other
             * handle on a person and is read the same way.
             *
             * INDEX, NOT UNIQUE, on `ic_number`, and that is a decision rather
             * than caution. A unique index compares STRINGS, and the office writes
             * the same IC two ways — `900101-12-0000` and `900101120000` — so it
             * would admit exactly the duplicates it appears to forbid while
             * rejecting nothing. The real uniqueness rule is "unique once reduced
             * to digits", which the importers enforce in PHP, where they can also
             * drop BOTH rows of a clash rather than pick one.
             */
            $table->index('ic_number');
            $table->index('community_id');
        });

        // The "forgot password" link, emailed (NewPasswordController).
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
