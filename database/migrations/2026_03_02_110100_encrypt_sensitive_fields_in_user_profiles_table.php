<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_profiles')
            ->select(['id', 'bank_account_number', 'tax_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($row->bank_account_number !== null && ! $this->isEncrypted((string) $row->bank_account_number)) {
                        $updates['bank_account_number'] = Crypt::encryptString((string) $row->bank_account_number);
                    }

                    if ($row->tax_id !== null && ! $this->isEncrypted((string) $row->tax_id)) {
                        $updates['tax_id'] = Crypt::encryptString((string) $row->tax_id);
                    }

                    if ($updates !== []) {
                        DB::table('user_profiles')
                            ->where('id', $row->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible on purpose: do not write plain sensitive values back to DB.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};

