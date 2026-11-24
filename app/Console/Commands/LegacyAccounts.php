<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Support\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the admin and participant accounts out of the loaded dump.
 *
 * ROLES COME FROM THE OLD DATA (owner, 2026-11-19): a `fileuser` Administrator
 * becomes super, a DepartmentAdmin becomes hq, and every `upengawai` officer
 * becomes the state admin of their own zone — several per zone where the office
 * had several. An officer whose zone cannot be translated gets no account and
 * is reported; their row stays in staging.
 *
 * PASSWORDS ARE NOT CARRIED OVER (owner, 2026-11-17): the old ones are plain
 * text and unsalted MD5. Every admin gets a random password and a super admin
 * resets it. Participants sign in with an email the office adds (the old
 * system kept none), their IC the first time.
 *
 * ONE PERSON, MANY OLD ROWS. The same usahawan appears in the live table, the
 * master lists and each past event's copy. They are one account, found by IC
 * (as digits), or by name + company when the row has no IC. The most recent
 * source gives the profile (`Legacy::participantSources()`); every row that is
 * the same person is linked to the account through `legacy_usahawan.user_id`
 * and `company_id`, which is what legacy:transform-event reads.
 *
 * Every account built carries `legacy_id` (`source_table:id`), which is how a
 * re-run finds it and how `--fresh` removes exactly what this command made.
 */
class LegacyAccounts extends Command
{
    protected $signature = 'legacy:accounts
        {--users=all : How many participants to build, or "all"}
        {--fresh : Delete accounts this command made before rebuilding (not in production)}';

    protected $description = 'Build admins and participants from the loaded legacy dump, linking every staging row';

    private const ROLES = ['Administrator' => Admin::SUPER_ROLE, 'DepartmentAdmin' => 'hq'];

    private array $notes = [];

    public function handle(): int
    {
        if (DB::table('legacy_upengawai')->doesntExist() && DB::table('legacy_usahawan')->doesntExist()) {
            $this->error('Staging is empty. Run import:legacy first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if (app()->environment('production')) {
                $this->error('--fresh deletes accounts and is refused in production.');

                return self::FAILURE;
            }

            $this->forget();
        }

        $admins = $this->admins();
        $users = $this->participants();

        $this->newLine();
        $this->line('Admins: '.collect($admins)->countBy('role')->map(fn ($n, $r) => "{$r} {$n}")->join(', '));
        $this->line("Participants: {$users}");
        foreach ($this->notes as $note) {
            $this->line('  '.$note);
        }
        $this->newLine();
        $this->line('Admin passwords are random; a super admin resets them.');
        // The old system kept no email, and a participant signs in with one.
        $noEmail = User::whereNotNull('legacy_id')->whereNull('email')->count();
        if ($noEmail > 0) {
            $this->warn("{$noEmail} participant(s) have no email and CANNOT SIGN IN until the office adds one on the company page. Their first sign-in is with their IC.");
        }

        return self::SUCCESS;
    }

    /** @return array<int, Admin> */
    private function admins(): array
    {
        // Administrators first, then officers, each in id order, so a re-run
        // hands the same username to the same person.
        $rows = DB::table('legacy_upengawai')
            ->whereIn('source_table', ['fileuser', 'upengawai'])
            ->orderByRaw("case when source_table = 'fileuser' then 0 else 1 end")
            ->orderByRaw('cast(legacy_id as unsigned)')
            ->get();

        $made = [];
        $taken = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $key = $row->source_table.':'.$row->legacy_id;
            $raw = Legacy::raw($row);

            if ($row->source_table === 'fileuser') {
                $role = self::ROLES[trim((string) $row->userright)] ?? null;
                $zone = null;
            } else {
                $role = 'admin';
                $zone = Legacy::zone($row->state);
            }

            if ($role === null || ($role === 'admin' && $zone === null)) {
                $skipped++;

                continue;
            }

            $admin = Admin::updateOrCreate(['legacy_id' => $key], [
                'username' => $this->handleFor($row, $key, $taken),
                'name' => trim(Legacy::repair((string) $row->name)),
                'password' => bcrypt(Str::random(32)),
                'role' => $role,
                'state' => $zone,
                'is_active' => ! $this->expired($raw),
            ]);

            DB::table('legacy_upengawai')->where('id', $row->id)->update(['admin_id' => $admin->id]);
            $made[] = $admin;
        }

        if ($skipped > 0) {
            $this->notes[] = "{$skipped} staff row(s) got no account: no zone this installation knows, or not an admin right. Kept in staging.";
        }

