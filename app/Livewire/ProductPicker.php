<?php

namespace App\Livewire;

use App\Services\Ticimax\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Manuel Ürün Listele')]
#[Layout('layouts.app')]
class ProductPicker extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    /** Listeleme sonucu: ana mağazadan çekilmiş UrunKarti dizisi */
    public array $products = [];

    /** Seçili VaryasyonID'ler (string olarak — Livewire ile birlikte gönderim) */
    public array $selected = [];

    public bool $selectAll = false;

    /** Yükleme/hata durumu */
    public bool $loading = false;
    public ?string $error = null;
    public int $resultCount = 0;
    public bool $hasSearched = false;

    public function listele(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->products = [];
        $this->selected = [];
        $this->selectAll = false;
        $this->resultCount = 0;
        $this->hasSearched = true;

        $q = trim($this->query);
        if ($q === '') {
            $this->error = 'Lütfen aramak için stok kodu yazın.';
            $this->loading = false;
            return;
        }

        try {
            $ana = ProductService::for('ana');
            $hits = $ana->searchProductsByStokKodu($q);
            $this->products = $this->flatten($hits);
            $this->resultCount = count($this->products);
            if ($this->resultCount === 0) {
                $this->error = 'Aramaya uyan ürün bulunamadı.';
            }
        } catch (\Throwable $e) {
            $this->error = 'Arama hatası: ' . $e->getMessage();
        }
        $this->loading = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selected = array_map(fn($r) => (string) $r['variant_id'], $this->products);
        } else {
            $this->selected = [];
        }
    }

    /**
     * Seçilenleri session'a kaydet, /urunler/aktar sayfasına yönlendir.
     */
    public function aktarimaGec()
    {
        if (empty($this->selected)) {
            $this->error = 'Lütfen aktarılacak ürünleri seçin.';
            return null;
        }
        // Seçili olanları ham UrunKarti payload'larıyla birlikte session'a koy
        $selectedRows = array_filter($this->products, fn($r) => in_array((string) $r['variant_id'], $this->selected, true));
        session()->put('picker.products', array_values($selectedRows));
        return redirect()->route('urunler.aktar');
    }

    /**
     * Ana'dan dönen UrunKarti listesini tabloya uygun düz formata çevirir.
     * Her varyasyon bir satır olur.
     */
    protected function flatten(array $urunKartlari): array
    {
        $rows = [];
        foreach ($urunKartlari as $uk) {
            $variants = $uk['Varyasyonlar']['Varyasyon'] ?? $uk['Varyasyonlar'] ?? [];
            if (isset($variants['Barkod'])) $variants = [$variants];
            if (! is_array($variants) || empty($variants)) continue;
            foreach ($variants as $v) {
                $rows[] = [
                    'urun_karti_id' => (int) ($uk['ID'] ?? 0),
                    'variant_id'    => (int) ($v['ID'] ?? 0),
                    'urun_adi'      => (string) ($uk['UrunAdi'] ?? ''),
                    'stok_kodu'     => (string) ($v['StokKodu'] ?? ''),
                    'barkod'        => (string) ($v['Barkod'] ?? ''),
                    'stok_adedi'    => (int) ($v['StokAdedi'] ?? 0),
                    'satis_fiyati'  => (float) ($v['SatisFiyati'] ?? 0),
                    'indirimli_fiyat' => (float) ($v['IndirimliFiyati'] ?? 0),
                    'aktif'         => (bool) (($v['Aktif'] ?? true) && ($uk['Aktif'] ?? true)),
                    // Ham UrunKarti payload'u — aktarım sayfasına geçer
                    '_raw'          => $uk,
                ];
            }
        }
        return $rows;
    }

    public function render()
    {
        return view('livewire.product-picker');
    }
}
