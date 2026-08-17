<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Jetstream's version uses storePublicly(), which sets a per-object public
     * ACL. Cloudflare R2 (Laravel Cloud object storage) rejects per-object
     * ACLs, so store without one -- visibility is governed by the bucket, and
     * the local 'public' disk serves everything regardless.
     */
    public function updateProfilePhoto(\Illuminate\Http\UploadedFile $photo, $storagePath = 'profile-photos')
    {
        tap($this->profile_photo_path, function ($previous) use ($photo, $storagePath) {
            $this->forceFill([
                'profile_photo_path' => $photo->store(
                    $storagePath, ['disk' => $this->profilePhotoDisk()]
                ),
            ])->save();

            if ($previous) {
                \Illuminate\Support\Facades\Storage::disk($this->profilePhotoDisk())->delete($previous);
            }
        });
    }

    /**
     * Get the user's initials derived from their name or email.
     */
    public function initials(): string
    {
        $source = trim((string) ($this->name ?? ''));

        if ($source === '') {
            $email = (string) ($this->email ?? '');
            $firstChar = $email !== '' ? mb_substr($email, 0, 1) : '';

            return mb_strtoupper($firstChar);
        }

        // Normalize separators and split into words
        $normalized = preg_replace('/[\\s\\-\\_]+/u', ' ', $source) ?? $source;
        $parts = array_values(array_filter(explode(' ', $normalized), static fn ($p) => $p !== ''));

        if (count($parts) === 0) {
            return '';
        }

        $firstInitial = mb_substr($parts[0], 0, 1);
        $lastInitial = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        $initials = $firstInitial.($lastInitial !== '' ? $lastInitial : '');

        return mb_strtoupper($initials);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