        return $made;
    }

    /** A `fileuser` account past its ExpireDate is imported switched off, as the old login treated it. */
    private function expired(array $raw): bool
    {
        $expires = trim((string) ($raw['expiredate'] ?? ''));

        if ($expires === '' || str_starts_with($expires, '0000')) {
            return false;
        }

        try {
            return Carbon::parse($expires)->isPast();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A unique username per account, from `loginname`.
     *
     * TWO OFFICERS CAN SHARE ONE LOGIN — the old table has the same person
     * entered twice under 'pegdua' — and `admins.username` is unique. The first
     * to claim a slug keeps it; every later one carries its own key, so the
     * second never silently takes over the first one's row.
     *
     * @param  array<string, string>  $taken  slug => the legacy key holding it
     */
    private function handleFor(object $row, string $key, array &$taken): string
    {
        $login = trim((string) $row->loginname);
        $slug = Str::slug($login !== '' ? $login : (string) $row->name) ?: 'pegawai';

        if (! isset($taken[$slug])) {
            $taken[$slug] = $key;

            return $slug;
        }

        $suffix = ($row->source_table === 'fileuser' ? 'f' : '').$row->legacy_id;

        return $taken[$slug] === $key ? $slug : $slug.'.'.$suffix;
    }

    /** @return int accounts built or updated */
    private function participants(): int
    {
        $sources = Legacy::participantSources();
        $limit = $this->option('users') === 'all' ? null : max(0, (int) $this->option('users'));

        $rows = DB::table('legacy_usahawan')
            ->whereIn('source_table', $sources)
            ->get()
            ->sortBy(fn ($r) => [array_search($r->source_table, $sources, true), (int) $r->legacy_id]);

        // An account this command built before, found again by who it is.
        $known = User::whereNotNull('legacy_id')->with('company')->get()
            ->keyBy(fn (User $u) => Legacy::identity($u->ic_number, $u->name, $u->company?->name));

        $groups = [];
        $anonymous = 0;

        foreach ($rows as $row) {
            $identity = Legacy::identity($row->ic, $row->name, $row->companyname);

            if ($identity === null) {
                $anonymous++;

                continue;
            }

            $groups[$identity][] = $row;
        }

        if ($limit !== null) {
            $groups = array_slice($groups, 0, $limit, true);
        }

        $noZone = 0;

        foreach ($groups as $identity => $group) {
            [$user, $company, $zoneLost] = $this->participant($group[0], $known[$identity] ?? null);
            $noZone += $zoneLost ? 1 : 0;

            DB::table('legacy_usahawan')
                ->whereIn('id', array_map(fn ($r) => $r->id, $group))
                ->update(['user_id' => $user->id, 'company_id' => $company->id]);
        }

        if ($anonymous > 0) {
            $this->notes[] = "{$anonymous} participant row(s) have no IC, name or company: linked to nobody, kept in staging.";
        }
        if ($noZone > 0) {
            $this->notes[] = "{$noZone} company(ies) have a zone this installation does not know: left without a zone, visible to HQ.";
        }

        return count($groups);
    }

    /** @return array{0: User, 1: Company, 2: bool} */
    private function participant(object $row, ?User $existing): array
    {
        $raw = Legacy::raw($row);
        [$code, $size] = Legacy::codeAndSize($row->crafttype);

        $user = $existing ?? new User(['password' => bcrypt(Str::random(32))]);
        $user->fill([
            'name' => trim(Legacy::repair((string) $row->name)),
            // Kept as the office wrote it — dashed or bare. Matching is on digits.
            'ic_number' => trim((string) $row->ic) ?: null,
            'community_id' => Legacy::communityId($row->comid),
            'salutation' => Legacy::field($raw, 'salu'),
            'address' => Legacy::field($raw, 'address'),
            'postcode' => Legacy::field($raw, 'postcode'),
            'district' => Legacy::field($raw, 'region'),
            'legacy_id' => $user->legacy_id ?? $row->source_table.':'.$row->legacy_id,
            'is_active' => true,
        ]);
        $user->save();

        $zone = Legacy::zone($row->state);
        $company = Company::firstOrNew(['user_id' => $user->id]);
        $company->fill([
            'name' => trim(Legacy::repair((string) $row->companyname)) ?: $user->name,
            'registration_no' => Legacy::field($raw, 'register'),
            'state' => $zone,
            'is_active' => true,
        ]);

        // Not fillable (admin-only), assigned directly: a company being created
        // has no draw list whose verification syncBoothSize() would clear.
        $company->kraf_code = $code;
        $company->plot_size = $size;
        $company->save();

        return [$user, $company, $zone === null && trim((string) $row->state) !== ''];
    }

    private function forget(): void
    {
        // `legacy_id` is set only by this command, so nothing the office or the
        // demo seeders made is touched.
        $users = User::whereNotNull('legacy_id')->pluck('id');
        Company::whereIn('user_id', $users)->delete();
        User::whereIn('id', $users)->delete();
        Admin::whereNotNull('legacy_id')->delete();

        $this->line('Removed the accounts this command had made.');
    }
}
