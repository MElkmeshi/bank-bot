<?php

namespace App\Services\Banks\Contracts;

use App\Data\Bank\VoucherData;
use App\Data\Bank\VoucherDenominationData;
use App\Data\Bank\VoucherProviderData;
use App\Data\Bank\VoucherPurchaseRequestData;
use App\Data\Bank\VoucherQuoteData;
use Spatie\LaravelData\DataCollection;

/**
 * Optional bank capability: selling prepaid (MNO) vouchers.
 */
interface SellsVouchers
{
    /** @return DataCollection<int, VoucherProviderData> */
    public function voucherProviders(): DataCollection;

    /** @return DataCollection<int, VoucherDenominationData> */
    public function voucherDenominations(string $providerId): DataCollection;

    /**
     * Prepare the purchase. Nothing is charged until the quote is confirmed.
     */
    public function purchaseVoucher(VoucherPurchaseRequestData $request): VoucherQuoteData;

    /**
     * Execute a prepared purchase, with the OTP when the quote requires one, and return the voucher.
     */
    public function confirmVoucherPurchase(VoucherQuoteData $quote, ?string $otp = null): VoucherData;
}
