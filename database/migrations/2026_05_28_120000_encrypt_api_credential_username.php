<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * api_credentials.username artık şifreli saklanır (#3).
 *
 * Yeni Ticimax B2B'de username = "Web Servis Yetki Kodu" = asıl GİZLİ anahtar
 * (password çoğu kurulumda boş). Bu yüzden username düz metin durmamalı.
 * Model'e `username => 'encrypted'` cast'i eklendi; bu migration mevcut düz
 * metin değerleri tek seferlik şifreli forma çevirir.
 *
 * Raw DB üzerinden çalışır (model cast'ini baypas eder) — böylece sıra
 * bağımsızdır. Zaten şifreli olan satırlar (decrypt başarılıysa) atlanır,
 * migration idempotent olur.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('api_credentials')->get() as $row) {
            $current = (string) $row->username;
            if ($current === '') {
                continue;
            }

            // Zaten şifreliyse decrypt başarılı olur → dokunma.
            try {
                Crypt::decryptString($current);

                continue;
            } catch (Throwable) {
                // düz metin → şifrele
            }

            DB::table('api_credentials')
                ->where('id', $row->id)
                ->update(['username' => Crypt::encryptString($current)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('api_credentials')->get() as $row) {
            $current = (string) $row->username;
            if ($current === '') {
                continue;
            }
            try {
                $plain = Crypt::decryptString($current);
            } catch (Throwable) {
                continue; // zaten düz metin
            }
            DB::table('api_credentials')
                ->where('id', $row->id)
                ->update(['username' => $plain]);
        }
    }
};
