<?php

namespace App\Services\Banks\Contracts;

use App\Data\Bank\AccountData;
use App\Data\Bank\ContactData;
use App\Data\Bank\CredentialPromptsData;
use App\Data\Bank\LoginResultData;
use App\Data\Bank\TransactionData;
use App\Data\Bank\TransferQuoteData;
use App\Data\Bank\TransferReceiptData;
use App\Data\Bank\TransferRequestData;
use App\Enums\Bank;
use App\Models\BankSession;
use Spatie\LaravelData\DataCollection;

/**
 * A bank driver talks to one bank's API on behalf of one BankSession.
 *
 * Every method returns normalized data so the Telegram layer never has to
 * know which bank it is dealing with. Drivers own the session state: they
 * persist tokens, device identifiers and any bank-specific metadata on the
 * session themselves.
 */
interface BankDriver
{
    public function bank(): Bank;

    public function session(): BankSession;

    /**
     * Labels for the two credentials the bank needs to log in.
     */
    public function credentialPrompts(): CredentialPromptsData;

    /**
     * Start a login with the user's credentials. The result says whether an
     * OTP is now required to complete the login.
     */
    public function login(string $identifier, string $secret): LoginResultData;

    /**
     * Complete a login that requested an OTP.
     */
    public function verifyOtp(string $code): LoginResultData;

    /**
     * Make sure the session holds a usable access token, silently refreshing
     * or re-logging in when possible. Returns false when the user must log in again.
     */
    public function ensureAuthenticated(): bool;

    /**
     * Revoke the device / session at the bank, if the bank supports it.
     */
    public function logout(): void;

    /** @return DataCollection<int, AccountData> */
    public function accounts(): DataCollection;

    /** @return DataCollection<int, TransactionData> */
    public function transactions(string $accountNumber): DataCollection;

    /**
     * The bank's untouched transactions payload, for debugging.
     */
    public function rawTransactions(string $accountNumber): mixed;

    /** @return DataCollection<int, ContactData> */
    public function contacts(): DataCollection;

    /**
     * Prepare a transfer. Nothing leaves the account until the quote is confirmed.
     */
    public function initiateTransfer(TransferRequestData $request): TransferQuoteData;

    /**
     * Execute a prepared transfer, with the OTP when the quote requires one.
     */
    public function confirmTransfer(TransferQuoteData $quote, ?string $otp = null): TransferReceiptData;
}
