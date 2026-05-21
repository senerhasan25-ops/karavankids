<?php

namespace Tests\Unit;

use App\Livewire\OrderTransfers;
use PHPUnit\Framework\TestCase;

class OrderTransfersHelperTest extends TestCase
{
    public function test_parses_missing_barcodes_from_error_message(): void
    {
        $err = 'Ana eşleşmesi yok: ABC123, DEF456';
        $this->assertSame(['ABC123', 'DEF456'], OrderTransfers::parseMissingBarcodes($err));
    }

    public function test_parses_single_missing_barcode(): void
    {
        $this->assertSame(['XYZ'], OrderTransfers::parseMissingBarcodes('Ana eşleşmesi yok: XYZ'));
    }

    public function test_returns_null_for_unrelated_error(): void
    {
        $this->assertNull(OrderTransfers::parseMissingBarcodes('SOAP fault: connection refused'));
        $this->assertNull(OrderTransfers::parseMissingBarcodes(null));
        $this->assertNull(OrderTransfers::parseMissingBarcodes(''));
    }

    public function test_trims_whitespace_around_barcodes(): void
    {
        $this->assertSame(['A1', 'B2'], OrderTransfers::parseMissingBarcodes('Ana eşleşmesi yok:  A1 ,  B2  '));
    }
}
