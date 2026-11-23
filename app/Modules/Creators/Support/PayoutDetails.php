<?php

declare(strict_types=1);

namespace App\Modules\Creators\Support;

use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Wallet\Contracts\Data\PayoutRail;

/**
 * Checks and masks the details for each payout rail. The full details are only ever
 * stored encrypted; the masked hint is what screens and staff see (rule 13).
 */
final class PayoutDetails
{
    /** TRON base58 address: "T" plus 33 characters (no 0, O, I or l). */
    private const string TRC20 = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';

    /**
     * @param  array<string, mixed>  $input
     * @return array{details: array<string, string>, hint: string}
     */
    public static function from(PayoutRail $rail, array $input): array
    {
        return match ($rail) {
            PayoutRail::Bank => self::bank($input),
            PayoutRail::Usdt => self::usdt($input),
            PayoutRail::Paxum => self::paxum($input),
        };
    }

    public static function accountName(mixed $value): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', is_string($value) ? $value : ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new CreatorException('Enter the name on the account, as it appears on your ID.', 'account_name');
        }

        return $name;
    }

    /**
     * Same words, ignoring case, punctuation and order ("Wanjiru, Amani" matches
     * "AMANI WANJIRU"). Anything else is flagged for staff, not refused.
     */
    public static function namesMatch(string $accountName, ?string $legalName): bool
    {
        if ($legalName === null) {
            return false;
        }

        $words = static function (string $name): array {
            $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($parts);

            return $parts;
        };

        return $words($accountName) !== [] && $words($accountName) === $words($legalName);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{details: array<string, string>, hint: string}
     */
    private static function bank(array $input): array
    {
        $bank = trim(self::string($input, 'bank_name'));
        $number = (string) preg_replace('/[\s-]+/', '', self::string($input, 'account_number'));

        if (mb_strlen($bank) < 2 || mb_strlen($bank) > 60) {
            throw new CreatorException('Enter the name of your bank.', 'bank_name');
        }

        if (preg_match('/^\d{6,20}$/', $number) !== 1) {
            throw new CreatorException('Enter the account number: 6 to 20 digits.', 'account_number');
        }

        return [
            'details' => ['bank_name' => $bank, 'account_number' => $number],
            'hint' => mb_substr($bank, 0, 60).' ····'.substr($number, -4),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{details: array<string, string>, hint: string}
     */
    private static function usdt(array $input): array
    {
        $address = trim(self::string($input, 'wallet_address'));

        if (preg_match(self::TRC20, $address) !== 1) {
            throw new CreatorException('Enter a TRC-20 address: it starts with T and has 34 characters.', 'wallet_address');
        }

        return [
            'details' => ['network' => 'trc20', 'address' => $address],
            'hint' => 'USDT wallet ····'.substr($address, -4),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{details: array<string, string>, hint: string}
     */
    private static function paxum(array $input): array
    {
        $email = mb_strtolower(trim(self::string($input, 'paxum_email')));

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CreatorException('Enter the email address of your Paxum account.', 'paxum_email');
        }

        [$local, $domain] = explode('@', $email, 2);

        return [
            'details' => ['email' => $email],
            'hint' => 'Paxum '.mb_substr($local, 0, 1).'····@'.mb_substr($domain, 0, 50),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
